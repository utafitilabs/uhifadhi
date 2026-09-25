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

namespace Uhifadhi\Bundle\AtlasBundle\Model\Heatmap;

use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;

/**
 * ONE HEAT CELL, with every decision already made — the template writes it
 * down and works nothing out. A figure and a run of states are the same box
 * with different contents, so the grid cannot drift between them.
 */
final readonly class HeatCell
{
    /**
     * @param list<HeatChip> $marks
     */
    private function __construct(
        public HeatCellKind $kind,
        public ?HeatTint $tint = null,
        public string $figure = '',
        public string $unit = '',
        public string $delta = '',
        /** 'good', 'bad', 'flat', or '' where the column makes no claim. */
        public string $deltaTone = '',
        public ?Sparkline $spark = null,
        public array $marks = [],
        /** What a blank cell says, in the reader's own words. */
        public string $word = '',
        /** What the whole cell says on hover. */
        public string $title = '',
        /** The figure as a machine sorts it; null sorts an absence to the end either way. */
        public ?float $sort = null,
    ) {
    }

    /** A measurement: printed, placed in its column, with its movement and its line. */
    public static function figure(string $figure, ?HeatTint $tint = null, string $unit = '', string $delta = '', string $deltaTone = '', ?Sparkline $spark = null, string $title = '', ?float $sort = null): self
    {
        return new self(HeatCellKind::Figure, $tint, $figure, $unit, $delta, $deltaTone, $spark, title: $title, sort: $sort);
    }

    /** @param list<HeatChip> $marks a run of states, never placed */
    public static function marks(array $marks, string $title = ''): self
    {
        return new self(HeatCellKind::Marks, marks: $marks, title: $title);
    }

    /** An absence, and which one, in words. */
    public static function blank(string $word, string $title = ''): self
    {
        return new self(HeatCellKind::Blank, word: $word, title: $title);
    }

    public function isBlank(): bool
    {
        return HeatCellKind::Blank === $this->kind;
    }

    public function isMarks(): bool
    {
        return HeatCellKind::Marks === $this->kind;
    }
}
