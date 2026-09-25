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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactReader;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Contracts\Facts\FactSubject;

/**
 * THE LEDGER: one row per subject, figure and period, replaced by the next
 * run, and read as a number with the time it is true as of.
 */
#[CoversClass(FigureFactRepository::class)]
#[CoversClass(FactReader::class)]
final class FigureFactLedgerTest extends FactsTestCase
{
    private const string NORTH = SurveyFactProvider::NORTH;
    private const string SOUTH = SurveyFactProvider::SOUTH;

    public function testAWrittenFigureReadsBackWithItsTime(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-09', 0.54, '2026-09-25 13:00:00');

        $fact = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-09');

        self::assertNotNull($fact);
        self::assertSame(0.54, $fact->value);
        self::assertSame('2026-09-25 13:00:00', $fact->computedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'));
        self::assertFalse($fact->isFinal());
    }

    /** The second run of a period replaces the first: one row, the newer value, the newer time. */
    public function testWritingTheSamePeriodAgainReplacesTheRow(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-09', 0.54, '2026-09-25 13:00:00');
        $this->write(self::NORTH, 'surveys.share', '2026-09', 0.58, '2026-09-25 14:00:00');

        self::assertSame(1, $this->rows());

        $fact = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-09');
        self::assertNotNull($fact);
        self::assertSame(0.58, $fact->value);
        self::assertSame('14:00', $fact->computedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i'));
    }

    /** Unknown is stored as unknown, and read back as unknown — not as a missing row, not as zero. */
    public function testAnUnknownFigureIsKeptAsUnknown(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-09', null, '2026-09-25 13:00:00');

        $fact = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-09');

        self::assertNotNull($fact);
        self::assertNull($fact->value);
    }

    public function testAFigureNobodyComputedReadsAsNull(): void
    {
        self::assertNull($this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-09'));
    }

    public function testAPageReadsItsFiguresInOneBatch(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-09', 0.5, '2026-09-25 13:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-09', 900.0, '2026-09-25 13:00:00');
        $this->write(self::SOUTH, 'surveys.share', '2026-09', 0.25, '2026-09-25 13:00:00');
        $this->write(self::SOUTH, 'surveys.share', '2026-08', 0.99, '2026-09-01 02:00:00');

        $batch = $this->reader()->batch(FactSubject::AREA, [self::NORTH, self::SOUTH], ['surveys.share', 'surveys.metres'], '2026-09');

        self::assertSame(0.5, $batch[self::NORTH]['surveys.share']->value);
        self::assertSame(900.0, $batch[self::NORTH]['surveys.metres']->value);
        self::assertSame(0.25, $batch[self::SOUTH]['surveys.share']->value);
        self::assertArrayNotHasKey('surveys.metres', $batch[self::SOUTH], 'a figure nobody computed is absent');
    }

    /** An additive figure's quarter is its months added up, and is as old as its oldest month. */
    public function testAnAdditiveQuarterIsTheSumOfItsMonths(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-07', 100.0, '2026-08-01 02:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-08', 200.0, '2026-09-01 02:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-09', 50.0, '2026-09-25 13:00:00');

        $quarter = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.metres', '2026-Q3');

        self::assertNotNull($quarter);
        self::assertSame(350.0, $quarter->value);
        self::assertSame('2026-Q3', $quarter->periodKey);
        self::assertSame('2026-09-25 13:00', $quarter->asOf->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'));
        self::assertFalse($quarter->isFinal());
    }

    /** A year is at most twelve month rows; one month in, it is that month. */
    public function testAnAdditiveYearIsTheSumOfItsMonthsSoFar(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-01', 10.0, '2026-02-01 02:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-02', 20.0, '2026-02-20 13:00:00');

        $year = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.metres', '2026');

        self::assertNotNull($year);
        self::assertSame(30.0, $year->value);
    }

    /**
     * A MISSING MONTH ENDS THE SUM. The quarter reads as its months up to the
     * gap and says so in its time, rather than presenting a quarter with a
     * month missing from the middle as the whole quarter.
     */
    public function testAGapEndsTheSum(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-07', 100.0, '2026-08-01 02:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-09', 50.0, '2026-09-25 13:00:00');

        $quarter = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.metres', '2026-Q3');

        self::assertNotNull($quarter);
        self::assertSame(100.0, $quarter->value);
        self::assertSame('2026-08-01 00:00', $quarter->asOf->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'));
    }

    public function testAQuarterWhoseFirstMonthWasNeverComputedHasNoFigure(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-08', 200.0, '2026-09-01 02:00:00');

        self::assertNull($this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.metres', '2026-Q3'));
    }

    /** An unknown month makes the sum unknown: a sum over a month nobody measured is not a figure. */
    public function testAnUnknownMonthMakesTheSumUnknown(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-07', 100.0, '2026-08-01 02:00:00');
        $this->write(self::NORTH, 'surveys.metres', '2026-08', null, '2026-09-01 02:00:00');

        $quarter = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.metres', '2026-Q3');

        self::assertNotNull($quarter);
        self::assertNull($quarter->value);
    }

    /** A share is not its months added up: its quarter is its own row, and without one there is no figure. */
    public function testANonAdditiveQuarterIsItsOwnRow(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-07', 0.5, '2026-08-01 02:00:00');
        $this->write(self::NORTH, 'surveys.share', '2026-08', 0.5, '2026-09-01 02:00:00');

        self::assertNull($this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-Q3'));

        $this->write(self::NORTH, 'surveys.share', '2026-Q3', 0.7, '2026-09-25 13:00:00');

        $quarter = $this->reader()->latest(FactSubject::AREA, self::NORTH, 'surveys.share', '2026-Q3');
        self::assertNotNull($quarter);
        self::assertSame(0.7, $quarter->value);
    }

    public function testABatchComposesAnAdditiveQuarterToo(): void
    {
        $this->write(self::NORTH, 'surveys.metres', '2026-07', 100.0, '2026-08-01 02:00:00');
        $this->write(self::SOUTH, 'surveys.metres', '2026-07', 300.0, '2026-08-01 02:00:00');
        $this->write(self::SOUTH, 'surveys.metres', '2026-08', 300.0, '2026-09-01 02:00:00');
        $this->write(self::SOUTH, 'surveys.share', '2026-Q3', 0.1, '2026-09-25 13:00:00');

        $batch = $this->reader()->batch(FactSubject::AREA, [self::NORTH, self::SOUTH], ['surveys.metres', 'surveys.share'], '2026-Q3');

        self::assertSame(100.0, $batch[self::NORTH]['surveys.metres']->value);
        self::assertSame(600.0, $batch[self::SOUTH]['surveys.metres']->value);
        self::assertSame(0.1, $batch[self::SOUTH]['surveys.share']->value);
        self::assertArrayNotHasKey('surveys.share', $batch[self::NORTH]);
    }

    public function testAnEmptyBatchAsksNothing(): void
    {
        self::assertSame([], $this->reader()->batch(FactSubject::AREA, [], ['surveys.metres'], '2026-09'));
    }

    /** The time a figure was last computed for a period, across its subjects — what the closing pass reads. */
    public function testTheLedgerKnowsWhenAFigureWasLastComputed(): void
    {
        $this->write(self::NORTH, 'surveys.share', '2026-08', 0.5, '2026-08-31 20:00:00');
        $this->write(self::SOUTH, 'surveys.share', '2026-08', 0.5, '2026-08-31 19:00:00');

        $last = $this->ledger()->getLastComputedAt(['surveys.share', 'surveys.metres'], '2026-08');

        self::assertNotNull($last);
        self::assertSame('2026-08-31 20:00', $last->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'));
        self::assertNull($this->ledger()->getLastComputedAt(['surveys.share'], '2026-07'));
    }
}
