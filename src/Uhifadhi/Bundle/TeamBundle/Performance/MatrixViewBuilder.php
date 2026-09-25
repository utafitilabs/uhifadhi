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

use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatBand;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatCell;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatChip;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatColumn;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatRow;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTint;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Contracts\Performance\CellMark;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * A PUBLISHED MATRIX, TURNED INTO THE ATLAS'S HEAT TABLE — something a
 * template can only write down.
 *
 * EVERY DECISION IS MADE HERE. What a figure looks like printed, which
 * of the three absences a cell is and what it says, whether a movement
 * reads well, where the sparkline breaks, what tone a state chip wears —
 * a template that decided any of it would decide it differently on the
 * next page, and a module that decided it would decide it differently
 * from the host.
 *
 * WHAT A TINT IS, WHICH ABSENCE A CELL IS AND WHAT IT SAYS are Team's
 * decisions, made here; what a heat table looks like is the atlas's
 * ({@see HeatTable}, drawn by `atlas_heatmap()`).
 */
final readonly class MatrixViewBuilder
{
    public function __construct(
        private MatrixPlacing $placing,
    ) {
    }

    public function build(TopicMatrix $matrix): HeatTable
    {
        $tints = $this->placing->forMatrix($matrix);

        /** @var array<string, list<HeatRow>> $bands */
        $bands = [];
        foreach ($matrix->rows as $row) {
            $bands[$row->band][] = $this->row($row, $matrix->columns, $tints[$row->departmentUuid] ?? []);
        }

        $built = [];
        foreach ($bands as $name => $rows) {
            $built[] = new HeatBand((string) $name, $rows, $matrix->bandNotes[(string) $name] ?? '');
        }

        return new HeatTable(array_map(self::column(...), $matrix->columns), $built);
    }

    private static function column(MatrixColumn $column): HeatColumn
    {
        return new HeatColumn(
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
    private function row(MatrixRow $row, array $columns, array $tints): HeatRow
    {
        $cells = [];
        foreach ($columns as $column) {
            $cell = $row->cells[$column->key] ?? new MatrixCell();
            $cells[] = $this->cell($cell, $column, $tints[$column->key] ?? '');
        }

        return new HeatRow(
            $row->departmentName,
            $row->mark,
            $cells,
            $row->url,
            $row->note,
        );
    }

    private function cell(MatrixCell $cell, MatrixColumn $column, string $tint): HeatCell
    {
        // A COLUMN THAT IS NOT THIS DEPARTMENT'S. Not a nought, not a
        // silence — a question this department was never asked.
        if ($cell->notMine) {
            return HeatCell::blank(
                'not its topic',
                title: \sprintf("%s is not one of this department\u{2019}s topics", $column->label),
            );
        }

        if ($cell->isMarked()) {
            return HeatCell::marks(array_map(self::chip(...), $cell->marks), $column->caption);
        }

        // A MODULE THAT HAS PUBLISHED NOTHING. There is a module and it
        // is silent, which is not the same as a nought it measured.
        if (null === $cell->value) {
            return HeatCell::blank('no figure', 'No figure to read');
        }

        return HeatCell::figure(
            Figures::figure($cell->value),
            tint: HeatTint::tryFrom($tint),
            unit: $column->unit,
            delta: Figures::delta($cell->delta),
            deltaTone: Figures::tone($cell->delta, $column->polarity),
            spark: Figures::line($cell->history, Figures::sparkTone($cell->delta, $column->polarity), SparkSize::Cell),
            title: $column->caption,
            sort: $cell->value,
        );
    }

    private static function chip(CellMark $mark): HeatChip
    {
        return new HeatChip(
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
