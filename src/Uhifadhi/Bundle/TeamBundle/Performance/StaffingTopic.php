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

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentMark;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PositionVacancy;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\MovementTone;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicDecision;
use Uhifadhi\Contracts\Performance\TopicDecisionsInterface;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Contracts\Performance\TopicMovement;
use Uhifadhi\Contracts\Performance\TopicMovementInterface;

/**
 * SEATS AND PEOPLE — the host's own topic, and the reason the host has
 * topics at all.
 *
 * EVERY DEPARTMENT IS A ROW HERE whatever it attaches: how many posts it
 * holds and how many of them somebody stands in is true of a department
 * that reads no module at all. That is what makes this comparable, and
 * what made the old board's module columns incomparable.
 *
 * IT PUBLISHES THROUGH THE SAME SEAM A MODULE DOES, deliberately. One
 * renderer draws the host's topics and the modules' alike, so the host's
 * cannot quietly acquire an ability a module's lacks — and a module
 * author reading this file is reading a worked example of the contract.
 *
 * THE MOVEMENT COMES OUT OF THE HISTORY, never out of a second count:
 * "↑ 3 on July" is a comparison against a period that has closed, and a
 * closed period cannot be recomputed. Where nothing was written down the
 * figures still draw and their deltas say there is no history yet.
 */
final readonly class StaffingTopic implements PerformanceTopicProviderInterface, TopicDecisionsInterface, TopicMovementInterface
{
    public const string KEY = 'staffing';

    /** What the sparkline is drawn over, and the run the charts use. */
    private const int PERIODS = 6;

    /**
     * HOW LONG A POST MAY STAND EMPTY BEFORE THE PAGE SAYS SO.
     *
     * The design's own default. It becomes an installation setting on the
     * performance configure page; until that page exists, this is the one
     * place it is written.
     */
    public const int THRESHOLD_DAYS = 60;

    public function __construct(
        private DepartmentRepository $departments,
        private StaffingFigures $staffing,
        private PerformanceHistory $history,
        private DepartmentMembership $membership,
        private UserRepository $users,
        private PositionVacancy $vacancy,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::HOST;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Staffing';
    }

    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $departments = $this->departmentsIn($scope);

        $now = [];
        foreach ($departments as $department) {
            foreach ($this->staffing->of($department) as $key => $value) {
                $now[$key] = ($now[$key] ?? 0.0) + $value;
            }
        }

        $filled = $now[StaffingFigures::FILLED] ?? 0.0;
        $seats = $now[StaffingFigures::POSITIONS] ?? 0.0;

        /*
         * FOUR, IN THE DESIGN'S ORDER: the establishment, how much of it
         * is filled, how much is not, and how much has been not for too
         * long. It reads left to right as one sentence about the same
         * set of posts.
         *
         * PEOPLE IS NOT ONE OF THEM. A position is one post held by one
         * person, so the count of people in positions and the count of
         * filled positions are the same number with the same movement —
         * two cards saying one thing, and the one that names the POST is
         * the one the other three are about.
         */
        return [
            $this->figure(StaffingFigures::POSITIONS, 'Positions', $seats, $departments, $period, ColumnPolarity::None,
                \sprintf('across %d %s', \count($departments), 1 === \count($departments) ? 'department' : 'departments')),
            $this->figure(StaffingFigures::FILLED, 'Filled', $filled, $departments, $period, ColumnPolarity::Up,
                \sprintf('of %s', self::plainly($seats))),
            $this->figure(StaffingFigures::VACANT, 'Vacant', $now[StaffingFigures::VACANT] ?? 0.0, $departments, $period, ColumnPolarity::Down),
            /*
             * AND HOW MANY POSTS HAVE STOOD EMPTY TOO LONG — counted from
             * the day each fell vacant, which is written when it falls
             * because it cannot be recovered afterwards.
             *
             * A POST WHOSE DAY NOBODY WROTE DOWN IS NOT COUNTED and the
             * caption says how many those are: it may well be the oldest
             * vacancy in the organization, and counting it either way
             * would be a guess.
             */
            $this->vacancies($departments, $period),
        ];
    }

    /**
     * WHAT SOMEBODY HAS TO DECIDE ABOUT THE ESTABLISHMENT: a post that
     * has stood empty past the threshold.
     *
     * THE ASK IS "FILL IT OR CLOSE IT", and that is the whole reason
     * this is a decision rather than a figure. A post empty for
     * seventy-four days is either work nobody is doing or a post the
     * organization no longer needs, and the figure cannot tell you
     * which — a person has to.
     *
     * NO NEW QUERY. These are the same positions the over-threshold
     * figure is counted from, asked for by name instead of by number.
     *
     * @return list<TopicDecision>
     */
    public function decisions(PerformanceScope $scope, FigurePeriod $period): array
    {
        $departments = $this->departmentsIn($scope);
        $now = new \DateTimeImmutable();

        $raised = [];
        foreach ($departments as $department) {
            foreach ($this->membership->positionsIn($department) as $position) {
                if (null === $position->getVacantSince()) {
                    continue;
                }

                $days = $this->vacancy->daysVacant($position, $now);
                if (null === $days || $days < self::THRESHOLD_DAYS) {
                    continue;
                }

                $raised[] = [$days, new TopicDecision(
                    what: \sprintf('%s has stood empty %d days', (string) $position->getName(), $days),
                    ask: 'Fill the post, or close it — an empty post is work nobody is doing or a post nobody needs.',
                    departmentName: (string) $department->getName(),
                    departmentMark: DepartmentMark::of((string) $department->getName()),
                    tone: MovementTone::Bad,
                )];
            }
        }

        // WORST FIRST, which here is longest empty: a post nobody has
        // filled in four months is a different conversation from one
        // that crossed the line last week.
        usort($raised, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $row): TopicDecision => $row[1], $raised);
    }

    /**
     * WHAT MOVED IN THE ESTABLISHMENT — the seats, and the posts that
     * have stood empty too long.
     *
     * THE SECOND HALF IS THE POINT. "Three more seats filled" reads as
     * a good month on its own; a month that filled three and left two
     * posts past the threshold is the month somebody has to act on, and
     * only this topic knows those two figures belong in one sentence.
     */
    public function movement(PerformanceScope $scope, FigurePeriod $period): ?TopicMovement
    {
        $kpis = $this->kpis($scope, $period);
        $filled = self::figureOf($kpis, StaffingFigures::FILLED);
        $stale = self::figureOf($kpis, 'staffing.over_threshold');

        if (null === $filled) {
            return null;
        }

        $moved = $filled->delta ?? 0.0;
        $overdue = null === $stale ? 0 : (int) ($stale->value ?? 0.0);

        // NOTHING MOVED AND NOTHING IS OVERDUE IS NOT A SENTENCE. A
        // briefing that said "no change" five times would be five lines
        // a reader learns to skip.
        if (0.0 === $moved && 0 === $overdue) {
            return null;
        }

        $seats = 0.0 === $moved
            ? 'No seat changed hands'
            : \sprintf('%s %s filled', 0 < $moved ? 'Another '.self::plainly(abs($moved)) : self::plainly(abs($moved)).' fewer', 1.0 === abs($moved) ? 'seat' : 'seats');

        if (0 === $overdue) {
            return new TopicMovement($seats.', and no post is past the threshold.', 0 < $moved ? MovementTone::Good : MovementTone::Bad);
        }

        return new TopicMovement(
            \sprintf('%s — but %d %s now past the 60-day threshold.', $seats, $overdue, 1 === $overdue ? 'post is' : 'posts are'),
            MovementTone::Attention,
        );
    }

    /**
     * One of this topic's own five, by the key it published it under.
     *
     * @param list<TopicKpi> $kpis
     */
    private static function figureOf(array $kpis, string $key): ?TopicKpi
    {
        foreach ($kpis as $kpi) {
            if ($key === $kpi->key) {
                return $kpi;
            }
        }

        return null;
    }

    public function charts(PerformanceScope $scope, FigurePeriod $period): array
    {
        $months = PerformanceHistory::monthsEndingAt($period->from, self::PERIODS);

        $filled = [];
        $vacant = [];
        foreach ($months as $month) {
            $filled[] = $this->sumAcross(StaffingFigures::FILLED, $month, $scope);
            $vacant[] = $this->sumAcross(StaffingFigures::VACANT, $month, $scope);
        }

        return [
            new TopicChart(
                key: 'staffing.seats',
                title: 'Seats filled and vacant',
                kind: ChartKind::Stacked,
                labels: array_map(static fn (string $month): string => mb_strtolower(new \DateTimeImmutable($month.'-01')->format('M')), $months),
                series: [
                    new ChartSeries('Filled', $filled),
                    new ChartSeries('Vacant', $vacant),
                ],
                caption: 'What the organization held, period by period — from the periods it wrote down.',
            ),
        ];
    }

    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $months = PerformanceHistory::monthsEndingAt($period->from, self::PERIODS);
        $previous = PerformanceHistory::keyFor($period->against());

        $columns = [
            new MatrixColumn(StaffingFigures::FILLED, 'Positions filled', polarity: ColumnPolarity::Up),
            new MatrixColumn(StaffingFigures::VACANT, 'Vacant', polarity: ColumnPolarity::Down),
            new MatrixColumn(StaffingFigures::PEOPLE, 'People', polarity: ColumnPolarity::Up),
            new MatrixColumn(StaffingFigures::POSITIONS, 'Positions', polarity: ColumnPolarity::None),
        ];

        $rows = [];
        foreach ($this->departmentsIn($scope) as $department) {
            $today = $this->staffing->of($department);

            $cells = [];
            foreach ($columns as $column) {
                $was = $this->history->valueAt($department, $column->key, $previous);
                $value = $today[$column->key] ?? null;

                $cells[$column->key] = new MatrixCell(
                    value: $value,
                    delta: null === $was || null === $value ? null : $value - $was,
                    history: array_values($this->history->runFor($department, $column->key, $months)),
                );
            }

            $rows[] = new MatrixRow(
                departmentUuid: (string) $department->getUuidString(),
                departmentName: (string) $department->getName(),
                cells: $cells,
                band: null === $department->getArea() ? 'Org-wide' : (string) $department->getArea()->getName(),
            );
        }

        return new TopicMatrix(
            $columns,
            $rows,
            'Every department has seats and people whatever it attaches, so every department is a row.',
        );
    }

    /**
     * THE POSTS THAT HAVE STOOD EMPTY LONGER THAN THE INSTALLATION ALLOWS.
     *
     * @param list<Department> $departments
     */
    private function vacancies(array $departments, FigurePeriod $period): TopicKpi
    {
        $empty = [];
        $undated = 0;
        $seen = [];
        foreach ($departments as $department) {
            foreach ($this->membership->positionsIn($department) as $position) {
                if (isset($seen[(int) $position->getId()])) {
                    continue;
                }
                $seen[(int) $position->getId()] = true;

                if (null === $position->getVacantSince()) {
                    // HELD, OR EMPTY SINCE BEFORE ANYBODY WROTE THE DAY DOWN.
                    // Which it is, is answered by whether somebody holds it.
                    if (0 === $this->users->countActiveHoldingAnyPosition([$position])) {
                        ++$undated;
                    }

                    continue;
                }

                $empty[] = $position;
            }
        }

        $over = $this->vacancy->overThreshold($empty, self::THRESHOLD_DAYS);

        return new TopicKpi(
            key: 'staffing.over_threshold',
            // THE LABEL NAMES THE RULE AND THE FRAGMENT NAMES THE NUMBER:
            // "Vacant over 60 days" spent the widest card in the row on a
            // constant, and the row beside it already says Vacant.
            label: 'Over threshold',
            value: (float) $over,
            caption: 0 === $undated
                ? \sprintf('unfilled past %d days', self::THRESHOLD_DAYS)
                : \sprintf('unfilled past %d days · %d more stood empty before the day was recorded', self::THRESHOLD_DAYS, $undated),
            polarity: ColumnPolarity::Down,
        );
    }

    /**
     * ONE HEADLINE FIGURE, with its movement and its run — both read out
     * of what was written down, because neither can be recomputed.
     *
     * @param list<Department> $departments
     */
    private function figure(
        string $key,
        string $label,
        float $value,
        array $departments,
        FigurePeriod $period,
        ColumnPolarity $polarity,
        string $caption = '',
    ): TopicKpi {
        $months = PerformanceHistory::monthsEndingAt($period->from, self::PERIODS);
        $previous = PerformanceHistory::keyFor($period->against());

        $history = [];
        foreach ($months as $month) {
            $history[] = $this->sumAcross($key, $month, null, $departments);
        }

        $was = $this->sumAcross($key, $previous, null, $departments);

        return new TopicKpi(
            key: $key,
            label: $label,
            value: $value,
            delta: null === $was ? null : $value - $was,
            history: $history,
            caption: $caption,
            polarity: $polarity,
        );
    }

    /**
     * WHAT THE WHOLE SCOPE WAS IN ONE PERIOD — null where nobody wrote any
     * of it down, because a sum of nothing is not nought.
     *
     * @param list<Department>|null $departments the ones already resolved, where the caller has them
     */
    private function sumAcross(string $key, string $periodKey, ?PerformanceScope $scope = null, ?array $departments = null): ?float
    {
        $departments ??= $this->departmentsIn($scope ?? PerformanceScope::organization());

        $sum = null;
        foreach ($departments as $department) {
            $value = $this->history->valueAt($department, $key, $periodKey);
            if (null !== $value) {
                $sum = ($sum ?? 0.0) + $value;
            }
        }

        return $sum;
    }

    /**
     * THE DEPARTMENTS THIS SCOPE HOLDS: every one for the organization, and
     * for an area the ones that read it — its own and the org-wide ones,
     * which is what "reads this area" means everywhere else in the product.
     *
     * @return list<Department>
     */
    private function departmentsIn(PerformanceScope $scope): array
    {
        $all = $this->departments->findAllActiveOrdered();
        if ($scope->isOrganization()) {
            return $all;
        }

        return array_values(array_filter($all, static function (Department $department) use ($scope): bool {
            $area = $department->getArea();

            return null === $area || $scope->areaUuid === $area->getUuidString();
        }));
    }

    private static function plainly(float $value): string
    {
        return number_format($value, 0, '.', ',');
    }
}
