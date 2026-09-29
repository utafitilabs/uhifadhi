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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\TeamBundle\Twig\MatrixRuntime;
use Uhifadhi\Contracts\Performance\CellMark;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * `{{ render_matrix(...) }}` THROUGH THE REAL TWIG — the DP·01 grammar
 * every topic's matrix is drawn in.
 *
 * ONE RENDERER FOR ALL OF THEM. The host's Staffing and a module's
 * Patrols are the same shape, so they are the same call; the host's
 * cannot quietly acquire an ability a module's lacks, and a module
 * cannot draw a table of its own that looks almost like this one.
 */
#[CoversClass(MatrixRuntime::class)]
final class RenderMatrixTest extends TestCase
{
    /** The card the platform draws every matrix in, with the table inside it. */
    public function testAMatrixIsACardWithASideScrollingTableInIt(): void
    {
        $html = self::render(self::staffing(), ['title' => 'Staffing', 'publisher' => 'the host']);

        self::assertStringContainsString('class="pfc"', $html);
        self::assertStringContainsString('Staffing', $html);
        self::assertStringContainsString('class="hscroll pa-board"', $html);
        self::assertStringContainsString('<table class="tbl heat">', $html);
        self::assertStringContainsString('the host', $html);
    }

    /** Every column is a header, in the order the topic published them. */
    public function testTheColumnsAreHeadersInThePublishersOrder(): void
    {
        $html = self::render(self::staffing());

        self::assertLessThan(
            strpos($html, 'Vacant') ?: 0,
            strpos($html, 'Positions filled') ?: 0,
        );
    }

    /**
     * THE BAND IS DRAWN, because a tint is a placing inside one band and
     * a reader who cannot see the boundary cannot see what the shade was
     * measured against.
     */
    public function testEachBandOpensWithARowThatNamesItAndCountsIt(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('class="pfscope"', $html);
        self::assertStringContainsString('Org-wide', $html);
        self::assertStringContainsString('Kilimani', $html);
    }

    /** A department is its two letters, its name and the way into its own page. */
    public function testARowCarriesTheDepartmentAndTheWayIntoIt(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('class="dept"', $html);
        self::assertStringContainsString('>EC<', $html);
        self::assertStringContainsString('href="/departments/ecology"', $html);
        self::assertStringContainsString('class="open-btn"', $html);
    }

    /**
     * A ROW AND A BAND CARRY THE TOPIC'S OWN WORDS ABOUT THEM. A band
     * that only counted its rows would leave the reader to guess what
     * "Org-wide" is measured over, and a department that only gave its
     * name would leave them to guess how big it is.
     */
    public function testTheTopicsWordsForARowAndABandAreDrawn(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString("3 positions \u{b7} org-wide", $html);
        self::assertStringContainsString('each reads every area', $html);
    }

    /** The placing reaches the cell as a shade and never as a colour. */
    public function testTheLeadingFigureWearsTheTopShade(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('class="hcell h5"', $html);
        self::assertStringContainsString('class="hcell h1"', $html);
        self::assertStringNotContainsString('style="background', $html);
    }

    /**
     * A COLUMN THAT IS NOT THIS DEPARTMENT'S SAYS SO IN WORDS, and the
     * words are in the cell rather than only in a title nobody hovers.
     */
    public function testAnAbsenceIsWrittenOutAndNotLeftBlank(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('hcell h0 blank', $html);
        self::assertStringContainsString('not its topic', $html);
        self::assertStringContainsString('no figure', $html);
    }

    /**
     * A CELL THAT COUNTS STATES DRAWS CHIPS IN THE SAME CELL RULE the
     * figures use — one `.hcell`, two kinds of content, so the grid
     * cannot drift between them.
     */
    public function testARunOfStatesIsDrawnAsChipsInsideTheSameCell(): void
    {
        $html = self::render(new TopicMatrix(
            [new MatrixColumn('pace', 'Pace')],
            [new MatrixRow('a', 'Ecology', [
                'pace' => MatrixCell::marking(
                    new CellMark('met', ColumnPolarity::Up),
                    new CellMark('missed', ColumnPolarity::Down),
                ),
            ], 'Org-wide', 'EC')],
        ));

        self::assertStringContainsString('class="hcell"', $html);
        self::assertStringContainsString('class="r2 marks"', $html);
        self::assertStringContainsString('class="cmark good"', $html);
        self::assertStringContainsString('class="cmark bad"', $html);
        self::assertStringContainsString('>missed<', $html);
    }

    /** The movement rides with the figure, toned by the column and not by its sign. */
    public function testAMovementIsDrawnWithTheToneItsColumnGivesIt(): void
    {
        $html = self::render(new TopicMatrix(
            [new MatrixColumn('d', 'Days to settle', unit: 'd', polarity: ColumnPolarity::Down)],
            [new MatrixRow('a', 'Ecology', ['d' => new MatrixCell(6.2, delta: 1.4, history: [4.0, 5.0, 6.2])], 'Org-wide', 'EC')],
        ));

        self::assertStringContainsString('class="delta bad"', $html);
        self::assertStringContainsString('<em>d</em>', $html);
        self::assertStringContainsString('class="spark"', $html);
        self::assertStringContainsString('class="dn"', $html);
    }

    /**
     * A SPARKLINE IS A LINE AND NEVER A COLOUR. The tone is a class the
     * sheet paints, so a theme change reaches it and a module cannot
     * hand the host a hex. `currentColor` on an icon is the opposite of
     * a choice and is left alone.
     */
    public function testTheSparklineCarriesNoColourOfItsOwn(): void
    {
        $html = self::render(self::staffing());
        $polyline = substr($html, strpos($html, '<polyline') ?: 0, 120);

        self::assertStringStartsWith('<polyline class="', $polyline);
        self::assertStringNotContainsString('stroke', $polyline);
        self::assertStringNotContainsString('var(--', $html);
        self::assertStringNotContainsString('color-mix', $html);
    }

    /**
     * THE LEGEND IS PART OF THE MATRIX. A shade nobody explained is a
     * verdict a reader invents, so the card's foot says what the tints
     * are and what they are not.
     */
    public function testTheCardsFootExplainsWhatAShadeMeansAndWhatItDoesNot(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('class="pfc-ft"', $html);
        self::assertStringContainsString('class="legend"', $html);
        self::assertStringContainsString('leads the column', $html);
        self::assertStringContainsString('not a zero', $html);
    }

    /** A column that published a total says it in its own header. */
    public function testAColumnsTotalIsWrittenIntoItsHeader(): void
    {
        $html = self::render(new TopicMatrix(
            [new MatrixColumn('f', 'Positions filled', unit: 'of 84', total: 71.0, totalDelta: 3.0, polarity: ColumnPolarity::Up)],
            [new MatrixRow('a', 'Ecology', ['f' => new MatrixCell(9.0)], 'Org-wide', 'EC')],
        ));

        self::assertStringContainsString('class="thtot"', $html);
        self::assertStringContainsString('>71<', $html);
        self::assertStringContainsString('+3', $html);
    }

    /**
     * A MATRIX OF NOTHING IS NOT AN EMPTY TABLE. A head, a rule and no
     * rows reads as a measurement of nought.
     */
    public function testAMatrixWithNoRowsSaysSoRatherThanDrawingAnEmptyTable(): void
    {
        $html = self::render(new TopicMatrix([], []));

        self::assertStringNotContainsString('<table', $html);
        self::assertStringContainsString('No department reads this topic', $html);
    }

    /** Every header is sortable, and the sort is the matrix's own enhancement. */
    public function testTheHeadersAreSortableAndTheTableSaysWhoSortsIt(): void
    {
        $html = self::render(self::staffing());

        self::assertStringContainsString('data-controller="uhifadhi--team-bundle--matrix"', $html);
        self::assertStringContainsString('class="sortable"', $html);
        self::assertStringContainsString('aria-sort="none"', $html);
    }

    private static function staffing(): TopicMatrix
    {
        return new TopicMatrix(
            [
                new MatrixColumn('fil', 'Positions filled', unit: 'of 11', polarity: ColumnPolarity::Up),
                new MatrixColumn('vac', 'Vacant', polarity: ColumnPolarity::Down),
                new MatrixColumn('pat', 'Patrols', polarity: ColumnPolarity::Up),
            ],
            [
                new MatrixRow('a', 'Ecology', [
                    'fil' => new MatrixCell(9.0, delta: 0.0, history: [7.0, 8.0, 9.0]),
                    'vac' => new MatrixCell(2.0),
                    'pat' => MatrixCell::notMine(),
                ], 'Org-wide', 'EC', '/departments/ecology', "3 positions \u{b7} org-wide"),
                new MatrixRow('b', 'Tourism', [
                    'fil' => new MatrixCell(5.0),
                    'vac' => new MatrixCell(4.0),
                    'pat' => new MatrixCell(),
                ], 'Org-wide', 'TO', '/departments/tourism'),
                new MatrixRow('c', 'Planning', [
                    'fil' => new MatrixCell(1.0),
                    'vac' => new MatrixCell(6.0),
                    'pat' => MatrixCell::notMine(),
                ], 'Org-wide', 'PL', '/departments/planning'),
                new MatrixRow('d', 'Vets', [
                    'fil' => new MatrixCell(3.0),
                    'vac' => new MatrixCell(1.0),
                    'pat' => MatrixCell::notMine(),
                ], 'Kilimani', 'VS', '/departments/vets'),
            ],
            'one cell a department in a topic',
            ['Org-wide' => 'each reads every area', 'Kilimani' => 'each reads one area only'],
        );
    }

    /** @param array<string, string> $options */
    private static function render(TopicMatrix $matrix, array $options = []): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate('{{ render_matrix(matrix, options) }}')->render([
            'matrix' => $matrix,
            'options' => $options,
        ]);

        $kernel->shutdown();

        return $html;
    }
}
