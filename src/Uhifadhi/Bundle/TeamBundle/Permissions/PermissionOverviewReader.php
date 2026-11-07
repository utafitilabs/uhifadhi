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

use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionChangeRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;

/**
 * THE HUB'S NUMBERS: the four figures, the four plots, the standing lists in
 * one line each and the whole history, newest first. The findings are the latest
 * run's; positions, people, tiers and placements are read live.
 *
 * @see docs/permissions-readers.md#permissionoverviewreader
 */
final readonly class PermissionOverviewReader
{
    public const int MONTHS = 6;

    public function __construct(
        private PositionRepository $positions,
        private UserRepository $users,
        private PermissionRunRepository $runs,
        private PermissionChangeRepository $changes,
        private ConcernCatalogue $catalogue,
        private PermissionAnswerService $answering,
        private PermissionFindingsReader $findings,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return array{
     *     kpis: array{positions: int, vacant: int, locked: int, people: int, staff: int, above: int, exceptionsInForce: int, notInForce: int,
     *         reachNothing: int, notHonoured: int, needsAttention: int, questionsFiled: int, driftUnreviewed: int},
     *     concernsByPositions: list<array{key: string, label: string, positions: int, share: int}>,
     *     positionsByPairs: list<array{position: string, held: int, problem: int, kinds: list<string>}>,
     *     whoCanActByPlace: list<array{key: string, name: string, people: int}>,
     *     changesByMonth: list<array{key: string, month: string, changes: int}>,
     *     review: list<array{id: string, label: string, alarm: bool, count: int, summary: string}>,
     *     history: list<ChangeLine>,
     *     historyChips: list<array{axis: string, value: string, on: bool, aria: string, sep: int, options: list<array{value: string, label: string, count: int, on: bool}>}>,
     *     freshness: array{asOf: ?\DateTimeImmutable, previousRun: ?\DateTimeImmutable, nextRun: \DateTimeImmutable, pending: bool, running: bool, lastFailure: ?string, questions: int, ms: int},
     * }
     */
    public function overview(): array
    {
        $run = $this->runs->findOneLatestFinished();
        $standing = $this->findings->standing();
        $lists = array_column($standing['lists'], null, 'id');
        $count = static fn (FindingListEnum $l): int => $lists[$l->value]['count'] ?? 0;

        $positions = array_values(array_filter($this->positions->findAllOrdered(), static fn (Position $p): bool => !$p->isRetired()));
        $people = array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive()));
        $above = \count(array_filter($people, static fn (User $u): bool => TeamRoleEnum::Staff !== $u->getTeamRole()));

        return [
            'kpis' => [
                'positions' => \count($positions),
                'vacant' => $count(FindingListEnum::Vacant),
                'locked' => \count(array_filter($positions, static fn (Position $p): bool => $p->isLocked())),
                'people' => \count($people),
                'staff' => \count($people) - $above,
                'above' => $above,
                'exceptionsInForce' => $count(FindingListEnum::Exceptions),
                'notInForce' => $count(FindingListEnum::NotInForce),
                'reachNothing' => $count(FindingListEnum::ReachNothing),
                'notHonoured' => $count(FindingListEnum::NotHonoured),
                'needsAttention' => $count(FindingListEnum::ReachNothing) + $count(FindingListEnum::NotHonoured),
                'questionsFiled' => null === $run ? 0 : \count($run->getPairs()),
                'driftUnreviewed' => $this->findings->drift()['unreviewed'],
            ],
            'concernsByPositions' => $this->concernsByPositions($positions),
            'positionsByPairs' => $this->positionsByPairs($positions, $lists),
            'whoCanActByPlace' => $this->whoCanActByPlace($people),
            'changesByMonth' => $this->changesByMonth(),
            'review' => array_map(fn (array $l): array => [
                'id' => $l['id'], 'label' => $l['label'], 'alarm' => $l['alarm'], 'count' => $l['count'], 'summary' => $this->summary($l['id'], $l['entries']),
            ], $standing['lists']),
            'history' => $history = array_map(static fn (PermissionChange $c): ChangeLine => ChangeLine::of($c), $this->changes->findPage(0, max(1, $this->changes->countAll()))),
            'historyChips' => [
                self::historyChip('kind', 'all', 'All', 'Filter by kind of change', $history, static fn (ChangeLine $l): string => $l->kind, ucfirst(...)),
                self::historyChip('by', 'anyone', 'Anyone', 'Filter by who made the change', $history, static fn (ChangeLine $l): string => $l->by, static fn (string $by): string => $by),
            ],
            'freshness' => $this->findings->freshness(),
        ];
    }

    /**
     * ONE CHIP OF THE HISTORY'S BAR: the lead option for every change, then
     * one option a value the history holds, with how many changes carry it,
     * in the value's order. The page's register controller recounts them as
     * the other chip and the search narrow.
     *
     * @param list<ChangeLine>             $history
     * @param callable(ChangeLine): string $of
     * @param callable(string): string     $label
     *
     * @return array{axis: string, value: string, on: bool, aria: string, sep: int, options: list<array{value: string, label: string, count: int, on: bool}>}
     */
    private static function historyChip(string $axis, string $word, string $all, string $aria, array $history, callable $of, callable $label): array
    {
        $counts = array_count_values(array_map($of, $history));
        ksort($counts, \SORT_NATURAL | \SORT_FLAG_CASE);
        $options = [['value' => '', 'label' => $all, 'count' => \count($history), 'on' => true]];
        foreach ($counts as $value => $n) {
            $options[] = ['value' => (string) $value, 'label' => $label((string) $value), 'count' => $n, 'on' => false];
        }

        return ['axis' => $axis, 'value' => $word.' · '.\count($history), 'on' => false, 'aria' => $aria, 'sep' => 1, 'options' => $options];
    }

    /**
     * @param list<Position> $positions
     *
     * @return list<array{key: string, label: string, positions: int, share: int}>
     */
    private function concernsByPositions(array $positions): array
    {
        $rows = [];
        foreach ($this->catalogue->all() as $concern) {
            $holding = \count(array_filter($positions, static fn (Position $p): bool => [] !== array_filter(
                $p->getGrantValues(), static fn (string $pair): bool => Grant::tryParse($pair)?->concern === $concern->key(),
            )));
            $rows[] = ['key' => $concern->key(), 'label' => $concern->label(), 'positions' => $holding,
                'share' => [] === $positions ? 0 : (int) round($holding * 100 / \count($positions))];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['positions'], $a['label']] <=> [$a['positions'], $b['label']]);

        return $rows;
    }

    /**
     * @param list<Position>                                            $positions
     * @param array<string, array{entries: list<array<string, mixed>>}> $lists
     *
     * @return list<array{position: string, held: int, problem: int, kinds: list<string>}>
     */
    private function positionsByPairs(array $positions, array $lists): array
    {
        $bad = [];
        foreach ([FindingListEnum::NotInForce->value => 'not in force', FindingListEnum::NotHonoured->value => 'not honoured'] as $list => $kind) {
            foreach ($lists[$list]['entries'] ?? [] as $entry) {
                if (\is_int($entry['positionId'] ?? null) && \is_string($entry['pair'] ?? null)) {
                    $bad[$entry['positionId'].'|'.$entry['pair']] = $kind;
                }
            }
        }

        $rows = [];
        foreach ($positions as $position) {
            $kinds = [];
            foreach ($position->getGrantValues() as $pair) {
                if (isset($bad[$position->getId().'|'.$pair])) {
                    $kinds[] = $bad[$position->getId().'|'.$pair];
                }
            }
            $rows[] = ['position' => (string) $position->getName(), 'held' => \count($position->getGrantValues()), 'problem' => \count($kinds), 'kinds' => $kinds];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['held'], $a['position']] <=> [$a['held'], $b['position']]);

        return $rows;
    }

    /**
     * WHO CAN ACT WHERE, from placements: the whole organization is the
     * people placed across it; an area is those and the people placed there.
     *
     * @param list<User> $people
     *
     * @return list<array{key: string, name: string, people: int}>
     */
    private function whoCanActByPlace(array $people): array
    {
        $across = 0;
        $inArea = [];
        foreach ($people as $person) {
            $placement = $person->getPlacement();
            if (null === $placement) {
                continue;
            }
            if ($placement->isWholeOrganization()) {
                ++$across;
                continue;
            }
            foreach ($placement->getAreas() ?? [] as $area) {
                $inArea[(int) $area->getId()] = ($inArea[(int) $area->getId()] ?? 0) + 1;
            }
        }

        return array_map(static fn (array $p): array => [
            'key' => PlaceKey::of($p['kind'], $p['id']),
            'name' => $p['name'],
            'people' => $across + (null === $p['id'] ? 0 : $inArea[$p['id']] ?? 0),
        ], $this->answering->places());
    }

    /** @return list<array{key: string, month: string, changes: int}> */
    private function changesByMonth(): array
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $first = $now->modify('first day of this month')->setTime(0, 0)->modify(\sprintf('-%d months', self::MONTHS - 1));
        $counts = $this->changes->countByMonthSince($first);

        $months = [];
        for ($i = 0; $i < self::MONTHS; ++$i) {
            $month = $first->modify(\sprintf('+%d months', $i));
            $months[] = ['key' => $month->format('Y-m'), 'month' => $month->format('M'), 'changes' => $counts[$month->format('Y-m')] ?? 0];
        }

        return $months;
    }

    /**
     * ONE LINE PER LIST, as the hub's review table prints it.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function summary(string $list, array $entries): string
    {
        $text = static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '';
        $pairOf = static fn (array $e): string => $text($e['position'] ?? '').' · '.$text($e['pairLabel'] ?? '');

        return match (FindingListEnum::from($list)) {
            FindingListEnum::ReachNothing => implode(', ', array_map(static fn (array $e): string => $text($e['name'] ?? ''), $entries)),
            FindingListEnum::NotInForce, FindingListEnum::NotHonoured => implode('; ', array_map($pairOf, $entries)),
            FindingListEnum::Gaining => [] === $entries ? 'nobody below Super Admin can raise themselves'
                : implode('; ', array_map(static fn (array $e): string => $text($e['subject'] ?? '').' · '.$text($e['detail'] ?? ''), $entries)),
            FindingListEnum::Vacant => implode('; ', array_map(static fn (array $e): string => $text($e['position'] ?? '')
                .(($e['since'] ?? null) instanceof \DateTimeImmutable ? ', since '.$e['since']->format('j M Y') : ''), $entries)),
            FindingListEnum::Exceptions => self::byPair($entries),
            FindingListEnum::SelfChanges => [] === $entries ? 'nobody changed their own tier, rank or position'
                : implode('; ', array_map(static fn (array $e): string => \sprintf('%s · %s: %s → %s', $text($e['name'] ?? ''), $text($e['what'] ?? ''),
                    $text($e['before'] ?? '—'), $text($e['after'] ?? '—')), $entries)),
        };
    }

    /** @param list<array<string, mixed>> $entries */
    private static function byPair(array $entries): string
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $label = \is_string($entry['pairLabel'] ?? null) ? $entry['pairLabel'] : '';
            $grouped[$label][] = \is_string($entry['position'] ?? null) ? $entry['position'] : '';
        }

        return implode(' · ', array_map(static fn (string $label, array $positions): string => $label.' — '.implode(', ', $positions), array_keys($grouped), $grouped));
    }
}
