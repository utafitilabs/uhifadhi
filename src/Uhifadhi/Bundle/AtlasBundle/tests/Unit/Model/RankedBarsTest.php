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
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;

/**
 * THE RANKED BARS' WIDTHS, worked out once for every card that draws them.
 */
#[CoversClass(RankedBars::class)]
#[CoversClass(Bar::class)]
final class RankedBarsTest extends TestCase
{
    /** Every row is scaled against the largest row's whole, so the longest bar runs the track. */
    public function testEveryRowIsScaledAgainstTheLargestRow(): void
    {
        $rows = new RankedBars([new Bar('North', 31.0), new Bar('South', 9.0)])->rows();

        self::assertSame(100.0, $rows[0]->fill);
        self::assertSame(29.0, $rows[1]->fill);
    }

    /** A two-part bar: the filled part and the rest, both against the largest whole. */
    public function testTheRestIsDrawnBesideTheFillAgainstTheSameScale(): void
    {
        $rows = new RankedBars([new Bar('North', 31.0, rest: 4.0), new Bar('South', 9.0, rest: 2.0)])->rows();

        self::assertSame(88.6, $rows[0]->fill);
        self::assertSame(11.4, $rows[0]->rest);
        self::assertSame(25.7, $rows[1]->fill);
        self::assertSame(5.7, $rows[1]->rest);
    }

    /** A row may state its own whole, and is then read against it rather than against the others. */
    public function testARowThatStatesItsOwnWholeIsReadAgainstIt(): void
    {
        $rows = new RankedBars([new Bar('Everywhere', 6.0, of: 6.0), new Bar('North', 3.0, of: 3.0), new Bar('South', 0.0, of: 3.0)])->rows();

        self::assertSame(100.0, $rows[0]->fill);
        self::assertSame(100.0, $rows[1]->fill);
        self::assertSame(0.0, $rows[2]->fill);
    }

    /** A row with nothing in it is dimmed; a row may also be dimmed by the caller whatever it holds. */
    public function testANoughtIsQuietAndACallerMayQuietAnyRow(): void
    {
        self::assertTrue(new Bar('Empty', 0.0)->isQuiet());
        self::assertFalse(new Bar('Full', 2.0)->isQuiet());
        self::assertTrue(new Bar('Unplaced', 1.0, quiet: true)->isQuiet());
    }

    /** Nothing anywhere divides by nothing: every width is nought. */
    public function testAllNoughtsScaleToNothing(): void
    {
        $rows = new RankedBars([new Bar('A', 0.0), new Bar('B', 0.0)])->rows();

        self::assertSame(0.0, $rows[0]->fill);
        self::assertSame(0.0, $rows[1]->rest);
    }

    /** No row is no bars, and the card says so in its own words. */
    public function testNoRowIsEmpty(): void
    {
        self::assertTrue(new RankedBars([], empty: 'Nobody yet.')->isEmpty());
        self::assertFalse(new RankedBars([new Bar('A', 1.0)])->isEmpty());
    }

    /** A negative reading is not a length. */
    public function testABarRefusesANegativeReading(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Bar('A', -1.0);
    }
}
