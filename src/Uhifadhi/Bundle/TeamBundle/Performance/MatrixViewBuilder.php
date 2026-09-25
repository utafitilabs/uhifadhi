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

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Contracts\Performance\CellMark;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * A PUBLISHED MATRIX, TURNED INTO SOMETHING A TEMPLATE CAN ONLY WRITE
 * DOWN.
 *
 * EVERY DECISION IS MADE HERE. What a figure looks like printed, which
 * of the three absences a cell is and what it says, whether a movement
 * reads well, where the sparkline breaks, what tone a state chip wears —
 * a template that decided any of it would decide it differently on the
 * next page, and a module that decided it would decide it differently
 * from the host.
 *
 * ONE SHAPE FOR BOTH KINDS OF CELL. A figure and a run of states are the
 * same {@see MatrixViewCell} with a different {@see CellKind}, so the
 * template has one rule for a cell and the grid cannot drift between the
 * two.
 */
final readonly class MatrixViewBuilder
{
    public function __construct(
        private MatrixPlacing $placing,
    ) {
    }

    public function build(TopicMatrix $matrix): MatrixView
    {
        $tints = $this->placing->forMatrix($matrix);

        /** @var array<string, list<MatrixViewRow>> $bands */
        $bands = [];
        foreach ($matrix->rows as $row) {
            $bands[$row->band][] = $this->row($row, $matrix->columns, $tints[$row->departmentUuid] ?? []);
        }

        $built = [];
        foreach ($bands as $name => $rows) {
            $built[] = new MatrixBand((string) $name, $rows, $matrix->bandNotes[(string) $name] ?? '');
        }

        return new MatrixView(array_map(self::column(...), $matrix->columns), $built, $matrix->caption);
    }

    private static function column(MatrixColumn $column): MatrixViewColumn
    {
        return new MatrixViewColumn(
            $column->key,
            $column->label,
            $column->unit,
            $column->caption,
            null === $column->total ? '' : Figures::figure($column->total),
            Figures::delta($column->totalDelta),
            Figures::tone($column->totalDelta, $column->polarity),
        );
    }

    /**
     * @param list<MatrixColumn>    $columns
     * @param array<string, string> $tints
     */
    private function row(MatrixRow $row, array $columns, array $tints): MatrixViewRow
    {
        $cells = [];
        foreach ($columns as $column) {
            $cell = $row->cells[$column->key] ?? new MatrixCell();
            $cells[] = $this->cell($cell, $column, $tints[$column->key] ?? '');
        }

        return new MatrixViewRow(
            $row->departmentUuid,
            $row->departmentName,
            $row->mark,
            $cells,
            $row->url,
            $row->note,
        );
    }

    private function cell(MatrixCell $cell, MatrixColumn $column, string $tint): MatrixViewCell
    {
        // A COLUMN THAT IS NOT THIS DEPARTMENT'S. Not a nought, not a
        // silence — a question this department was never asked.
        if ($cell->notMine) {
            return new MatrixViewCell(
                CellKind::Blank,
                word: 'not its topic',
                title: \sprintf("%s is not one of this department\u{2019}s topics", $column->label),
            );
        }

        if ($cell->isMarked()) {
            return new MatrixViewCell(
                CellKind::Marks,
                marks: array_map(self::chip(...), $cell->marks),
                title: $column->caption,
            );
        }

        // A MODULE THAT HAS PUBLISHED NOTHING. There is a module and it
        // is silent, which is not the same as a nought it measured.
        if (null === $cell->value) {
            return new MatrixViewCell(
                CellKind::Blank,
                word: 'no figure',
                title: 'No figure to read',
            );
        }

        return new MatrixViewCell(
            CellKind::Figure,
            tint: $tint,
            figure: Figures::figure($cell->value),
            unit: $column->unit,
            delta: Figures::delta($cell->delta),
            deltaTone: Figures::tone($cell->delta, $column->polarity),
            spark: Figures::line($cell->history, SparkTone::from(Figures::sparkTone($cell->delta, $column->polarity)), SparkSize::Cell),
            title: $column->caption,
            sort: $cell->value,
        );
    }

    private static function chip(CellMark $mark): CellChip
    {
        return new CellChip(
            $mark->label,
            match ($mark->reads) {
                ColumnPolarity::Up => 'good',
                ColumnPolarity::Down => 'bad',
                ColumnPolarity::None => '',
            },
            $mark->title ?? '',
        );
    }
}
