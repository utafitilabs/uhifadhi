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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * ONE CHART, STATED RATHER THAN DRAWN.
 *
 * THE MAP PLATE'S SIBLING, AND THE SAME BARGAIN. A module says what its
 * series is, what the axis reads, what it is counted in and what it was
 * measured against; the atlas owns the colours, the grid, the axes, the
 * legend and the height — so every chart in the product reads the same
 * way and a module cannot invent a fifth look.
 *
 * A FIXED BOX. A chart that grew with its data would make one topic's
 * page twice another's; the height is the platform's and the design's,
 * and a caller may state one only through the same custom-property door
 * a plate's height comes through.
 *
 * A TARGET IS A FACT. It is what somebody committed to, and a chart of
 * attainment without it is a chart of a number.
 *
 * THREE MORE THINGS A CALLER MAY STATE, none of them a look: the top and
 * step of the value axis ({@see AxisScale}), that the figures are written
 * on the bars ({@see ChartFigures}), and where the legend goes
 * ({@see ChartLegend}). Each is a fact about how the chart is READ; how
 * it is drawn stays the atlas's.
 */
final readonly class AtlasChart
{
    /**
     * @param list<string>      $labels the axis, one per point in every series
     * @param list<ChartSeries> $series
     */
    public function __construct(
        public ChartKind $kind,
        public array $labels,
        public array $series,
        public ?float $target = null,
        public string $unit = '',
        /** What the target line is called, where "Target" is not the word. */
        public string $targetLabel = 'Target',
        /** The top of the value axis and its step, where the caller has a rule; null leaves it to the library. */
        public ?AxisScale $axis = null,
        /** The figure at the end of every bar; null draws none and a hover answers instead. */
        public ?ChartFigures $figures = null,
        public ChartLegend $legend = ChartLegend::Canvas,
        /** How a nought is drawn on a bar chart: no bar, or a faded hairline on the axis. */
        public ChartNoughts $noughts = ChartNoughts::Blank,
        /** The widest a bar may be drawn, in pixels, where the design states one; null leaves it to the library. */
        public ?float $barWidth = null,
        /**
         * The corner radius of every bar, in pixels, where the design states
         * one — the designs draw 0.8, 1.2, 1.5, 2.5 and 3, so it is the
         * chart's to say; null leaves the bars square.
         */
        public ?float $barRadius = null,
    ) {
    }

    /**
     * THE ROWS A CHIP LEGEND IS DRAWN FROM: every series in order, wearing
     * the category the builder gives its marks — stated, or its position in
     * the palette — and the target last, with no category, because a dashed
     * grey line is not one.
     *
     * AN ACCENT SERIES IS NO CATEGORY and its pill is the accent pill.
     *
     * @return list<array{label: string, cat: int|null, swatch: string|null, accent: bool}>
     */
    public function legendRows(): array
    {
        $rows = [];
        foreach ($this->series as $position => $series) {
            $rows[] = [
                'label' => $series->label,
                'cat' => null !== $series->swatch || $series->accent ? null : $series->cat ?? ($position % PlatePalette::CATEGORIES) + 1,
                'swatch' => $series->swatch,
                'accent' => $series->accent,
            ];
        }

        if (null !== $this->target) {
            $rows[] = ['label' => $this->targetLabel, 'cat' => null, 'swatch' => null, 'accent' => false];
        }

        return $rows;
    }

    /** A chart nobody published a point in is not drawn at all. */
    public function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series->points as $point) {
                if (null !== $point) {
                    return false;
                }
            }
        }

        return true;
    }
}
