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

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * WHAT HOLDING EACH POSITION GRANTS, read from the positions as they are
 * stored — no voter and no run.
 *
 * GROUPS ARE THE DECLARING PACKAGE. A position's pairs are grouped under the
 * package that declared the concern (Team, Ground, each module), in the
 * catalogue's order, and the group is labelled with that package's name.
 *
 * @phpstan-type PositionRow array{
 *     id: int, uuid: string, name: string, holders: int, seatCount: int|null,
 *     allowedKinds: list<string>, where: string, vacantSince: ?\DateTimeImmutable, locked: bool, retired: bool,
 *     pairs: list<string>,
 *     groups: list<array{group: string, concerns: list<array{key: string, label: string, sensitive: bool, lifts: string|null, verbs: list<string>}>, line: string}>,
 *     exceptions: list<array{pair: string, lifts: string, inForce: bool, reason: string|null, by: string|null, at: ?\DateTimeImmutable}>,
 *     notHonoured: list<string>,
 *     marks: array{locked: bool, vacant: bool, exceptionNotInForce: bool, notHonoured: bool},
 * }
 *
 * @see docs/permissions-readers.md#positionsreportreader
 */
final readonly class PositionsReportReader
{
    public function __construct(
        private PositionRepository $positions,
        private UserRepository $users,
        private GrantJustificationRepository $justifications,
        private ConcernCatalogue $catalogue,
    ) {
    }

    /**
     * @return array{
     *     positions: list<PositionRow>,
     *     tiers: list<array{key: string, label: string, people: list<array{id: int, uuid: string, name: string, placed: string}>}>,
     *     tierOnlyPairs: list<string>,
     *     groups: list<string>,
     *     totals: array{all: int, vacant: int, exception: int, locked: int, honoured: int, organization: int, area: int},
     * }
     */
    public function report(): array
    {
        $active = array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive()));
        $holders = [];
        foreach ($active as $person) {
            $id = $person->getPosition()?->getId();
            if (null !== $id) {
                $holders[$id] = ($holders[$id] ?? 0) + 1;
            }
        }

        $pairs = $this->catalogue->pairs();
        $rows = array_map(fn (Position $p): array => $this->row($p, $holders[(int) $p->getId()] ?? 0, $pairs), $this->positions->findAllOrdered());

        $totals = ['all' => 0, 'vacant' => 0, 'exception' => 0, 'locked' => 0, 'honoured' => 0, 'organization' => 0, 'area' => 0];
        foreach ($rows as $row) {
            if ($row['retired']) {
                continue;
            }
            ++$totals['all'];
            $totals['vacant'] += (int) $row['marks']['vacant'];
            $totals['exception'] += (int) ([] !== $row['exceptions']);
            $totals['locked'] += (int) $row['locked'];
            $totals['honoured'] += (int) $row['marks']['notHonoured'];
            $totals['organization'] += (int) \in_array(ScopeKind::Organization->value, $row['allowedKinds'], true);
            $totals['area'] += (int) \in_array(ScopeKind::Area->value, $row['allowedKinds'], true);
        }

        $tiers = [];
        foreach ([TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin] as $tier) {
            $tiers[] = [
                'key' => $tier->value,
                'label' => $tier->label(),
                'people' => array_values(array_map(static fn (User $u): array => ['id' => (int) $u->getId(), 'uuid' => (string) $u->getUuidString(), 'name' => self::nameOf($u), 'placed' => self::placed($u)],
                    array_filter($active, static fn (User $u): bool => $tier === $u->getTeamRole()))),
            ];
        }

        return [
            'positions' => $rows,
            'tiers' => $tiers,
            'tierOnlyPairs' => array_values(array_filter($pairs, fn (string $pair): bool => $this->catalogue->isTierOnly($pair))),
            'groups' => array_keys($this->catalogue->grouped()),
            'totals' => $totals,
        ];
    }

    /**
     * @param list<string> $catalogue every pair on offer, in the catalogue's order
     *
     * @return PositionRow
     */
    private function row(Position $position, int $holders, array $catalogue): array
    {
        $stored = $position->getGrantValues();
        $pairs = array_values(array_filter($catalogue, static fn (string $pair): bool => \in_array($pair, $stored, true)));

        $groups = [];
        $exceptions = [];
        $notHonoured = [];
        foreach ($this->catalogue->grouped() as $declarer => $concerns) {
            $held = [];
            foreach ($concerns as $concern) {
                $verbs = array_values(array_filter(Verb::cases(), static fn (Verb $v): bool => \in_array((string) Grant::of($concern->key(), $v), $pairs, true)));
                if ([] === $verbs) {
                    continue;
                }
                $held[] = ['key' => $concern->key(), 'label' => $concern->label(), 'sensitive' => $concern->isSensitive(), 'lifts' => $concern->lifts(),
                    'verbs' => array_map(static fn (Verb $v): string => $v->value, $verbs)];
                foreach ($verbs as $verb) {
                    $pair = (string) Grant::of($concern->key(), $verb);
                    if (null !== $concern->lifts()) {
                        $justification = $this->justifications->findOneCurrent($position, $pair);
                        $exceptions[] = ['pair' => $pair, 'lifts' => $concern->lifts(), 'inForce' => null !== $justification, 'reason' => $justification?->getReason(),
                            'by' => $justification?->getGrantedByName(), 'at' => $justification?->getGrantedAt()];
                    }
                    if ($concern->isTierOnly($verb)) {
                        $notHonoured[] = $pair;
                    }
                }
            }
            if ([] !== $held) {
                $groups[] = [
                    'group' => $declarer,
                    'concerns' => $held,
                    'line' => implode(' · ', array_map(static fn (array $c): string => $c['label'].' — '.implode(', ', $c['verbs']), $held)),
                ];
            }
        }

        $kinds = array_map(static fn (ScopeKind $k): string => $k->value, $position->getAllowedKinds());
        $org = \in_array(ScopeKind::Organization->value, $kinds, true);
        $area = \in_array(ScopeKind::Area->value, $kinds, true);
        $vacant = 0 === $holders || null !== $position->getVacantSince();

        return [
            'id' => (int) $position->getId(),
            'uuid' => (string) $position->getUuidString(),
            'name' => (string) $position->getName(),
            'holders' => $holders,
            'seatCount' => $position->getSeatCount(),
            'allowedKinds' => $kinds,
            'where' => $org && $area ? 'organization-wide or in named areas' : ($org ? 'organization-wide' : 'in named areas'),
            'vacantSince' => $position->getVacantSince(),
            'locked' => $position->isLocked(),
            'retired' => $position->isRetired(),
            'pairs' => $pairs,
            'groups' => $groups,
            'exceptions' => $exceptions,
            'notHonoured' => $notHonoured,
            'marks' => [
                'locked' => $position->isLocked(),
                'vacant' => $vacant,
                'exceptionNotInForce' => [] !== array_filter($exceptions, static fn (array $e): bool => !$e['inForce']),
                'notHonoured' => [] !== $notHonoured,
            ],
        ];
    }

    /**
     * WHERE A PERSON ABOVE THE MATRIX IS PLACED: the whole organization, the
     * areas named, or — placed nowhere — everywhere, since the tier answers
     * before any placement is read.
     */
    private static function placed(User $person): string
    {
        $placement = $person->getPlacement();
        if (null !== $placement && $placement->isWholeOrganization()) {
            return 'the whole organization';
        }
        $areas = array_map(static fn (AreaInterface $a): string => (string) $a->getName(), $placement?->getAreas() ?? []);

        return [] === $areas ? 'everywhere, by tier' : implode(' + ', $areas);
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
