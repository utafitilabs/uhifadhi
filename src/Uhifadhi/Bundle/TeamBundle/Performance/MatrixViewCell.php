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

use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;

/**
 * ONE CELL, WITH EVERY DECISION ALREADY MADE — the template writes it
 * down and works nothing out.
 */
final readonly class MatrixViewCell
{
    /**
     * @param list<CellChip> $marks the states, where this cell counts states
     */
    public function __construct(
        public CellKind $kind,
        public string $tint = '',
        public string $figure = '',
        public string $unit = '',
        public string $delta = '',
        /** 'good', 'bad', 'flat', or '' where the column makes no claim. */
        public string $deltaTone = '',
        /** The history as the atlas draws it at the cell's size; null where there is no line to draw. */
        public ?Sparkline $spark = null,
        public array $marks = [],
        /** What a blank cell says in the reader's own language. */
        public string $word = '',
        /** What the whole cell says on hover, blank or not. */
        public string $title = '',
        /**
         * THE FIGURE AS A MACHINE READS IT, for the sort — null on a
         * cell that has no figure, which is how an absence sorts to the
         * end however the column is turned rather than sorting as nought.
         */
        public ?float $sort = null,
    ) {
    }

    public function isBlank(): bool
    {
        return CellKind::Blank === $this->kind;
    }

    public function isMarks(): bool
    {
        return CellKind::Marks === $this->kind;
    }
}
