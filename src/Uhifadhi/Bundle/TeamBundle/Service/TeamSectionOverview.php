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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\AxisScale;
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyMark;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Model\PostingStation;
use Uhifadhi\Bundle\TeamBundle\Model\SectionFact;
use Uhifadhi\Bundle\TeamBundle\Model\SectionKpi;
use Uhifadhi\Bundle\TeamBundle\Model\SectionLine;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Verb;

/**
 * WHAT THE TEAM SECTION'S OVERVIEW READS, AND OWNS NOTHING OF.
 *
 * Every figure here exists somewhere else already — on the register, on
 * Positions, on the station in the area. The overview is a reading of them, so
 * this service reads and never writes, and the page it feeds has no control
 * that changes anybody. That is what makes it safe to open first.
 *
 * ONE PASS OVER THE PEOPLE AND ONE OVER THE POSITIONS. Every figure below is
 * counted from two lists rather than from a query per department; walking a
 * department's inverse collection is a lazy load per card, and the inverse of a
 * OneToMany is only as true as whoever maintained it.
 *
 * THE POSTINGS FIGURE IS THE ONE THAT CROSSES A BUNDLE BOUNDARY, and it
 * crosses read-only, through the board. An installation with no ground package
 * reads nought stations, which is true rather than broken.
 *
 * NO MOVEMENT IS CLAIMED. The drawn row carries a delta pill against the
 * previous period, and this installation records no previous figure for any of
 * these — a pill reading zero would be a claim it cannot make, so the pill is
 * absent until the figure has a history to compare against.
 */
final readonly class TeamSectionOverview
{
    /** How many rows a bounded card shows before it hands the rest to a register. */
    private const int BOUND = 5;

    /** The row somebody placed in no department is counted under. */
    private const string UNPLACED = 'No department';

    /** The chart's axis climbs in eights, so its half is a whole number too. */
    private const int AXIS_STEP = 8;

    /** The width the design draws an area's column at, in pixels. */
    private const float COLUMN_WIDTH = 40.0;

    public function __construct(
        private UserRepository $users,
        private PositionRepository $positions,
        private DepartmentRepository $departments,
        private PostingBoard $board,
        private PerformanceHistory $history,
    ) {
    }

    /**
     * THE FIVE FIGURES THIS SECTION REMEMBERS, as they are right now — what
     * the snapshot command writes down while the period is still true.
     *
     * IT IS THE SAME COMPUTATION THE PAGE DRAWS, deliberately: a history
     * written by a second reckoning of the same words is a history the page
     * would disagree with the moment either changed.
     *
     * @return array<string, float>
     */
    public function figures(): array
    {
        $people = $this->users->findAllByName();
        $positions = $this->positions->findAllOrdered();
        $held = self::heldPositionIds($people);

        $postings = 0;
        foreach ($this->board->board() as $station) {
            $postings += \count($station->rows);
        }

        return [
            TeamFigures::PEOPLE => (float) \count($people),
            TeamFigures::POSITIONS => (float) \count($positions),
            TeamFigures::FILLED => (float) \count($held),
            TeamFigures::POSTINGS => (float) $postings,
            TeamFigures::ADMINISTRATORS => (float) self::mayAdminister($people),
        ];
    }

    /**
     * @return array{
     *     facts: list<SectionFact>,
     *     kpis: list<SectionKpi>,
     *     byDepartment: RankedBars,
     *     people: int,
     *     seats: RankedBars,
     *     positions: int,
     *     departments: int,
     *     postingsChart: AtlasChart|null,
     *     postings: int,
     *     emptyStations: list<SectionLine>,
     *     emptyStationsTotal: int,
     *     stations: int,
     *     holdsNothing: list<SectionLine>,
     *     holdsNothingTotal: int,
     *     neverSignedIn: list<SectionLine>,
     *     neverSignedInTotal: int,
     *     active: int,
     * }
     */
    public function read(): array
    {
        $people = $this->users->findAllByName();
        $positions = $this->positions->findAllOrdered();
        $stations = $this->board->board();

        $held = self::heldPositionIds($people);
        $known = $this->departments->findAllOrdered();
        $byDepartment = self::peopleByDepartment($people, $known);
        $seats = self::seatsByDepartment($people, $known, $held);

        $active = \count(array_filter($people, static fn (User $u): bool => $u->isActive()));
        $departments = \count($known);

        $postings = 0;
        $emptyStations = [];
        $areas = [];
        foreach ($stations as $station) {
            $postings += \count($station->rows);
            $areas[$station->areaName] = ($areas[$station->areaName] ?? 0) + \count($station->rows);
            if ($station->isEmpty()) {
                $emptyStations[] = new SectionLine(
                    label: $station->name,
                    note: $station->note(),
                );
            }
        }

        $holdsNothing = self::linesFor($this->users->findActiveWithoutPosition());
        $neverSignedIn = self::linesFor(array_filter($people, static fn (User $u): bool => !$u->isVerified()));

        return [
            'facts' => $this->facts($people, $positions, $held, $stations, $postings),
            'kpis' => $this->kpis($people, $positions, $held, $postings, $stations),
            'byDepartment' => $byDepartment,
            'people' => \count($people),
            'seats' => $seats,
            'positions' => \count($positions),
            'departments' => $departments,
            'postingsChart' => self::postingsChart($areas),
            'postings' => $postings,
            'emptyStations' => \array_slice($emptyStations, 0, self::BOUND),
            'emptyStationsTotal' => \count($emptyStations),
            'stations' => \count($stations),
            'holdsNothing' => \array_slice($holdsNothing, 0, self::BOUND),
            'holdsNothingTotal' => \count($holdsNothing),
            'neverSignedIn' => \array_slice($neverSignedIn, 0, self::BOUND),
            'neverSignedInTotal' => \count($neverSignedIn),
            'active' => $active,
        ];
    }

    /**
     * @param list<User>           $people
     * @param list<Position>       $positions
     * @param array<int, true>     $held
     * @param list<PostingStation> $stations
     *
     * @return list<SectionFact>
     */
    private function facts(array $people, array $positions, array $held, array $stations, int $postings): array
    {
        $active = \count(array_filter($people, static fn (User $u): bool => $u->isActive()));
        $unheld = \count($positions) - \count($held);
        $departments = \count($this->departments->findAllOrdered());

        return [
            new SectionFact('People', (string) \count($people), \sprintf('%d active · %d deactivated', $active, \count($people) - $active)),
            new SectionFact('Positions', (string) \count($positions), \sprintf('in %d departments', $departments)),
            new SectionFact(
                'Held',
                (string) \count($held),
                \sprintf('of %d · %d nobody holds', \count($positions), $unheld),
            ),
            new SectionFact('Assignments', (string) $postings, \sprintf('%d stations', \count($stations))),
            new SectionFact(
                'Roles',
                (string) \count(TeamRoleEnum::cases()),
                \sprintf('tiers · %d may administer', self::mayAdminister($people)),
            ),
        ];
    }

    /**
     * THE FOUR KPI CARDS, FOUR OR NONE — the area overview's own row, and
     * four to a row is the ruled shape of every strip in the product.
     *
     * @param list<User>           $people
     * @param list<Position>       $positions
     * @param array<int, true>     $held
     * @param list<PostingStation> $stations
     *
     * @return list<SectionKpi>
     */
    private function kpis(array $people, array $positions, array $held, int $postings, array $stations): array
    {
        /*
         * THE PERIOD A MOVEMENT IS MEASURED AGAINST is the last CLOSED one —
         * the month before this. The month in progress is still moving, and
         * a card that compared today against a period half-written would
         * report a fall every first of the month.
         */
        $before = $this->history->installationAt(
            PerformanceHistory::monthKey(new \DateTimeImmutable('first day of last month')),
        );
        $active = \count(array_filter($people, static fn (User $u): bool => $u->isActive()));
        $departments = \count($this->departments->findAllOrdered());
        $empty = \count(array_filter($stations, static fn (PostingStation $s): bool => $s->isEmpty()));
        $holdsNothing = \count($this->users->findActiveWithoutPosition());

        return [
            new SectionKpi(
                'People',
                (string) \count($people),
                qualifier: \sprintf('%d active · %d deactivated', $active, \count($people) - $active),
                delta: self::movement(\count($people), $before, TeamFigures::PEOPLE),
            ),
            new SectionKpi(
                'Positions',
                (string) \count($positions),
                qualifier: \sprintf('in %d departments · names unique inside one', $departments),
                delta: self::movement(\count($positions), $before, TeamFigures::POSITIONS),
            ),
            /*
             * A SEAT IS A POSITION, FOR NOW. The drawn card counts SEATS — a
             * position that can be held by more than one person — and the model
             * has no such number: a position is one post, held or not. The
             * figure is therefore positions, which is the truth this
             * installation can state; the multi-seat position is a model change
             * that rewrites the positions screen too and is not made here.
             */
            new SectionKpi(
                'Seats filled',
                (string) \count($held),
                of: \sprintf('of %d', \count($positions)),
                qualifier: \sprintf('%d vacant · %d hold none', \count($positions) - \count($held), $holdsNothing),
                delta: self::movement(\count($held), $before, TeamFigures::FILLED),
            ),
            new SectionKpi(
                'Assignments',
                (string) $postings,
                qualifier: \sprintf('%d stations · %d with nobody', \count($stations), $empty),
                delta: self::movement($postings, $before, TeamFigures::POSTINGS),
            ),
            /*
             * AND NOT A FIFTH. A strip is FOUR to a row (ruled): five wrapped
             * an orphan onto a second line on a small laptop, and a row of
             * four is the shape every strip in the product keeps.
             *
             * ROLES WAS THE ONE TO GO, and not by length. The tiers are three
             * and they never move — a card whose figure is a constant of the
             * product is a card that tells a reader nothing twice a day — and
             * the number under it that DOES move, how many people may
             * administer, is already the People card's own business and is
             * drawn in full on the people register's strip.
             */
        ];
    }

    /**
     * THE MOVEMENT AGAINST THE LAST CLOSED PERIOD, or NULL where nobody wrote
     * that period down.
     *
     * A PERIOD NOBODY WROTE HAS NO PILL. An installation whose snapshot has
     * never run, or one younger than a month, has no previous figure — and
     * "0" there would say the figure held steady, which is a claim it cannot
     * make. It is an absolute movement and never a percentage: four more
     * people is four more people.
     *
     * @param array<string, float|null> $before
     */
    private static function movement(int $now, array $before, string $key): ?float
    {
        $was = $before[$key] ?? null;

        return null === $was ? null : $now - $was;
    }

    /**
     * WHO MAY ADMINISTER THE TEAM — the two tiers that stand above the matrix,
     * plus everybody whose position carries the grant. It is not the tier
     * column, and a page that read it off the tier column would be wrong on
     * every installation that delegates.
     *
     * @param list<User> $people
     */
    private static function mayAdminister(array $people): int
    {
        $count = 0;
        foreach ($people as $person) {
            if (!$person->isActive()) {
                continue;
            }
            if ($person->getTeamRole()->canManageContent()
                || true === $person->getPosition()?->grantsVerbOn(TeamConcerns::DIRECTORY, Verb::Manage)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * EVERY POSITION SOMEBODY SITS IN, by id. Held is a fact about the PEOPLE
     * and not a column on the position, so it is read from the one side that
     * knows.
     *
     * @param list<User> $people
     *
     * @return array<int, true>
     */
    private static function heldPositionIds(array $people): array
    {
        $held = [];
        foreach ($people as $person) {
            $id = $person->isActive() ? $person->getPosition()?->getId() : null;
            if (null !== $id) {
                $held[$id] = true;
            }
        }

        return $held;
    }

    /**
     * PEOPLE BY DEPARTMENT, LONGEST FIRST, and the share of the installation
     * each one is. A person with no position has no department, and that is a
     * row rather than a rounding: it is exactly the person the attention card
     * below is about.
     *
     * A PERSON IS COUNTED IN EVERY DEPARTMENT THEY ARE PLACED IN, and several
     * are allowed - so the bars add up to more than the headcount, which is
     * the honest shape for a placement that names two. Somebody placed
     * nowhere is counted once under "No department", because an unplaced
     * person is a real state a director needs to see.
     *
     * @param list<User>       $people
     * @param list<Department> $departments
     */
    private static function peopleByDepartment(array $people, array $departments): RankedBars
    {
        // A DEPARTMENT NOBODY IS PLACED IN KEEPS ITS ROW, at nought and dimmed.
        $counts = [];
        foreach ($departments as $department) {
            $counts[(string) $department->getName()] = 0;
        }
        foreach ($people as $person) {
            $placement = $person->getPlacement();
            $in = [];
            foreach ($departments as $department) {
                if (null !== $placement && $placement->coversDepartment($department)) {
                    $in[] = (string) $department->getName();
                }
            }

            foreach ([] === $in ? [self::UNPLACED] : $in as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);

        $total = \count($people);
        $bars = [];
        foreach ($counts as $name => $count) {
            $share = $total > 0 ? (int) round($count / $total * 100) : 0;
            $bars[] = new Bar(
                label: (string) $name,
                value: (float) $count,
                figure: (string) $count,
                note: \sprintf(' · %d %%', $share),
                // SOMEBODY PLACED NOWHERE IS NOT A DEPARTMENT, and is dimmed
                // beside the ones that are.
                quiet: self::UNPLACED === $name ? true : null,
            );
        }

        return new RankedBars($bars, empty: 'Nobody on this installation yet.');
    }

    /**
     * POSITIONS HELD AND UNHELD, by department. A position with no holder is a
     * permission set sitting ready rather than an error, so the gap is drawn
     * beside the fill rather than reported as a fault.
     *
     * A DEPARTMENT'S POSITIONS ARE THE ONES ITS MEMBERS HOLD, derived rather
     * than filed: a position belongs to nobody now, so the only honest way to
     * ask which ones a department sees is to ask who is placed in it.
     *
     * @param list<User>       $people
     * @param list<Department> $departments
     * @param array<int, true> $held
     */
    private static function seatsByDepartment(array $people, array $departments, array $held): RankedBars
    {
        $totals = $filled = [];
        foreach ($departments as $department) {
            $name = (string) $department->getName();
            $seen = [];
            foreach ($people as $person) {
                $position = $person->getPosition();
                if (null === $position || !($person->getPlacement()?->coversDepartment($department) ?? false)) {
                    continue;
                }
                $seen[(int) $position->getId()] = true;
            }

            $totals[$name] = \count($seen);
            $filled[$name] = \count(array_intersect_key($seen, $held));
        }

        // LONGEST FIRST, and a department with no position last: it keeps its
        // row, dimmed, and says so rather than drawing an empty track.
        uksort($totals, static fn (string|int $a, string|int $b): int => $totals[$b] <=> $totals[$a] ?: strcmp((string) $a, (string) $b));

        $bars = [];
        foreach ($totals as $name => $total) {
            $unheld = $total - $filled[$name];
            $bars[] = 0 === $total
                ? new Bar(label: (string) $name, value: 0.0, note: 'no position yet')
                : new Bar(
                    label: (string) $name,
                    value: (float) $filled[$name],
                    rest: (float) $unheld,
                    figure: (string) $filled[$name],
                    note: \sprintf('/%d%s', $total, $unheld > 0 ? \sprintf(' · %d unheld', $unheld) : ''),
                );
        }

        return new RankedBars(
            $bars,
            key: new DotKey([new KeyEntry('somebody holds it'), new KeyEntry('nobody holds it', KeyMark::Rest)]),
            empty: 'No position yet.',
        );
    }

    /**
     * ASSIGNMENTS BY AREA, as the chart the atlas draws: one column an area,
     * in the house accent because the card measures one thing and it is no
     * category. Null where no area has a station, which the card says in
     * words instead.
     *
     * AN AREA WITH NONE KEEPS ITS COLUMN, drawn as the faded hairline a
     * nought is: an area whose stations nobody is stationed at is the reading
     * the card exists for.
     *
     * THE TOP OF THE AXIS IS A ROUND NUMBER ABOVE THE TALLEST COLUMN, with its
     * half between: an axis whose top is 31 tells a reader to do arithmetic,
     * and a column drawn to the very top of its frame reads as clipped.
     *
     * @param array<string, int> $areas
     */
    private static function postingsChart(array $areas): ?AtlasChart
    {
        if ([] === $areas) {
            return null;
        }

        ksort($areas);
        $top = (float) (max(1, (int) ceil(max($areas) / self::AXIS_STEP)) * self::AXIS_STEP);

        return new AtlasChart(
            ChartKind::Bar,
            array_map(static fn (string|int $name): string => mb_strtolower((string) $name), array_keys($areas)),
            [new ChartSeries('Assignments', array_map(static fn (int $postings): float => (float) $postings, array_values($areas)), accent: true)],
            axis: new AxisScale($top, $top / 2),
            legend: ChartLegend::None,
            noughts: ChartNoughts::Hairline,
            barWidth: self::COLUMN_WIDTH,
        );
    }

    /**
     * @param iterable<User> $people
     *
     * @return list<SectionLine>
     */
    private static function linesFor(iterable $people): array
    {
        $lines = [];
        foreach ($people as $person) {
            $lines[] = new SectionLine(
                label: $person->getFullName(),
                uuid: $person->getUuidString(),
                note: implode(' · ', array_filter([
                    $person->getTeamRole()->label(),
                    $person->isVerified() ? 'verified' : 'never signed in',
                    $person->isActive() ? null : 'deactivated',
                    null === $person->getPosition() ? 'no position' : null,
                ])),
                tone: $person->isActive() && !$person->isVerified() ? 'w' : 'd',
            );
        }

        return $lines;
    }
}
