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

use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\BarFill;
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyMark;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Model\SectionFact;
use Uhifadhi\Bundle\TeamBundle\Model\SectionKpi;
use Uhifadhi\Bundle\TeamBundle\Model\SectionLine;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;

/**
 * WHAT THE DEPARTMENTS SECTION'S OVERVIEW READS, AND OWNS NOTHING OF.
 *
 * Every figure on that page exists somewhere else already — on the register, in
 * Team, or on Performance. The overview is a reading of them, so this service
 * reads and never writes, and the page it feeds has no control that changes a
 * department. That is what makes it safe to open first.
 *
 * ONE PASS OVER THE POSITIONS, NOT ONE PER DEPARTMENT. Walking each
 * department's inverse collection is a lazy load per card and the inverse of a
 * OneToMany is only as true as whoever maintained it; the owning side is the
 * fact, and one query over it answers every figure here.
 *
 * A SEAT IS A POSITION, FOR NOW. The drawn page counts SEATS — a position that
 * can be held by more than one person — and the model has no such number: a
 * position is one post, held or not. Every count below is therefore positions,
 * which is the truth this installation can state; the multi-seat position is a
 * model change that rewrites Team's positions screen too and is not made here.
 */
final readonly class DepartmentSectionOverview
{
    /** How many rows a bounded card shows before it hands the rest to a register. */
    private const int BOUND = 5;

    public function __construct(
        private DepartmentRepository $departments,
        private PositionRepository $positions,
        private DepartmentMembership $membership,
        private UserRepository $users,
        private DepartmentGoalRepository $goals,
        private ModuleCatalogue $catalogue,
    ) {
    }

    /**
     * @return array{
     *     facts: list<SectionFact>,
     *     kpis: list<SectionKpi>,
     *     staffing: RankedBars,
     *     scope: array{orgWide: int, areaLevel: int, bars: RankedBars},
     *     modules: RankedBars,
     *     unattached: list<SectionLine>,
     *     unattachedTotal: int,
     *     vacancies: list<SectionLine>,
     *     vacanciesTotal: int,
     *     goals: list<SectionLine>,
     *     goalsTotal: int,
     * }
     */
    public function read(): array
    {
        $departments = $this->departments->findAllActiveOrdered();
        $held = $this->heldByPosition();

        $orgWide = $areaLevel = 0;
        $areas = [];
        foreach ($departments as $department) {
            if ($department->isOrgLevel()) {
                ++$orgWide;
                continue;
            }

            ++$areaLevel;
            $name = (string) $department->getArea()?->getName();
            $areas[$name] = ($areas[$name] ?? 0) + 1;
        }
        ksort($areas);

        $staffing = $this->staffing($departments, $held);
        $modules = $this->modules($departments);

        $seats = $filled = $people = 0;
        foreach ($staffing as $tally) {
            $seats += $tally['total'];
            $filled += $tally['filled'];
            $people += $tally['people'];
        }

        $attached = $reading = 0;
        foreach ($modules as $names) {
            $attached += \count($names);
            $reading += [] === $names ? 0 : 1;
        }

        $goals = $this->goals->findAllOrdered();

        return [
            'facts' => [
                new SectionFact('Departments', (string) \count($departments), \sprintf('org-wide %d · area-level %d', $orgWide, $areaLevel)),
                new SectionFact('Org-wide', (string) $orgWide, 'every area reads them'),
                new SectionFact('Area-level', (string) $areaLevel, $this->areasPhrase($areas)),
                new SectionFact('Positions', (string) $seats, \sprintf('across %d departments', \count($departments))),
                new SectionFact('People', (string) $people, \sprintf('%d of %d positions filled', $filled, $seats)),
            ],
            /*
             * FOUR TO A ROW, NEVER FIVE (ruled) — and the one dropped is the
             * COUNT OF DEPARTMENTS, because the band directly above this strip
             * opens with exactly that fact and the register below is the list
             * of them. A figure a reader can already read on the same screen
             * is the cheapest of the five to lose.
             */
            'kpis' => [
                new SectionKpi('Positions filled', (string) $filled, \sprintf('of %d', $seats), \sprintf('%d vacant', $seats - $filled)),
                new SectionKpi('People', (string) $people, null, \sprintf('%d in a position', $people)),
                new SectionKpi('Modules attached', (string) $attached, null, \sprintf('%d of %d departments · %d installed', $reading, \count($departments), $this->catalogue->count())),
                new SectionKpi('Goals declared', (string) \count($goals), null, \sprintf('across %d departments', $this->departmentsWithAGoal($goals)), hot: true),
            ],
            'staffing' => self::staffingBars($staffing),
            'scope' => [
                'orgWide' => $orgWide,
                'areaLevel' => $areaLevel,
                'bars' => self::scopeBars($orgWide, $areaLevel, $areas),
            ],
            'modules' => self::moduleBars($modules),
            'unattached' => $this->unattached($departments, $held, self::BOUND),
            'unattachedTotal' => \count($this->unattached($departments, $held, null)),
            'vacancies' => $this->vacancies($held, self::BOUND),
            'vacanciesTotal' => \count($this->vacancies($held, null)),
            'goals' => $this->goalLines($goals, self::BOUND),
            'goalsTotal' => \count($goals),
        ];
    }

    /**
     * How many people hold each position, keyed by the position's id — the one
     * pass the rest of this class reads from.
     *
     * @return array<int, array{position: Position, holders: int}>
     */
    private function heldByPosition(): array
    {
        $held = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $id = $position->getId();
            if (null === $id) {
                continue;
            }

            $held[$id] = [
                'position' => $position,
                'holders' => $this->users->countActiveHoldingAnyPosition([$position]),
            ];
        }

        return $held;
    }

    /**
     * THE HELD-POSITION ROWS THAT BELONG TO ONE DEPARTMENT - which is to say,
     * the ones its members hold. A position belongs to nobody, so there is no
     * column to compare against and the membership answers instead.
     *
     * @param array<int, array{position: Position, holders: int}> $held
     *
     * @return list<array{position: Position, holders: int}>
     */
    private function rowsFor(Department $department, array $held): array
    {
        $mine = [];
        foreach ($this->membership->positionsIn($department) as $position) {
            $row = $held[(int) $position->getId()] ?? null;
            if (null !== $row) {
                $mine[] = $row;
            }
        }

        return $mine;
    }

    /**
     * WHAT EACH DEPARTMENT'S POSITIONS COME TO: how many, how many are held,
     * and how many people hold them.
     *
     * @param list<Department>                                    $departments
     * @param array<int, array{position: Position, holders: int}> $held
     *
     * @return array<string, array{total: int, filled: int, people: int}>
     */
    private function staffing(array $departments, array $held): array
    {
        $tallies = [];
        foreach ($departments as $department) {
            $total = $filled = $people = 0;
            foreach ($this->rowsFor($department, $held) as $row) {
                ++$total;
                $people += $row['holders'];
                if ($row['holders'] > 0) {
                    ++$filled;
                }
            }

            $tallies[(string) $department->getName()] = ['total' => $total, 'filled' => $filled, 'people' => $people];
        }

        return $tallies;
    }

    /**
     * POSITIONS FILLED PER DEPARTMENT, LONGEST FIRST — the ranked bars, the
     * vacant part drawn beside the filled one and both read against the
     * largest department. A department with no position keeps its row and
     * says so rather than drawing an empty bar.
     *
     * @param array<string, array{total: int, filled: int, people: int}> $tallies
     */
    private static function staffingBars(array $tallies): RankedBars
    {
        uksort($tallies, static fn (string|int $a, string|int $b): int => $tallies[$b]['total'] <=> $tallies[$a]['total'] ?: strcmp((string) $a, (string) $b));

        $bars = [];
        foreach ($tallies as $name => $tally) {
            $vacant = $tally['total'] - $tally['filled'];
            $bars[] = 0 === $tally['total']
                ? new Bar(label: (string) $name, value: 0.0, figure: 'no positions', note: ' · nothing filed here yet')
                : new Bar(
                    label: (string) $name,
                    value: (float) $tally['filled'],
                    rest: (float) $vacant,
                    figure: (string) $tally['filled'],
                    note: \sprintf('/%d · %d vacant', $tally['total'], $vacant),
                );
        }

        return new RankedBars(
            $bars,
            key: new DotKey([new KeyEntry('filled'), new KeyEntry('vacant', KeyMark::Rest), new KeyEntry('scaled to the largest department', null)]),
            empty: 'No department yet.',
        );
    }

    /**
     * WHERE EACH DEPARTMENT IS READ: the org-wide bucket every area inherits,
     * then each area's own — every area row read against all the area-level
     * departments there are, so the rows add up to the whole.
     *
     * @param array<string, int> $areas
     */
    private static function scopeBars(int $orgWide, int $areaLevel, array $areas): RankedBars
    {
        $bars = [new Bar(label: 'Org-wide', value: (float) $orgWide, figure: (string) $orgWide, note: ' · every area', of: (float) $orgWide)];
        foreach ($areas as $name => $count) {
            $bars[] = new Bar(label: (string) $name, value: (float) $count, figure: (string) $count, note: ' · its own', quiet: false, of: (float) $areaLevel);
        }

        return new RankedBars(
            $bars,
            BarFill::Soft,
            key: new DotKey([new KeyEntry('Org-wide — every area reads it'), new KeyEntry('Area-level — one area only', KeyMark::Soft)]),
        );
    }

    /**
     * WHICH MODULES EACH DEPARTMENT READS, sorted, by department.
     *
     * @param list<Department> $departments
     *
     * @return array<string, list<string>>
     */
    private function modules(array $departments): array
    {
        $read = [];
        foreach ($departments as $department) {
            $names = [];
            foreach ($department->getModules() as $module) {
                // A module row not yet flushed has neither, and names nothing.
                $name = $module->getName() ?? $module->getSlug();
                if (null !== $name) {
                    $names[] = $name;
                }
            }
            sort($names);

            $read[(string) $department->getName()] = $names;
        }

        return $read;
    }

    /**
     * HOW MANY MODULES EACH DEPARTMENT READS, most first, read against the
     * department that reads the most.
     *
     * @param array<string, list<string>> $read
     */
    private static function moduleBars(array $read): RankedBars
    {
        uksort($read, static fn (string|int $a, string|int $b): int => \count($read[$b]) <=> \count($read[$a]) ?: strcmp((string) $a, (string) $b));

        $bars = [];
        foreach ($read as $name => $names) {
            $bars[] = [] === $names
                ? new Bar(label: (string) $name, value: 0.0, note: 'reads no module')
                : new Bar(label: (string) $name, value: (float) \count($names), figure: (string) \count($names), note: ' · '.implode(', ', $names));
        }

        return new RankedBars($bars, BarFill::Soft, empty: 'No department yet.');
    }

    /**
     * THE DEPARTMENTS THAT READ NO MODULE — a department with none has people
     * and positions but no figure of its own, so it never reaches Performance.
     *
     * @param list<Department>                                    $departments
     * @param array<int, array{position: Position, holders: int}> $held
     *
     * @return list<SectionLine>
     */
    private function unattached(array $departments, array $held, ?int $bound): array
    {
        $lines = [];
        foreach ($departments as $department) {
            if (0 !== $department->getModules()->count()) {
                continue;
            }

            $total = $filled = 0;
            foreach ($this->rowsFor($department, $held) as $row) {
                ++$total;
                if ($row['holders'] > 0) {
                    ++$filled;
                }
            }

            $lines[] = new SectionLine(
                label: (string) $department->getName(),
                uuid: $department->getUuidString(),
                note: \sprintf(
                    '%s · %d of %d positions filled',
                    $department->isOrgLevel() ? 'org-wide' : (string) $department->getArea()?->getName(),
                    $filled,
                    $total,
                ),
            );
        }

        return null === $bound ? $lines : \array_slice($lines, 0, $bound);
    }

    /**
     * THE POSITIONS NOBODY HOLDS, widest gap first — longest vacant at the top,
     * because that is the one a reader is deciding about.
     *
     * @param array<int, array{position: Position, holders: int}> $held
     *
     * @return list<SectionLine>
     */
    private function vacancies(array $held, ?int $bound): array
    {
        $vacant = [];
        foreach ($held as $row) {
            if ($row['holders'] > 0) {
                continue;
            }

            $vacant[] = $row['position'];
        }

        usort($vacant, static fn (Position $a, Position $b): int => ($a->getVacantSince()?->getTimestamp() ?? \PHP_INT_MAX) <=> ($b->getVacantSince()?->getTimestamp() ?? \PHP_INT_MAX));

        $lines = array_map(
            static fn (Position $position): SectionLine => new SectionLine(
                label: (string) $position->getName(),
                uuid: null,
                note: 'nobody holds it',
                tone: 'w',
            ),
            $vacant,
        );

        return null === $bound ? $lines : \array_slice($lines, 0, $bound);
    }

    /**
     * @param list<\Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal> $goals
     *
     * @return list<SectionLine>
     */
    private function goalLines(array $goals, int $bound): array
    {
        $lines = [];
        foreach (\array_slice($goals, 0, $bound) as $goal) {
            $lines[] = new SectionLine(
                label: \sprintf('%s · %s', (string) $goal->getDepartment()?->getName(), $goal->getStatement()),
                uuid: null,
                note: \sprintf('%s %s', self::trim($goal->getTarget()), $goal->getUnit()),
            );
        }

        return $lines;
    }

    /** @param array<string, int> $areas */
    private function areasPhrase(array $areas): string
    {
        if ([] === $areas) {
            return 'no area carries one of its own';
        }

        $parts = [];
        foreach ($areas as $name => $count) {
            $parts[] = \sprintf('%s %d', $name, $count);
        }

        return implode(' · ', $parts);
    }

    /** @param list<\Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal> $goals */
    private function departmentsWithAGoal(array $goals): int
    {
        $seen = [];
        foreach ($goals as $goal) {
            $seen[(string) $goal->getDepartment()?->getUuidString()] = true;
        }

        return \count($seen);
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
