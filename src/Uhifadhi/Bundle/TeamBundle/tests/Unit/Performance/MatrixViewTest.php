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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatCellKind;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixViewBuilder;
use Uhifadhi\Contracts\Performance\CellMark;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * WHAT THE TEMPLATE IS HANDED — every decision already made.
 *
 * A Twig template that decided anything would decide it differently on
 * the next page, so the arithmetic, the words for the three emptinesses
 * and the tone of every chip are settled here and the template only
 * writes them down.
 */
#[CoversClass(MatrixViewBuilder::class)]
final class MatrixViewTest extends TestCase
{
    /**
     * THE BANDS ARE THE ROWS' OWN, IN THE ORDER THEY ARRIVED. The
     * provider decided which department comes first and the page does
     * not re-sort it.
     */
    public function testTheRowsGroupIntoTheirBandsInTheProvidersOrder(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('cov', 'Coverage')],
            [
                new MatrixRow('a', 'Ecology', ['cov' => new MatrixCell(1.0)], 'Org-wide', 'EC'),
                new MatrixRow('b', 'Vets', ['cov' => new MatrixCell(2.0)], 'Northreach', 'VS'),
                new MatrixRow('c', 'Tourism', ['cov' => new MatrixCell(3.0)], 'Org-wide', 'TO'),
            ],
        ));

        self::assertSame(['Org-wide', 'Northreach'], array_map(
            static fn (object $band): string => $band->name,
            $view->bands,
        ));
        self::assertSame(['Ecology', 'Tourism'], array_map(
            static fn (object $row): string => $row->name,
            $view->bands[0]->rows,
        ));
        self::assertSame(2, $view->bands[0]->count());
    }

    /**
     * WHAT A ROW AND A BAND SAY ABOUT THEMSELVES IS THE TOPIC'S WORD.
     * "3 positions · org-wide" under a department, and "each reads every
     * area · 32 of 40 seats filled" under a band: the host cannot write
     * either, because each is a sentence about the topic's own figures.
     */
    public function testTheWordsARowAndABandSayAboutThemselvesTravelWithThem(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('cov', 'Coverage')],
            [new MatrixRow('a', 'Ecology', ['cov' => new MatrixCell(1.0)], 'Org-wide', 'EC', null, '3 positions')],
            bandNotes: ['Org-wide' => 'each reads every area'],
        ));

        self::assertSame('3 positions', $view->bands[0]->rows[0]->note);
        self::assertSame('each reads every area', $view->bands[0]->note);
    }

    /** A cell is drawn in every column, in the columns' order, even where the row published none. */
    public function testEveryRowHasACellInEveryColumnInTheColumnsOrder(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('a', 'A'), new MatrixColumn('b', 'B')],
            [new MatrixRow('x', 'X', ['b' => new MatrixCell(2.0)], 'Org-wide')],
        ));

        $cells = $view->bands[0]->rows[0]->cells;
        self::assertCount(2, $cells);
        self::assertSame(HeatCellKind::Blank, $cells[0]->kind);
        self::assertSame(HeatCellKind::Figure, $cells[1]->kind);
    }

    /**
     * THREE EMPTINESSES, THREE DIFFERENT MARKS. Collapsing any two of
     * them turns a department that was never asked into one that scored
     * nothing.
     */
    public function testTheColumnThatIsNotThisDepartmentsSaysSoAndNotNothing(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('pat', 'Patrols')],
            [
                new MatrixRow('x', 'X', ['pat' => MatrixCell::notMine()], 'Org-wide'),
                new MatrixRow('y', 'Y', ['pat' => new MatrixCell()], 'Org-wide'),
                new MatrixRow('z', 'Z', ['pat' => new MatrixCell(0.0)], 'Org-wide'),
            ],
        ));

        [$notMine, $noFigure, $nought] = $view->bands[0]->rows;

        self::assertSame('not its topic', $notMine->cells[0]->word);
        self::assertStringContainsString('not one of this', $notMine->cells[0]->title);

        self::assertSame('no figure', $noFigure->cells[0]->word);
        self::assertNotSame($notMine->cells[0]->title, $noFigure->cells[0]->title);

        // A real nought is a measurement, and it is drawn as one.
        self::assertSame(HeatCellKind::Figure, $nought->cells[0]->kind);
        self::assertSame('0', $nought->cells[0]->figure);
    }

    /** The figure is written the way every plate writes one, and its unit rides with it. */
    public function testAFigureIsPrintedWithItsThousandsAndItsUnit(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('rec', 'Records', unit: 'of 84')],
            [new MatrixRow('x', 'X', ['rec' => new MatrixCell(12435.0)], 'Org-wide')],
        ));

        self::assertSame('12,435', $view->bands[0]->rows[0]->cells[0]->figure);
        self::assertSame('of 84', $view->bands[0]->rows[0]->cells[0]->unit);
    }

    /** A figure that is not whole keeps the one decimal that made it worth publishing. */
    public function testAFractionKeepsItsDecimalAndAWholeNumberDoesNot(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('d', 'Days')],
            [new MatrixRow('x', 'X', ['d' => new MatrixCell(6.24)], 'Org-wide')],
        ));

        self::assertSame('6.2', $view->bands[0]->rows[0]->cells[0]->figure);
    }

    /**
     * A MOVEMENT IS COLOURED BY THE COLUMN'S POLARITY AND NOT BY ITS
     * SIGN. More days to settle is a worse month, and a page that
     * painted every rise green would congratulate a department for it.
     */
    public function testARiseInAColumnThatCountsDownwardsReadsBadly(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('d', 'Days', polarity: ColumnPolarity::Down)],
            [new MatrixRow('x', 'X', ['d' => new MatrixCell(9.0, delta: 2.0)], 'Org-wide')],
        ));

        $cell = $view->bands[0]->rows[0]->cells[0];
        self::assertSame('+2', $cell->delta);
        self::assertSame('bad', $cell->deltaTone);
    }

    /** A column that makes no claim states the movement and claims nothing about it. */
    public function testAMovementInAColumnWithNoPolarityIsNotJudged(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('p', 'Positions')],
            [new MatrixRow('x', 'X', ['p' => new MatrixCell(9.0, delta: -2.0)], 'Org-wide')],
        ));

        $cell = $view->bands[0]->rows[0]->cells[0];
        self::assertSame("\u{2212}2", $cell->delta);
        self::assertSame('', $cell->deltaTone);
    }

    /**
     * A DELTA NOBODY CAN COMPUTE IS NOT A NOUGHT. No period has been
     * written down yet, so there is no chip — not a chip saying "±0",
     * which is a claim that nothing changed.
     */
    public function testAFigureWithNoHistoryWearsNoMovementChipAtAll(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('p', 'Positions')],
            [new MatrixRow('x', 'X', ['p' => new MatrixCell(9.0)], 'Org-wide')],
        ));

        self::assertSame('', $view->bands[0]->rows[0]->cells[0]->delta);
    }

    /** A figure that did not move says so, without a direction. */
    public function testAFigureThatDidNotMoveSaysSo(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('p', 'Positions', polarity: ColumnPolarity::Up)],
            [new MatrixRow('x', 'X', ['p' => new MatrixCell(9.0, delta: 0.0)], 'Org-wide')],
        ));

        $cell = $view->bands[0]->rows[0]->cells[0];
        self::assertSame('no change', $cell->delta);
        self::assertSame('flat', $cell->deltaTone);
    }

    /**
     * THE SPARKLINE IS DRAWN OVER THE HISTORY, AND A HOLE IS A HOLE.
     * A month nobody wrote down is not a nought on the line: the line
     * stops and starts again, so the gap is visible instead of drawn
     * through.
     */
    public function testAHoleInTheHistoryBreaksTheLineRatherThanFillingIt(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('p', 'P')],
            [new MatrixRow('x', 'X', ['p' => new MatrixCell(4.0, history: [1.0, 2.0, null, 3.0, 4.0])], 'Org-wide')],
        ));

        $spark = $view->bands[0]->rows[0]->cells[0]->spark;
        self::assertNotNull($spark);
        self::assertCount(2, $spark->runs());
        // The atlas's line, at the matrix cell's size.
        self::assertSame(SparkSize::Cell, $spark->size);
    }

    /** One reading cannot draw a line, so it draws none. */
    public function testAHistoryTooShortToDrawALineDrawsNothing(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('p', 'P')],
            [new MatrixRow('x', 'X', ['p' => new MatrixCell(4.0, history: [4.0])], 'Org-wide')],
        ));

        self::assertNull($view->bands[0]->rows[0]->cells[0]->spark);
    }

    /**
     * A CELL THAT COUNTS STATES DRAWS CHIPS AND NO NUMBER. The word is
     * the publisher's and the tone is the platform's — the same bargain
     * a calendar pill strikes.
     */
    public function testACellOfStatesBecomesChipsWhoseToneIsThisPlatforms(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('pace', 'Pace')],
            [new MatrixRow('x', 'X', [
                'pace' => MatrixCell::marking(
                    new CellMark('met', ColumnPolarity::Up, 'period closed'),
                    new CellMark('missed', ColumnPolarity::Down),
                    new CellMark('cannot be paced'),
                ),
            ], 'Org-wide')],
        ));

        $cell = $view->bands[0]->rows[0]->cells[0];
        self::assertSame(HeatCellKind::Marks, $cell->kind);
        self::assertSame('', $cell->figure);
        self::assertSame(
            [['met', 'good', 'period closed'], ['missed', 'bad', ''], ['cannot be paced', '', '']],
            array_map(
                static fn (object $mark): array => [$mark->label, $mark->tone, $mark->title],
                $cell->marks,
            ),
        );
    }

    /** The placing arrives on the cell that carries the figure, and nowhere else. */
    public function testThePlacingReachesTheCellItPlaced(): void
    {
        $view = self::view(new TopicMatrix(
            [new MatrixColumn('c', 'C', polarity: ColumnPolarity::Up)],
            [
                new MatrixRow('a', 'A', ['c' => new MatrixCell(30.0)], 'Org-wide'),
                new MatrixRow('b', 'B', ['c' => new MatrixCell(20.0)], 'Org-wide'),
                new MatrixRow('c', 'C', ['c' => new MatrixCell(10.0)], 'Org-wide'),
            ],
        ));

        self::assertSame('h5', $view->bands[0]->rows[0]->cells[0]->tint?->value);
        self::assertSame('h1', $view->bands[0]->rows[2]->cells[0]->tint?->value);
    }

    /** A matrix with no rows knows it, so a page can say so in its own words. */
    public function testAMatrixOfNothingIsEmpty(): void
    {
        self::assertTrue(self::view(new TopicMatrix([], []))->isEmpty());
    }

    private static function view(TopicMatrix $matrix): HeatTable
    {
        return new MatrixViewBuilder(new MatrixPlacing())->build($matrix);
    }
}
