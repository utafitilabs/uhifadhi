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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Enum\IntervalUnit;

/**
 * A NUMBER AND A UNIT, stored as minutes and offered back in the largest unit
 * the stored number is a whole count of.
 */
#[CoversClass(IntervalUnit::class)]
final class IntervalUnitTest extends TestCase
{
    public function testEachUnitStatesItsMinutes(): void
    {
        self::assertSame(1, IntervalUnit::Minutes->minutes());
        self::assertSame(60, IntervalUnit::Hours->minutes());
        self::assertSame(1440, IntervalUnit::Days->minutes());
    }

    public function testATypedPairIsMinutes(): void
    {
        self::assertSame(120, IntervalUnit::Hours->toMinutes(2.0));
        self::assertSame(90, IntervalUnit::Hours->toMinutes(1.5));
        self::assertSame(45, IntervalUnit::Minutes->toMinutes(45.0));
    }

    public function testStoredMinutesAreOfferedInTheLargestWholeUnit(): void
    {
        self::assertSame(IntervalUnit::Minutes, IntervalUnit::largestWholeFor(30));
        self::assertSame(IntervalUnit::Minutes, IntervalUnit::largestWholeFor(90));
        self::assertSame(IntervalUnit::Hours, IntervalUnit::largestWholeFor(120));
        self::assertSame(IntervalUnit::Days, IntervalUnit::largestWholeFor(2880));
    }

    public function testStoredMinutesAreSaidInThatUnit(): void
    {
        self::assertSame('30 minutes', IntervalUnit::say(30));
        self::assertSame('1 hour', IntervalUnit::say(60));
        self::assertSame('2 hours', IntervalUnit::say(120));
        self::assertSame('1 day', IntervalUnit::say(1440));
    }

    public function testTheLabelIsTheWordTheSelectPrints(): void
    {
        self::assertSame('minutes', IntervalUnit::Minutes->label());
        self::assertSame(['minutes', 'hours', 'days'], array_map(static fn (IntervalUnit $u): string => $u->value, IntervalUnit::cases()));
    }
}
