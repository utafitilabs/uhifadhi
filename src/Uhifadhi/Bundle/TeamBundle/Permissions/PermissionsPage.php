<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Model\Facet;
use Uhifadhi\Bundle\TeamBundle\Model\FacetGroup;
use Uhifadhi\Bundle\TeamBundle\Model\FilterOption;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * WHAT THE PERMISSIONS PAGE DRAWS, from the evaluator's cells: the band of the
 * tiers × the main powers (and a picked person's column beside them), the
 * person's card, and the ledger's facets and rows.
 */
final readonly class PermissionsPage
{
    /** The main powers the band reads, in its order; the ledger has every one. */
    public const array BAND = [
        'raise-their-own-tier', 'seat-themselves-in-a-stronger-position',
        'make-admin', 'make-super-admin', 'add-a-pair-to-a-position', 'give-live-locations',
        'change-another-rangers-check-in',
        'send-a-reset-link', 'change-their-email', 'issue-a-one-time-password', 'switch-user',
    ];

    public function __construct(
        private PermissionEvaluator $evaluator,
        private PowerCatalogue $powers,
        private UserRepository $users,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(LedgerQuery $query): array
    {
        $person = null === $query->person || !Uuid::isValid($query->person) ? null : $this->users->findOneByUuid(Uuid::fromString($query->person));
        $actors = $this->evaluator->actors();
        $ledger = $this->evaluator->ledger();
        $mine = null === $person ? [] : $this->evaluator->forPerson($person);
        $base = null === $person ? $ledger : $mine;

        $matching = array_values(array_filter($base, $query->matches(...)));
        $pages = max(1, (int) ceil(\count($matching) / LedgerQuery::PER_PAGE));
        $page = min($query->page, $pages);

        return [
            'query' => $query,
            'person' => $person,
            'people' => array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive())),
            'band' => $this->band($ledger, $actors, $mine),
            'card' => null === $person ? null : $this->card($person, $mine),
            'facets' => $this->facets($base, $actors),
            'rows' => \array_slice($matching, ($page - 1) * LedgerQuery::PER_PAGE, LedgerQuery::PER_PAGE),
            'matching' => \count($matching),
            'total' => \count($base),
            'page' => $page,
            'pages' => $pages,
            'groups' => PowerGroup::cases(),
        ];
    }

    /**
     * @param list<Cell> $ledger
     * @param list<User> $actors
     * @param list<Cell> $mine
     *
     * @return list<array{group: PowerGroup, power: Power, staff: Mark, admin: Mark, superAdmin: Mark, person: ?Mark}>
     */
    private function band(array $ledger, array $actors, array $mine): array
    {
        $byKey = [];
        foreach ($this->powers->all() as $power) {
            $byKey[$power->key] = $power;
        }
        $staff = array_filter($actors, static fn (User $u): bool => TeamRoleEnum::Staff === $u->getTeamRole());
        $tier = static fn (TeamRoleEnum $t): ?User => array_values(array_filter($actors, static fn (User $u): bool => $t === $u->getTeamRole()))[0] ?? null;

        $rows = [];
        foreach (self::BAND as $key) {
            $power = $byKey[$key] ?? null;
            if (null === $power) {
                continue;
            }
            $cellsOf = static fn (?User $actor): array => null === $actor ? [] : array_values(array_filter($ledger, static fn (Cell $c): bool => $c->power->key === $key && $c->actor->getId() === $actor->getId()));
            $rows[] = [
                'group' => $power->group,
                'power' => $power,
                'staff' => self::across(array_map(static fn (User $u): Mark => self::mark($cellsOf($u)), array_values($staff))),
                'admin' => self::mark($cellsOf($tier(TeamRoleEnum::Admin))),
                'superAdmin' => self::mark($cellsOf($tier(TeamRoleEnum::SuperAdmin))),
                'person' => [] === $mine ? null : self::mark(array_values(array_filter($mine, static fn (Cell $c): bool => $c->power->key === $key))),
            ];
        }

        return $rows;
    }

    /**
     * ONE ACTOR'S ANSWER TO ONE POWER, over its targets: yes to all, never,
     * or yes within a limit named by what was refused.
     *
     * @param list<Cell> $cells
     */
    private static function mark(array $cells): Mark
    {
        $allowed = $refused = [];
        foreach ($cells as $cell) {
            match ($cell->answer) {
                CellAnswer::Allowed => $allowed[] = $cell->kind,
                CellAnswer::Refused => $refused[] = $cell->kind,
                CellAnswer::NoTarget => null,
            };
        }

        return match (true) {
            [] === $allowed && [] === $refused => new Mark(Mark::NONE, 'nobody to ask'),
            [] === $refused => new Mark(Mark::YES, 'yes'),
            [] === $allowed => new Mark(Mark::NEVER, 'never'),
            default => new Mark(Mark::PART, self::limit($allowed, $refused)),
        };
    }

    /**
     * @param list<PowerTarget> $allowed
     * @param list<PowerTarget> $refused
     */
    private static function limit(array $allowed, array $refused): string
    {
        $tiers = [PowerTarget::AnAdmin, PowerTarget::ASuperAdmin];

        return match (true) {
            \in_array(PowerTarget::SomebodyBeyondTheirArea, $refused, true) && self::only($allowed, [PowerTarget::AColleague, PowerTarget::Themselves]) => 'own area',
            self::only($refused, [PowerTarget::ASuperAdmin]) => 'not a Super Admin',
            self::only($refused, $tiers) => 'Staff only',
            \in_array(PowerTarget::Themselves, $refused, true) && !\in_array(PowerTarget::Themselves, $allowed, true) => 'not themselves',
            default => 'not '.implode(', ', array_values(array_unique(array_map(static fn (PowerTarget $k): string => $k->label(), $refused)))),
        };
    }

    /**
     * Whether every one of these kinds is among those.
     *
     * @param list<PowerTarget> $kinds
     * @param list<PowerTarget> $within
     */
    private static function only(array $kinds, array $within): bool
    {
        foreach ($kinds as $kind) {
            if (!\in_array($kind, $within, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * THE STAFF COLUMN, across one holder of each position: yes or never when
     * they agree, and otherwise "by position" — the ledger names which.
     *
     * @param list<Mark> $marks
     */
    private static function across(array $marks): Mark
    {
        $kinds = array_values(array_unique(array_map(static fn (Mark $m): string => $m->kind, array_filter($marks, static fn (Mark $m): bool => Mark::NONE !== $m->kind))));
        if ([] === $kinds) {
            return new Mark(Mark::NONE, 'nobody to ask');
        }
        if (1 === \count($kinds) && Mark::PART !== $kinds[0]) {
            return new Mark($kinds[0], Mark::YES === $kinds[0] ? 'yes' : 'never');
        }
        $texts = array_values(array_unique(array_map(static fn (Mark $m): string => $m->text, array_filter($marks, static fn (Mark $m): bool => Mark::PART === $m->kind))));

        return new Mark(Mark::PART, 1 === \count($texts) ? $texts[0].' · by position' : 'by position');
    }

    /**
     * @param list<Cell> $mine
     *
     * @return array{allowed: list<array{power: Power, mark: Mark}>, count: int, total: int, actingOn: list<array{actor: User, powers: list<string>}>}
     */
    private function card(User $person, array $mine): array
    {
        $allowed = [];
        foreach ($this->powers->all() as $power) {
            $mark = self::mark(array_values(array_filter($mine, static fn (Cell $c): bool => $c->power->key === $power->key)));
            if (\in_array($mark->kind, [Mark::YES, Mark::PART], true)) {
                $allowed[] = ['power' => $power, 'mark' => $mark];
            }
        }

        $acting = [];
        foreach ($this->evaluator->actingOn($person) as $cell) {
            $id = (int) $cell->actor->getId();
            $acting[$id] ??= ['actor' => $cell->actor, 'powers' => []];
            $acting[$id]['powers'][] = $cell->power->label;
        }

        return ['allowed' => $allowed, 'count' => \count($allowed), 'total' => \count($this->powers->all()), 'actingOn' => array_values($acting)];
    }

    /**
     * @param list<Cell> $cells
     * @param list<User> $actors
     *
     * @return list<Facet>
     */
    private function facets(array $cells, array $actors): array
    {
        $count = static fn (callable $test): int => \count(array_filter($cells, $test));
        $powers = $this->powers->all();

        return [
            new Facet('group', 'group', 'All groups', \count($cells), [new FacetGroup(null, array_map(
                static fn (PowerGroup $g): FilterOption => new FilterOption($g->value, $g->label(), $count(static fn (Cell $c): bool => $c->power->group === $g)),
                PowerGroup::cases(),
            ))]),
            new Facet('action', 'action', 'Every action', \count($cells), [new FacetGroup(null, array_map(
                static fn (Power $p): FilterOption => new FilterOption($p->key, $p->label, $count(static fn (Cell $c): bool => $c->power->key === $p->key)),
                $powers,
            ))]),
            new Facet('actor', 'who acts', 'Everybody', \count($cells), [new FacetGroup(null, array_map(
                static fn (User $u): FilterOption => new FilterOption((string) $u->getUuidString(), $u->getFullName(), $count(static fn (Cell $c): bool => $c->actor->getId() === $u->getId())),
                $actors,
            ))]),
            new Facet('target', 'on whom', 'Anybody', \count($cells), [new FacetGroup(null, array_map(
                static fn (PowerTarget $k): FilterOption => new FilterOption($k->value, $k->label(), $count(static fn (Cell $c): bool => $c->kind === $k)),
                PowerTarget::cases(),
            ))]),
            new Facet('answer', 'answer', 'Every answer', \count($cells), [new FacetGroup(null, array_map(
                static fn (CellAnswer $a): FilterOption => new FilterOption($a->value, match ($a) {
                    CellAnswer::Allowed => 'Allowed',
                    CellAnswer::Refused => 'Refused',
                    CellAnswer::NoTarget => 'Nobody to ask',
                }, $count(static fn (Cell $c): bool => $c->answer === $a)),
                CellAnswer::cases(),
            ))], opensLeft: true),
        ];
    }
}
