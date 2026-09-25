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
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatBand;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatCell;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatColumn;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatRow;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTint;

/** THE HEAT TABLE'S SHAPE: bands of rows, one cell per column, and five places and an absence. */
#[CoversClass(HeatTable::class)]
#[CoversClass(HeatCell::class)]
final class HeatTableTest extends TestCase
{
    public function testTheTableCountsItsRowsAcrossEveryBand(): void
    {
        $row = new HeatRow('North', 'NO', [HeatCell::figure('4', HeatTint::Leads)]);
        $table = new HeatTable([new HeatColumn('k', 'K')], [new HeatBand('Org-wide', [$row, $row]), new HeatBand('Area', [$row])]);

        self::assertSame(3, $table->rowCount());
        self::assertFalse($table->isEmpty());
        self::assertTrue(new HeatTable([new HeatColumn('k', 'K')], [])->isEmpty());
    }

    /** The five places are the classes the sheet tints; the absence is its own. */
    public function testTheFivePlacesAndTheAbsenceAreTheSheetsClasses(): void
    {
        self::assertSame(['h5', 'h4', 'h3', 'h2', 'h1', 'h0'], array_map(static fn (HeatTint $t): string => $t->value, HeatTint::cases()));
    }

    /** A cell is a figure, a run of states, or a blank — and says which. */
    public function testACellSaysWhichOfTheThreeItIs(): void
    {
        self::assertTrue(HeatCell::blank('no figure')->isBlank());
        self::assertTrue(HeatCell::marks([])->isMarks());
        $figure = HeatCell::figure('12', HeatTint::Mid, sort: 12.0);
        self::assertFalse($figure->isBlank());
        self::assertFalse($figure->isMarks());
        self::assertSame(12.0, $figure->sort);
    }
}
