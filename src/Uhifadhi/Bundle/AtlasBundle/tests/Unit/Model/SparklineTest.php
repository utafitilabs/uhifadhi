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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;

/**
 * THE SPARKLINE'S GEOMETRY, stated once for every surface that draws one.
 *
 * A caller hands the history and says what the movement means; where each
 * point lands in the box, and where the line breaks, is the atlas's.
 */
#[CoversClass(Sparkline::class)]
#[CoversClass(SparkSize::class)]
final class SparklineTest extends TestCase
{
    /** The line runs corner to corner of its box, three pixels clear of the top and the bottom. */
    public function testALineRunsTheWidthOfItsBoxInsideTheInset(): void
    {
        $spark = new Sparkline([1.0, 2.0, 3.0], SparkTone::Good, SparkSize::Cell);

        self::assertSame(['0.0,15.0 35.0,9.0 70.0,3.0'], $spark->runs());
    }

    /** The card's box is wider and taller, over the same rule. */
    public function testTheCardsBoxIsItsOwnSize(): void
    {
        $spark = new Sparkline([1.0, 3.0], SparkTone::Flat, SparkSize::Card);

        self::assertSame(['0.0,23.0 100.0,3.0'], $spark->runs());
        self::assertSame(100.0, SparkSize::Card->width());
        self::assertSame(26.0, SparkSize::Card->height());
        self::assertSame(70.0, SparkSize::Cell->width());
        self::assertSame(18.0, SparkSize::Cell->height());
    }

    /**
     * A HOLE IS A HOLE. A period nobody wrote down breaks the line; it does
     * not dip it to nought.
     */
    public function testAPeriodNobodyWroteBreaksTheLineIntoRuns(): void
    {
        $spark = new Sparkline([1.0, 2.0, null, 2.0, 3.0], SparkTone::Bad, SparkSize::Cell);

        self::assertSame(['0.0,15.0 17.5,9.0', '52.5,9.0 70.0,3.0'], $spark->runs());
    }

    /** A lone reading between two holes is no line at all. */
    public function testASingleReadingBetweenHolesDrawsNothing(): void
    {
        $spark = new Sparkline([1.0, 2.0, null, 5.0, null], SparkTone::Flat, SparkSize::Cell);

        self::assertSame(['0.0,15.0 17.5,12.0'], $spark->runs());
    }

    /** A flat series sits on the baseline rather than dividing by nothing. */
    public function testAFlatSeriesSitsOnTheBaseline(): void
    {
        self::assertSame(['0.0,15.0 70.0,15.0'], new Sparkline([4.0, 4.0], SparkTone::Flat, SparkSize::Cell)->runs());
    }

    /** Fewer than two readings is not a line, and the sparkline says it is empty. */
    public function testFewerThanTwoReadingsIsEmpty(): void
    {
        self::assertTrue(new Sparkline([null, 4.0, null])->isEmpty());
        self::assertSame([], new Sparkline([4.0])->runs());
        self::assertFalse(new Sparkline([3.0, 4.0])->isEmpty());
    }

    /** The tone is a class the sheet paints, never a color. */
    public function testTheToneIsTheClassItsPolylineWears(): void
    {
        self::assertSame('up', SparkTone::Good->value);
        self::assertSame('dn', SparkTone::Bad->value);
        self::assertSame('fl', SparkTone::Flat->value);
        self::assertSame('sk', SparkSize::Card->value);
        self::assertSame('spark', SparkSize::Cell->value);
    }
}
