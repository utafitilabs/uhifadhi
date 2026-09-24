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
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalStateEnum;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentMark;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\CellMark;
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
use Uhifadhi\Contracts\Performance\TopicLedgerInterface;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Contracts\Performance\TopicMovement;
use Uhifadhi\Contracts\Performance\TopicMovementInterface;

/**
 * WHAT EACH DEPARTMENT SAID IT WOULD DO — the host's second topic.
 *
 * EVERY DEPARTMENT IS A ROW, whatever it attaches: any department can
 * declare a goal, and a department that declared none is a row saying
 * so. That is what makes this comparable, and it is why the matrix
 * lists all nine rather than folding the quiet ones away.
 *
 * A GOAL'S STATE IS DERIVED, NEVER STORED. It is read from the figure
 * the goal names against the target it set, at the moment of asking —
 * so a module that revises a figure revises the goal's state with it,
 * and a target nobody has a figure for reads "no figure yet" rather
 * than passing or failing by default. The rule lives on the goal
 * itself ({@see DepartmentGoal::stateFor()}), which is where a reader
 * looking for it will look.
 *
 * NO FIGURE YET IS NOT A MISS. It is the fourth state precisely so
 * that a goal whose module has not reported cannot be counted against
 * the department that declared it; "3 met of 9" and "4 with no figure"
 * are two facts and the page states both.
 *
 * IT PUBLISHES THROUGH THE SAME SEAM A MODULE DOES, as
 * {@see StaffingTopic} does and for the same reason.
 */
final readonly class GoalsTopic implements PerformanceTopicProviderInterface, TopicDecisionsInterface, TopicLedgerInterface, TopicMovementInterface
{
    public const string KEY = 'goals';

    /** Every state a matrix column counts, in the order the design reads them. */
    private const array COUNTED = [
        GoalStateEnum::Met,
        GoalStateEnum::AtRisk,
        GoalStateEnum::Missed,
        GoalStateEnum::NoFigure,
    ];

    public function __construct(
        private DepartmentRepository $departments,
        private DepartmentGoalRepository $goals,
        private DepartmentPerformance $performance,
    ) {
    }

    public function moduleSlug(): string
    {
        // THE HOST'S OWN. Not a module, and the renderer says "the host"
        // beside it exactly as it names the module beside a module's.
        return self::HOST;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Goals';
    }

    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $states = $this->statesIn($scope);
        $declared = 0;
        $declaring = 0;
        foreach ($states as $byDepartment) {
            $declared += \count($byDepartment);
            $declaring += [] === $byDepartment ? 0 : 1;
        }

        $tally = self::tally($states);
        $departments = \count($states);

        $atRisk = $tally[GoalStateEnum::AtRisk->value];
        $missed = $tally[GoalStateEnum::Missed->value];

        /*
         * FOUR, IN THE DESIGN'S ORDER: what was declared, what was met,
         * what is not on course, and what nothing can be measured
         * against.
         *
         * AT RISK AND MISSED ARE ONE CARD. They are two verdicts a
         * reader acts on the same way — this is not going to land, so
         * somebody has to decide — and a row of four has no room for
         * the distinction as a plate. It is not lost: the fragment
         * keeps them apart ("2 at risk · 1 missed"), and the BRIEFING
         * asks about them separately, because the two asks really are
         * different once you are deciding rather than counting (a
         * missed goal is finished; one at risk can still be moved).
         */
        return [
            new TopicKpi(
                key: 'goals.declared',
                label: 'Declared',
                value: (float) $declared,
                caption: \sprintf('by %d of %d departments', $declaring, $departments),
                polarity: ColumnPolarity::None,
            ),
            new TopicKpi(
                key: 'goals.met',
                label: 'Met',
                value: (float) $tally[GoalStateEnum::Met->value],
                caption: \sprintf('of %d declared', $declared),
                polarity: ColumnPolarity::Up,
            ),
            new TopicKpi(
                key: 'goals.off_track',
                label: 'Off track',
                value: (float) ($atRisk + $missed),
                caption: \sprintf('%d at risk · %d missed', $atRisk, $missed),
                polarity: ColumnPolarity::Down,
            ),
            /*
             * NOT A MISS AND NOT A PASS. A goal whose module has not
             * reported is counted here and nowhere else, so neither of
             * the verdicts above is inflated by a silence.
             */
            new TopicKpi(
                key: 'goals.no_figure',
                label: 'No figure yet',
                value: (float) $tally[GoalStateEnum::NoFigure->value],
                caption: 'nothing published to measure against',
                polarity: ColumnPolarity::None,
            ),
        ];
    }

    /**
     * WHAT SOMEBODY HAS TO DECIDE ABOUT THE GOALS: one that was missed,
     * and one still open and behind.
     *
     * TWO DIFFERENT ASKS, and that is why they are two decisions rather
     * than one count. A MISSED goal is finished and the question is
     * what the next one should be; an AT-RISK goal is still open and
     * the question is whether anything can still be done about it.
     * Collapsing them into "2 goals in trouble" would hide the one that
     * can still be saved.
     *
     * NO NEW QUERY: these are the same states the five figures are
     * tallied from, asked for by department instead of by count.
     *
     * @return list<TopicDecision>
     */
    public function decisions(PerformanceScope $scope, FigurePeriod $period): array
    {
        $missed = [];
        $atRisk = [];

        // READ ONCE, not once per department: this is the same tally the
        // five figures are counted from, and asking for it inside the
        // loop would be the briefing paying a query per department for
        // an answer it already had.
        $byDepartment = $this->statesIn($scope);

        foreach ($this->departmentsIn($scope) as $department) {
            $name = (string) $department->getName();
            $states = $byDepartment[(string) $department->getUuidString()] ?? [];

            foreach ($states as $state) {
                if (GoalStateEnum::Missed === $state) {
                    $missed[] = new TopicDecision(
                        what: \sprintf('%s missed a declared goal this period', $name),
                        ask: 'Decide what replaces it — a goal nobody restates is a goal the organization has quietly dropped.',
                        departmentName: $name,
                        departmentMark: DepartmentMark::of($name),
                        tone: MovementTone::Bad,
                    );
                }

                if (GoalStateEnum::AtRisk === $state) {
                    $atRisk[] = new TopicDecision(
                        what: \sprintf('%s has a goal behind pace and still open', $name),
                        ask: 'Decide whether it can still be made, and say so while there is time to act.',
                        departmentName: $name,
                        departmentMark: DepartmentMark::of($name),
                        tone: MovementTone::Attention,
                    );
                }
            }
        }

        // MISSED FIRST: it is the one that is already true.
        return [...$missed, ...$atRisk];
    }

    /**
     * WHAT MOVED IN THE GOALS — and for a goal, what moved is a STATE
     * crossed, not a figure that drifted.
     *
     * A GOAL THAT WENT MET → MISSED IS THE LINE. The count of met goals
     * falling by one says the same arithmetic and none of the meaning:
     * somebody declared a thing, and this period it stopped being true.
     * And a department that declared NOTHING is the other half — it
     * cannot miss a goal it never made, and a board that only counted
     * misses would call it the best department in the organization.
     */
    public function movement(PerformanceScope $scope, FigurePeriod $period): ?TopicMovement
    {
        $kpis = $this->kpis($scope, $period);
        $met = self::figureOf($kpis, 'goals.met');
        $missed = self::figureOf($kpis, 'goals.missed');
        $risk = self::figureOf($kpis, 'goals.at_risk');

        $crossed = (int) abs($met->delta ?? 0.0);
        $failing = (int) ($missed->value ?? 0.0) + (int) ($risk->value ?? 0.0);

        if (0 === $crossed && 0 === $failing) {
            return null;
        }

        if (0 === $crossed) {
            return new TopicMovement(
                \sprintf('No goal crossed a state, and %d %s still short of pace.', $failing, 1 === $failing ? 'is' : 'are'),
                MovementTone::Attention,
            );
        }

        $improving = 0.0 < ($met->delta ?? 0.0);

        return new TopicMovement(
            \sprintf(
                '%d %s crossed a state this period, %s. %d %s short of pace.',
                $crossed,
                1 === $crossed ? 'goal' : 'goals',
                $improving ? 'into met' : 'out of met',
                $failing,
                1 === $failing ? 'is' : 'are',
            ),
            $improving ? MovementTone::Good : MovementTone::Bad,
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
        $tally = self::tally($this->statesIn($scope));

        return [
            new TopicChart(
                key: 'goals.states',
                title: 'Where the goals stand',
                kind: ChartKind::Bar,
                labels: array_map(static fn (GoalStateEnum $state): string => $state->label(), self::COUNTED),
                series: [
                    new ChartSeries('Goals', array_map(
                        static fn (GoalStateEnum $state): float => (float) $tally[$state->value],
                        self::COUNTED,
                    )),
                ],
                caption: 'Read at the moment of asking, against each goal\'s own target.',
            ),
        ];
    }

    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $columns = [
            new MatrixColumn('goals.declared', 'Declared', polarity: ColumnPolarity::None),
            new MatrixColumn('goals.met', 'Met', polarity: ColumnPolarity::Up),
            new MatrixColumn('goals.at_risk', 'At risk', polarity: ColumnPolarity::Down),
            new MatrixColumn('goals.missed', 'Missed', polarity: ColumnPolarity::Down),
            new MatrixColumn('goals.no_figure', 'No figure yet', polarity: ColumnPolarity::None),
            /*
             * AND HOW EACH ONE IS PACING — a chip per goal, not a figure.
             * Four goals in four states is four facts, and an average of
             * them would answer a question nobody asked; a reader wants
             * to see that one is missed while three are met.
             */
            new MatrixColumn('goals.pace', 'Pace', polarity: ColumnPolarity::None),
        ];

        $rows = [];
        foreach ($this->statesIn($scope) as $uuid => $states) {
            $department = $this->departmentOf($scope, $uuid);
            if (!$department instanceof Department) {
                continue;
            }

            $tally = self::tally([$states]);

            $cells = ['goals.declared' => new MatrixCell(value: (float) \count($states))];
            foreach (self::COUNTED as $state) {
                $cells['goals.'.$state->value] = new MatrixCell(value: (float) $tally[$state->value]);
            }

            $cells['goals.pace'] = MatrixCell::marking(...array_map(
                static fn (GoalStateEnum $state): CellMark => new CellMark($state->label(), self::reads($state)),
                $states,
            ));

            $rows[] = new MatrixRow(
                departmentUuid: $uuid,
                departmentName: (string) $department->getName(),
                cells: $cells,
                band: null === $department->getArea() ? 'Org-wide' : (string) $department->getArea()->getName(),
            );
        }

        return new TopicMatrix(
            $columns,
            $rows,
            'Any department can declare a goal, so every department is a row — including the ones that declared none.',
        );
    }

    /**
     * EVERY DEPARTMENT IN SCOPE, AND THE STATE OF EACH OF ITS GOALS.
     *
     * Keyed by department so a department with no goals is an empty
     * list rather than a missing key: "declared none" is an answer this
     * topic has to be able to give.
     *
     * @return array<string, list<GoalStateEnum>>
     */
    private function statesIn(PerformanceScope $scope): array
    {
        $now = new \DateTimeImmutable();

        $states = [];
        foreach ($this->departmentsIn($scope) as $department) {
            $figures = [];
            foreach ($this->performance->kpisFor($department, $now) as $kpi) {
                $figures[$kpi->key] = $kpi->value;
            }

            $read = [];
            foreach ($this->goals->findForDepartment($department) as $goal) {
                $ref = $goal->getKpiRef();
                $read[] = $goal->stateFor(null === $ref ? null : ($figures[$ref] ?? null), $now);
            }

            $states[(string) $department->getUuidString()] = $read;
        }

        return $states;
    }

    /**
     * @param iterable<list<GoalStateEnum>> $states
     *
     * @return array<string, int> every counted state, including the ones at nought
     */
    private static function tally(iterable $states): array
    {
        $tally = [];
        foreach (self::COUNTED as $state) {
            $tally[$state->value] = 0;
        }

        foreach ($states as $byDepartment) {
            foreach ($byDepartment as $state) {
                if (\array_key_exists($state->value, $tally)) {
                    ++$tally[$state->value];
                }
            }
        }

        return $tally;
    }

    /**
     * HOW A STATE READS. The word is the goal's own; whether it is good
     * news is the platform's, so a surface colours four topics' chips
     * the same way without each of them deciding.
     */
    private static function reads(GoalStateEnum $state): ColumnPolarity
    {
        return match ($state) {
            GoalStateEnum::Met => ColumnPolarity::Up,
            GoalStateEnum::AtRisk, GoalStateEnum::Missed => ColumnPolarity::Down,
            // Not yet an answer, so not yet good or bad news.
            GoalStateEnum::NoFigure, GoalStateEnum::None => ColumnPolarity::None,
        };
    }

    /** @return list<Department> */
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

    private function departmentOf(PerformanceScope $scope, string $uuid): ?Department
    {
        foreach ($this->departmentsIn($scope) as $department) {
            if ($uuid === $department->getUuidString()) {
                return $department;
            }
        }

        return null;
    }
}
