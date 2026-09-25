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

namespace Uhifadhi\Contracts\Tests\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactPeriodKind;

/**
 * THE PERIODS A FACT IS FILED UNDER: a month, a quarter, a year, each named
 * by a sortable key and each half-open.
 */
#[CoversClass(FactPeriod::class)]
final class FactPeriodTest extends TestCase
{
    public function testAMonthIsKeyedByYearAndMonth(): void
    {
        $month = FactPeriod::month(new \DateTimeImmutable('2026-09-25 14:00:00'));

        self::assertSame('2026-09', $month->key);
        self::assertSame(FactPeriodKind::Month, $month->kind);
        self::assertSame('2026-09-01 00:00:00', $month->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-01 00:00:00', $month->until->format('Y-m-d H:i:s'));
    }

    public function testAQuarterIsTheCalendarQuarter(): void
    {
        $quarter = FactPeriod::quarter(new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertSame('2026-Q3', $quarter->key);
        self::assertSame('2026-07-01', $quarter->from->format('Y-m-d'));
        self::assertSame('2026-10-01', $quarter->until->format('Y-m-d'));
    }

    public function testAYearIsTheCalendarYear(): void
    {
        $year = FactPeriod::year(new \DateTimeImmutable('2026-08-14 10:00:00'));

        self::assertSame('2026', $year->key);
        self::assertSame('2026-01-01', $year->from->format('Y-m-d'));
        self::assertSame('2027-01-01', $year->until->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, FactPeriodKind, string, string}>
     */
    public static function keys(): iterable
    {
        yield 'month' => ['2026-02', FactPeriodKind::Month, '2026-02-01', '2026-03-01'];
        yield 'quarter' => ['2026-Q4', FactPeriodKind::Quarter, '2026-10-01', '2027-01-01'];
        yield 'year' => ['2025', FactPeriodKind::Year, '2025-01-01', '2026-01-01'];
    }

    #[DataProvider('keys')]
    public function testAKeyReadsBackAsItsPeriod(string $key, FactPeriodKind $kind, string $from, string $until): void
    {
        $period = FactPeriod::fromKey($key);

        self::assertSame($key, $period->key);
        self::assertSame($kind, $period->kind);
        self::assertSame($from, $period->from->format('Y-m-d'));
        self::assertSame($until, $period->until->format('Y-m-d'));
    }

    public function testAKeyThatNamesNoPeriodIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FactPeriod::fromKey('2026-13');
    }

    public function testAQuarterIsComposedOfItsThreeMonths(): void
    {
        $months = array_map(
            static fn (FactPeriod $month): string => $month->key,
            FactPeriod::fromKey('2026-Q3')->months(),
        );

        self::assertSame(['2026-07', '2026-08', '2026-09'], $months);
    }

    public function testAYearIsComposedOfItsTwelveMonths(): void
    {
        $months = FactPeriod::fromKey('2026')->months();

        self::assertCount(12, $months);
        self::assertSame('2026-01', $months[0]->key);
        self::assertSame('2026-12', $months[11]->key);
    }

    public function testAMonthIsComposedOfItself(): void
    {
        self::assertSame(['2026-09'], array_map(
            static fn (FactPeriod $month): string => $month->key,
            FactPeriod::fromKey('2026-09')->months(),
        ));
    }

    /** The three periods an instant is open in, month first. */
    public function testAnInstantIsOpenInAMonthAQuarterAndAYear(): void
    {
        $open = array_map(
            static fn (FactPeriod $period): string => $period->key,
            FactPeriod::containing(new \DateTimeImmutable('2026-09-25 14:00:00')),
        );

        self::assertSame(['2026-09', '2026-Q3', '2026'], $open);
    }

    public function testThePeriodBeforeIsOfTheSameKind(): void
    {
        self::assertSame('2026-08', FactPeriod::fromKey('2026-09')->previous()->key);
        self::assertSame('2025-12', FactPeriod::fromKey('2026-01')->previous()->key);
        self::assertSame('2026-Q2', FactPeriod::fromKey('2026-Q3')->previous()->key);
        self::assertSame('2025-Q4', FactPeriod::fromKey('2026-Q1')->previous()->key);
        self::assertSame('2025', FactPeriod::fromKey('2026')->previous()->key);
    }

    /** The months from one to another, both ends included, oldest first. */
    public function testARangeOfMonthsIncludesBothEnds(): void
    {
        $range = array_map(
            static fn (FactPeriod $month): string => $month->key,
            FactPeriod::monthsBetween(new \DateTimeImmutable('2025-11-20'), new \DateTimeImmutable('2026-02-03')),
        );

        self::assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], $range);
    }

    public function testARangeThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FactPeriod::monthsBetween(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-02-01'));
    }

    public function testAPeriodHasEndedOnlyAtOrAfterItsEnd(): void
    {
        $september = FactPeriod::fromKey('2026-09');

        self::assertFalse($september->hasEndedAt(new \DateTimeImmutable('2026-09-30 23:59:59')));
        self::assertTrue($september->hasEndedAt(new \DateTimeImmutable('2026-10-01 00:00:00')));
    }
}
