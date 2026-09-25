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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentPeriodFigure;
use Uhifadhi\Bundle\TeamBundle\Entity\InstallationPeriodFigure;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentPeriodFigureRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\InstallationPeriodFigureRepository;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Kpi\FigurePeriod;

/**
 * WHAT THE FIGURES WERE, PERIOD BY PERIOD — the only thing in the core that
 * remembers.
 *
 * A CLOSED PERIOD CANNOT BE RECOMPUTED, so every movement on the
 * performance page is a comparison against something written down while it
 * was still true. This is the writing and the reading of it, in one place,
 * so the snapshot command and the page cannot disagree about what a period
 * is called or where its figures live.
 *
 * PERIOD KEYS ARE SORTABLE STRINGS — `2026-08`, `2026-Q3`, `2026` — minted by
 * {@see FactPeriod}, the one place the history and the facts ledger both
 * take them from. A page that built its own would be one typo away from a
 * history it cannot find.
 *
 * THE OPEN PERIOD IS NEVER WRITTEN HERE, and a figure the worker filed for
 * it on the facts ledger (subject: the department, figure: the same key) is
 * what {@see valueAt()} and {@see runFor()} read where no row was written.
 * A written row always wins: it is what the period was when it closed.
 */
final readonly class PerformanceHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DepartmentPeriodFigureRepository $figures,
        private InstallationPeriodFigureRepository $installation,
        private ?FactReaderInterface $facts = null,
    ) {
    }

    /** The month an instant falls in, as a key. */
    public static function monthKey(\DateTimeImmutable $when): string
    {
        return FactPeriod::month($when)->key;
    }

    /** The quarter an instant falls in — the calendar's, not a fiscal year's. */
    public static function quarterKey(\DateTimeImmutable $when): string
    {
        return FactPeriod::quarter($when)->key;
    }

    public static function yearKey(\DateTimeImmutable $when): string
    {
        return FactPeriod::year($when)->key;
    }

    /**
     * THE KEY A WHOLE PERIOD IS STORED UNDER, chosen by its LENGTH.
     *
     * THE BUG THIS EXISTS FOR: every topic used to read its comparison
     * from `monthKey($period->from->modify('-1 month'))`, which is the
     * month before a month — and the month before a QUARTER, and the
     * month before a YEAR. A quarter's movement was measured against
     * one month and was wrong by two.
     *
     * A QUARTER WITH NO QUARTER ROW READS AS NO HISTORY, which is the
     * honest answer: the figure was never written down at that length,
     * and a page that said "no history yet" is right where one that
     * printed a month's number in a quarter's place was not.
     */
    public static function keyFor(FigurePeriod $period): string
    {
        $days = (int) $period->from->diff($period->until)->days;

        return match (true) {
            $days > 200 => self::yearKey($period->from),
            $days > 45 => self::quarterKey($period->from),
            default => self::monthKey($period->from),
        };
    }

    /**
     * THE RUN OF MONTHS ENDING AT ONE, oldest first — what a sparkline is
     * drawn over and what "the same period last year" is found in.
     *
     * @return list<string>
     */
    public static function monthsEndingAt(\DateTimeImmutable $last, int $count): array
    {
        $keys = [];
        for ($back = $count - 1; $back >= 0; --$back) {
            $keys[] = self::monthKey($last->modify(\sprintf('-%d months', $back)));
        }

        return $keys;
    }

    /**
     * WRITE ONE FIGURE DOWN. Running the snapshot twice for a period
     * CORRECTS the history rather than doubling it: a scheduled run and a
     * hand-run are the same act, and the second one is usually the one
     * somebody wanted.
     */
    public function record(Department $department, string $periodKey, string $figureKey, ?float $value): void
    {
        $figure = $this->figures->findOne($department, $periodKey, $figureKey)
            ?? new DepartmentPeriodFigure()
                ->setDepartment($department)
                ->setPeriodKey($periodKey)
                ->setFigureKey($figureKey);

        $figure->setValue($value)->setWrittenAt(new \DateTimeImmutable());

        $this->entityManager->persist($figure);
        $this->entityManager->flush();
    }

    /**
     * THE SAME WRITING, ONE SCOPE UP — a figure that belongs to the whole
     * installation rather than to a department.
     *
     * THREE OF THE TEAM OVERVIEW'S FIGURES HAVE NO DEPARTMENT TO BELONG TO:
     * an account with no position is in none, a posting is the area's, and
     * the tiers are the installation's. Summing the department rows would
     * drop exactly the loose bucket the overview draws as its own line.
     */
    public function recordForInstallation(string $periodKey, string $figureKey, ?float $value): void
    {
        $figure = $this->installation->findOne($periodKey, $figureKey)
            ?? new InstallationPeriodFigure()
                ->setPeriodKey($periodKey)
                ->setFigureKey($figureKey);

        $figure->setValue($value)->setWrittenAt(new \DateTimeImmutable());

        $this->entityManager->persist($figure);
        $this->entityManager->flush();
    }

    /**
     * WHAT THE INSTALLATION'S FIGURES WERE IN ONE CLOSED PERIOD, in one read.
     *
     * @return array<string, float|null> keyed by figure; a figure nobody wrote is absent
     */
    public function installationAt(string $periodKey): array
    {
        return $this->installation->of($periodKey);
    }

    /**
     * What one figure was, or — for a period nobody wrote down — what the
     * facts ledger holds for it; null where neither has it.
     */
    public function valueAt(Department $department, string $figureKey, string $periodKey): ?float
    {
        $written = $this->figures->findOne($department, $periodKey, $figureKey);
        if (null !== $written) {
            return $written->getValue();
        }

        $uuid = $department->getUuidString();
        if (null === $this->facts || null === $uuid) {
            return null;
        }

        return $this->facts->latest(FactSubject::DEPARTMENT, $uuid, $figureKey, $periodKey)?->value;
    }

    /**
     * ONE FIGURE OVER SEVERAL PERIODS, KEEPING ITS HOLES. A run that
     * quietly closed up over a month nobody wrote would draw a line that
     * never happened.
     *
     * @param list<string> $periodKeys
     *
     * @return array<string, float|null>
     */
    public function runFor(Department $department, string $figureKey, array $periodKeys): array
    {
        $written = $this->figures->run($department, $figureKey, $periodKeys);

        $run = [];
        foreach ($periodKeys as $key) {
            $run[$key] = \array_key_exists($key, $written) ? $written[$key] : $this->filed($department, $figureKey, $key);
        }

        return $run;
    }

    /** What the facts ledger holds for a period nobody wrote down, one read per hole. */
    private function filed(Department $department, string $figureKey, string $periodKey): ?float
    {
        $uuid = $department->getUuidString();
        if (null === $this->facts || null === $uuid) {
            return null;
        }

        return $this->facts->latest(FactSubject::DEPARTMENT, $uuid, $figureKey, $periodKey)?->value;
    }

    /**
     * ONE FIGURE, ONE PERIOD, EVERY DEPARTMENT — a matrix column in one read.
     *
     * @return array<string, float|null> department uuid to what was written
     */
    public function acrossDepartments(string $figureKey, string $periodKey): array
    {
        return $this->figures->acrossDepartments($figureKey, $periodKey);
    }

    /** Whether this installation has written anything down at all. */
    public function isEmpty(): bool
    {
        return 0 === $this->figures->count([]);
    }
}
