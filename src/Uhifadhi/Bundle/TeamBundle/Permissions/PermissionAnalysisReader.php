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
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE ANALYSIS PAGE'S READING OF THE LATEST FINISHED RUN, one question at a
 * time: for a pair, places across and positions down with a number of
 * people in each cell; for a power, the actors by target kind. Nothing here
 * asks a voter — the run asked, and this reads what it filed.
 *
 * @see docs/permissions-readers.md#permissionanalysisreader
 */
final readonly class PermissionAnalysisReader
{
    public function __construct(
        private PermissionRunRepository $runs,
        private PermissionAnswerRepository $answers,
        private PositionRepository $positions,
        private UserRepository $users,
        private ConcernCatalogue $catalogue,
        private PowerCatalogue $powers,
        private DepartmentRepository $departments,
    ) {
    }

    /**
     * @return array{
     *     pair: string, label: string, filed: bool, asOf: ?\DateTimeImmutable,
     *     places: list<array{key: string, kind: string, id: int|null, name: string}>,
     *     rows: list<array{positionId: int, position: string, holders: int, counts: array<string, int>, anywhere: int, people: int}>,
     *     totals: array<string, int>,
     *     tiers: list<array{key: string, label: string, count: int}>,
     *     departments: list<array{id: int, name: string}>,
     * }
     */
    public function forPair(string $pair): array
    {
        $run = $this->runs->findOneLatestFinished();
        $empty = ['pair' => $pair, 'label' => $this->pairLabel($pair), 'filed' => false, 'asOf' => null, 'places' => [], 'rows' => [], 'totals' => [], 'tiers' => [], 'departments' => []];
        if (null === $run) {
            return $empty;
        }

        $places = PlaceKey::places($run->getPlaces());
        $zero = array_fill_keys(array_column($places, 'key'), 0);
        $byPosition = [];
        $people = [];
        $names = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $names[(int) $position->getId()] = (string) $position->getName();
            if (!$position->isRetired() && \in_array($pair, $position->getGrantValues(), true)) {
                $byPosition[(int) $position->getId()] = $zero;
                $people[(int) $position->getId()] = [];
            }
        }
        foreach ($this->answers->findByRunAndQuestion($run, QuestionKindEnum::Pair, $pair) as $answer) {
            $position = $answer->getPositionId();
            if (null === $position) {
                continue;
            }
            $byPosition[$position] ??= $zero;
            $byPosition[$position][PlaceKey::of($answer->getPlaceKind() ?? PlaceKindEnum::Organization, $answer->getPlaceId())] = $answer->getPeopleCount();
            $people[$position] = [...$people[$position] ?? [], ...$answer->getPeople()];
        }

        $holders = $this->staffHolders();
        $rows = [];
        foreach ($byPosition as $position => $counts) {
            $rows[] = [
                'positionId' => $position,
                'position' => $names[$position] ?? 'a position',
                'holders' => $holders[$position] ?? 0,
                'counts' => $counts,
                'anywhere' => max([0, ...array_values($counts)]),
                'people' => \count(array_unique($people[$position] ?? [])),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['people'], $a['position']] <=> [$a['people'], $b['position']]);

        $totals = $zero;
        foreach ($rows as $row) {
            foreach ($row['counts'] as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return [
            ...$empty,
            'filed' => \in_array($pair, $run->getPairs(), true),
            'asOf' => $run->getStartedAt(),
            'places' => $places,
            'rows' => $rows,
            'totals' => $totals,
            'tiers' => self::tiers($run),
            'departments' => array_map(static fn (Department $d): array => ['id' => (int) $d->getId(), 'name' => (string) $d->getName()], $this->departments->findAllOrdered()),
        ];
    }

    /**
     * THE PEOPLE BEHIND ONE CELL, from the ids the run stored, never by
     * asking again.
     *
     * @return list<array{id: int, uuid: string, name: string}>
     */
    public function peopleIn(string $pair, string $placeKey, int $positionId): array
    {
        $run = $this->runs->findOneLatestFinished();
        $place = PlaceKey::parse($placeKey);
        if (null === $run || null === $place) {
            return [];
        }
        $cell = $this->answers->findOneCell($run, $pair, $place[0], $place[1], $positionId);
        if (null === $cell || [] === $cell->getPeople()) {
            return [];
        }

        $people = array_map(static fn (User $u): array => [
            'id' => (int) $u->getId(),
            'uuid' => (string) $u->getUuidString(),
            'name' => self::nameOf($u),
        ], $this->users->findBy(['id' => $cell->getPeople()]));
        usort($people, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $people;
    }

    /**
     * THE PEOPLE BEHIND EVERY CELL OF ONE PAIR, read in one go from the ids
     * the run stored: place key, then position id, then the people by name.
     * A cell nobody passes is not stored, so it is absent. Each person
     * carries the departments they serve, their own first, then those they
     * support, so a page can recount a cell to one department.
     *
     * @return array<string, array<int, list<array{id: int, uuid: string, name: string, departments: list<int>}>>>
     */
    public function peopleByCell(string $pair): array
    {
        $run = $this->runs->findOneLatestFinished();
        if (null === $run) {
            return [];
        }
        $answers = array_filter(
            $this->answers->findByRunAndQuestion($run, QuestionKindEnum::Pair, $pair),
            static fn (PermissionAnswer $a): bool => null !== $a->getPositionId() && [] !== $a->getPeople(),
        );
        $ids = array_values(array_unique(array_merge(...array_map(static fn (PermissionAnswer $a): array => $a->getPeople(), array_values($answers)))));
        $people = [];
        foreach ([] === $ids ? [] : $this->users->findBy(['id' => $ids]) as $person) {
            $placement = $person->getPlacement();
            $own = $placement?->getDepartment();
            $people[(int) $person->getId()] = [
                'id' => (int) $person->getId(), 'uuid' => (string) $person->getUuidString(), 'name' => self::nameOf($person),
                'departments' => null === $own ? [] : [(int) $own->getId(), ...array_map(static fn (Department $d): int => (int) $d->getId(), $placement->getSupports())],
            ];
        }

        $cells = [];
        foreach ($answers as $answer) {
            $names = array_values(array_filter(array_map(static fn (int $id): ?array => $people[$id] ?? null, $answer->getPeople())));
            usort($names, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
            $cells[PlaceKey::of($answer->getPlaceKind() ?? PlaceKindEnum::Organization, $answer->getPlaceId())][(int) $answer->getPositionId()] = $names;
        }

        return $cells;
    }

    /**
     * @return array{
     *     power: array{key: string, label: string, group: string, groupLabel: string},
     *     targets: list<array{key: string, label: string}>,
     *     rows: list<array{kind: string, name: string, positionId: int|null, tier: string|null, cells: array<string, string>, refusedBy: array<string, string|null>}>,
     *     alarm: bool,
     *     asOf: ?\DateTimeImmutable,
     * }
     */
    public function forPower(string $powerKey): array
    {
        $power = $this->power($powerKey) ?? throw new \InvalidArgumentException(\sprintf('No power is declared as "%s".', $powerKey));
        $run = $this->runs->findOneLatestFinished();

        $rows = [];
        foreach (null === $run ? [] : $this->answers->findByRunAndQuestion($run, QuestionKindEnum::Power, $powerKey) as $answer) {
            $tier = $answer->getTier();
            $key = null === $tier ? 'position:'.$answer->getPositionId() : 'tier:'.$tier;
            $rows[$key] ??= ['tier' => $tier, 'positionId' => $answer->getPositionId(), 'cells' => [], 'refusedBy' => []];
            $rows[$key]['cells'][(string) $answer->getTarget()] = (string) $answer->getVerdict();
            $rows[$key]['refusedBy'][(string) $answer->getTarget()] = $answer->getRefusedBy();
        }

        $ordered = [];
        foreach ([TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin] as $tier) {
            if (isset($rows['tier:'.$tier->value])) {
                $ordered[] = ['kind' => 'tier', 'name' => $tier->label(), ...$rows['tier:'.$tier->value]];
            }
        }
        foreach ($this->positions->findAllOrdered() as $position) {
            if (isset($rows['position:'.$position->getId()])) {
                $ordered[] = ['kind' => 'position', 'name' => (string) $position->getName(), ...$rows['position:'.$position->getId()]];
            }
        }

        $alarm = PowerGroup::GainingPower === $power->group
            && [] !== array_filter($ordered, static fn (array $r): bool => TeamRoleEnum::SuperAdmin->value !== $r['tier'] && \in_array(CellAnswer::Allowed->value, $r['cells'], true));

        return [
            'power' => ['key' => $power->key, 'label' => $power->label, 'group' => $power->group->value, 'groupLabel' => $power->group->label()],
            'targets' => array_map(static fn ($t): array => ['key' => $t->value, 'label' => $t->label()], $power->targets),
            'rows' => array_map(static fn (array $r): array => [
                'kind' => $r['kind'], 'name' => $r['name'], 'positionId' => $r['positionId'], 'tier' => $r['tier'],
                'cells' => $r['cells'], 'refusedBy' => $r['refusedBy'],
            ], $ordered),
            'alarm' => $alarm,
            'asOf' => $run?->getStartedAt(),
        ];
    }

    /**
     * WHAT THE QUESTION DROPDOWN OFFERS: every concern with its verbs, each
     * verb a pair, and every power by group — each with how many answers the
     * latest run filed for it. A pair also says how many positions hold it,
     * as written; a power, how many actors the run allowed over its first
     * target kind, and that kind's label.
     *
     * @return array{
     *     asOf: ?\DateTimeImmutable,
     *     concerns: list<array{key: string, label: string, group: string, verbs: list<array{pair: string, verb: string, label: string, answers: int, positions: int}>}>,
     *     powers: list<array{key: string, label: string, group: string, groupLabel: string, answers: int, allowed: int, target: string}>,
     * }
     */
    public function questions(): array
    {
        $run = $this->runs->findOneLatestFinished();
        $pairCounts = null === $run ? [] : $this->answers->countByRunAndKind($run, QuestionKindEnum::Pair);
        $powerCounts = null === $run ? [] : $this->answers->countByRunAndKind($run, QuestionKindEnum::Power);

        $holding = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            if (!$position->isRetired()) {
                foreach (array_unique($position->getGrantValues()) as $pair) {
                    $holding[$pair] = ($holding[$pair] ?? 0) + 1;
                }
            }
        }

        $concerns = [];
        foreach ($this->catalogue->grouped() as $group => $declared) {
            foreach ($declared as $concern) {
                $verbs = [];
                foreach (Verb::cases() as $verb) {
                    if ($concern->supports($verb)) {
                        $pair = (string) Grant::of($concern->key(), $verb);
                        $verbs[] = ['pair' => $pair, 'verb' => $verb->value, 'label' => $verb->label(), 'answers' => $pairCounts[$pair] ?? 0, 'positions' => $holding[$pair] ?? 0];
                    }
                }
                $concerns[] = ['key' => $concern->key(), 'label' => $concern->label(), 'group' => $group, 'verbs' => $verbs];
            }
        }

        return [
            'asOf' => $run?->getStartedAt(),
            'concerns' => $concerns,
            'powers' => array_map(fn (Power $p): array => [
                'key' => $p->key, 'label' => $p->label, 'group' => $p->group->value, 'groupLabel' => $p->group->label(), 'answers' => $powerCounts[$p->key] ?? 0,
                'allowed' => null === $run ? 0 : $this->allowedOverFirstTarget($run, $p),
                'target' => [] === $p->targets ? '' : $p->targets[0]->label(),
            ], $this->powers->all()),
        ];
    }

    /** `Surveys · record`, or the pair itself when nothing declares it. */
    public function pairLabel(string $pair): string
    {
        $grant = Grant::tryParse($pair);
        $concern = null === $grant ? null : $this->catalogue->concern($grant->concern);

        return null === $grant || null === $concern ? $pair : $concern->label().' · '.$grant->verb->value;
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public static function tiers(PermissionRun $run): array
    {
        return [
            ['key' => TeamRoleEnum::SuperAdmin->value, 'label' => TeamRoleEnum::SuperAdmin->label(), 'count' => $run->getCount('superAdmins')],
            ['key' => TeamRoleEnum::Admin->value, 'label' => TeamRoleEnum::Admin->label(), 'count' => $run->getCount('admins')],
        ];
    }

    /** The actors the run allowed over the power's first target kind. */
    private function allowedOverFirstTarget(PermissionRun $run, Power $power): int
    {
        if ([] === $power->targets) {
            return 0;
        }
        $first = $power->targets[0]->value;
        $allowed = array_filter(
            $this->answers->findByRunAndQuestion($run, QuestionKindEnum::Power, $power->key),
            static fn (PermissionAnswer $a): bool => $first === $a->getTarget() && CellAnswer::Allowed->value === $a->getVerdict(),
        );

        return \count($allowed);
    }

    private function power(string $key): ?Power
    {
        foreach ($this->powers->all() as $power) {
            if ($key === $power->key) {
                return $power;
            }
        }

        return null;
    }

    /**
     * ONE ACTIVE STAFF HOLDER OF EACH POSITION, the first by name: the
     * person a drift line's live check asks about.
     *
     * @return array<int, int> position id to a person id
     */
    public function firstHolders(): array
    {
        $first = [];
        foreach ($this->users->findAllByName() as $person) {
            $position = $person->getPosition()?->getId();
            if (null !== $position && $person->isActive() && TeamRoleEnum::Staff === $person->getTeamRole()) {
                $first[$position] ??= (int) $person->getId();
            }
        }

        return $first;
    }

    /**
     * THE RECORDS A ROW OPENS: every position's and every person's uuid by
     * id, and the first active person of each tier by name, the person a
     * tier's row in a power's table checks.
     *
     * @return array{positions: array<int, string>, people: array<int, string>, tiers: array<string, int>}
     */
    public function records(): array
    {
        $positions = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $positions[(int) $position->getId()] = (string) $position->getUuidString();
        }
        $people = [];
        $tiers = [];
        foreach ($this->users->findAllByName() as $person) {
            $people[(int) $person->getId()] = (string) $person->getUuidString();
            if ($person->isActive()) {
                $tiers[$person->getTeamRole()->value] ??= (int) $person->getId();
            }
        }

        return ['positions' => $positions, 'people' => $people, 'tiers' => $tiers];
    }

    /** @return array<int, int> position id to its active Staff holders */
    private function staffHolders(): array
    {
        $holders = [];
        foreach ($this->users->findAllByName() as $person) {
            $position = $person->getPosition()?->getId();
            if (null !== $position && $person->isActive() && TeamRoleEnum::Staff === $person->getTeamRole()) {
                $holders[$position] = ($holders[$position] ?? 0) + 1;
            }
        }

        return $holders;
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
