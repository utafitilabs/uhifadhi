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
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Contracts\Performance\CellMark;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * THE ONE DECISION THE PAGE MAKES ABOUT SOMEBODY ELSE'S FIGURES.
 *
 * A provider publishes figures and says which way is good; where a
 * department stands among the others is the host's to work out, once,
 * here — so two topics cannot disagree about what a tint means.
 */
#[CoversClass(MatrixPlacing::class)]
final class MatrixPlacingTest extends TestCase
{
    /**
     * BEST IS h5 AND WORST IS h1, and the rest are spread between them.
     * A reader learns the scale on one matrix and reads every other one.
     */
    public function testTheLeaderTakesTheTopTintAndTheTrailerTheBottom(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::Up, [
            'ec' => 90.0,
            'ps' => 60.0,
            'cd' => 30.0,
        ]));

        self::assertSame('h5', $tints['ec']['cov']);
        self::assertSame('h3', $tints['ps']['cov']);
        self::assertSame('h1', $tints['cd']['cov']);
    }

    /**
     * WHICH WAY IS GOOD IS THE COLUMN'S TO SAY. Days to settle an
     * incident is the same arithmetic read the other way round, and a
     * host that guessed from the label would get it wrong the first time
     * somebody published one.
     */
    public function testALowerFigureLeadsAColumnThatCountsDownwards(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::Down, [
            'ec' => 90.0,
            'ps' => 60.0,
            'cd' => 30.0,
        ]));

        self::assertSame('h1', $tints['ec']['cov']);
        self::assertSame('h5', $tints['cd']['cov']);
    }

    /**
     * A COLUMN THAT MAKES NO CLAIM IS NEVER TINTED. Positions is the
     * size of a department, and a department tinted red for being small
     * has been accused of something nobody measured.
     */
    public function testAColumnWithNoPolarityIsNotPlacedAtAll(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::None, [
            'ec' => 90.0,
            'ps' => 60.0,
            'cd' => 30.0,
        ]));

        self::assertSame(['ec' => [], 'ps' => [], 'cd' => []], $tints);
    }

    /**
     * FEWER THAN THREE FIGURES PLACE NOTHING. First and last out of two
     * is a tint that says "one of you is losing" about a field of two,
     * and out of one it is a tint that says nothing at all.
     */
    public function testAColumnTooThinToRankIsLeftUntinted(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::Up, [
            'ec' => 90.0,
            'ps' => 60.0,
        ]));

        self::assertSame(['ec' => [], 'ps' => []], $tints);
    }

    /**
     * THE BAND IS THE BOUNDARY. An org-wide department placed among an
     * area's is a placing of nothing, so each band is counted and ranked
     * on its own — three in one band does not lend a figure to the other.
     */
    public function testEachBandIsRankedAmongItselfAndCountedOnItsOwn(): void
    {
        $matrix = new TopicMatrix(
            [new MatrixColumn('cov', 'Coverage', polarity: ColumnPolarity::Up)],
            [
                self::row('ec', 'Org-wide', 10.0),
                self::row('ps', 'Org-wide', 20.0),
                self::row('cd', 'Org-wide', 30.0),
                self::row('vs', 'Kilimani', 99.0),
                self::row('ic', 'Kilimani', 1.0),
            ],
        );

        $tints = new MatrixPlacing()->forMatrix($matrix);

        // Ranked inside the band: the org-wide leader is the band's 30,
        // not the 99 an area published.
        self::assertSame('h5', $tints['cd']['cov']);
        self::assertSame('h1', $tints['ec']['cov']);
        // And the area band has two figures, which is too thin to place.
        self::assertSame([], $tints['vs']);
        self::assertSame([], $tints['ic']);
    }

    /** Two departments on the same figure are in the same place. */
    public function testAnEqualFigureTakesAnEqualPlace(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::Up, [
            'ec' => 50.0,
            'ps' => 50.0,
            'cd' => 10.0,
        ]));

        self::assertSame($tints['ec']['cov'], $tints['ps']['cov']);
        self::assertSame('h5', $tints['ec']['cov']);
    }

    /**
     * THE THREE EMPTINESSES ARE NOT FIGURES. A cell nobody published and
     * a column that is not this department's are absences, and an
     * absence that counted towards a ranking would place the departments
     * that did publish against departments that did not.
     */
    public function testAnAbsenceIsNeitherPlacedNorCounted(): void
    {
        $matrix = new TopicMatrix(
            [new MatrixColumn('cov', 'Coverage', polarity: ColumnPolarity::Up)],
            [
                self::row('ec', 'Org-wide', 30.0),
                self::row('ps', 'Org-wide', 20.0),
                new MatrixRow('cd', 'Community', ['cov' => new MatrixCell()], 'Org-wide'),
                new MatrixRow('vs', 'Vets', ['cov' => MatrixCell::notMine()], 'Org-wide'),
            ],
        );

        $tints = new MatrixPlacing()->forMatrix($matrix);

        self::assertSame([], $tints['cd']);
        self::assertSame([], $tints['vs']);
        // Two figures among four rows is still two figures: nothing is placed.
        self::assertSame([], $tints['ec']);
    }

    /**
     * A CELL THAT COUNTS STATES IS NOT A FIGURE. Four goals in four
     * states have no average, so a pace column is never ranked — not
     * even where every department in the band has one.
     */
    public function testACellOfStatesIsNeverPlaced(): void
    {
        $mark = static fn (): MatrixCell => MatrixCell::marking(new CellMark('met', ColumnPolarity::Up));

        $matrix = new TopicMatrix(
            [new MatrixColumn('pace', 'Pace', polarity: ColumnPolarity::Up)],
            [
                new MatrixRow('ec', 'Ecology', ['pace' => $mark()], 'Org-wide'),
                new MatrixRow('ps', 'Protection', ['pace' => $mark()], 'Org-wide'),
                new MatrixRow('cd', 'Community', ['pace' => $mark()], 'Org-wide'),
            ],
        );

        self::assertSame(['ec' => [], 'ps' => [], 'cd' => []], new MatrixPlacing()->forMatrix($matrix));
    }

    /** A matrix with no rows has nothing to place and says so quietly. */
    public function testAMatrixOfNothingPlacesNothing(): void
    {
        self::assertSame([], new MatrixPlacing()->forMatrix(new TopicMatrix([], [])));
    }

    /**
     * Six in a band fill the scale without a gap in it: a reader who
     * sees an h4 and an h2 has seen the same column twice.
     */
    public function testAFullBandSpreadsAcrossTheWholeScale(): void
    {
        $tints = new MatrixPlacing()->forMatrix(self::matrix(ColumnPolarity::Up, [
            'a' => 60.0, 'b' => 50.0, 'c' => 40.0, 'd' => 30.0, 'e' => 20.0,
        ]));

        self::assertSame(
            ['h5', 'h4', 'h3', 'h2', 'h1'],
            array_map(static fn (array $row): string => $row['cov'], array_values($tints)),
        );
    }

    /** @param array<string, float> $values */
    private static function matrix(ColumnPolarity $polarity, array $values): TopicMatrix
    {
        $rows = [];
        foreach ($values as $uuid => $value) {
            $rows[] = self::row($uuid, 'Org-wide', $value);
        }

        return new TopicMatrix([new MatrixColumn('cov', 'Coverage', polarity: $polarity)], $rows);
    }

    private static function row(string $uuid, string $band, float $value): MatrixRow
    {
        return new MatrixRow($uuid, ucfirst($uuid), ['cov' => new MatrixCell($value)], $band);
    }
}
