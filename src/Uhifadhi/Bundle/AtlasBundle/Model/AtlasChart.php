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
use Uhifadhi\Contracts\Performance\ChartKind;

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
 *
 * AND THREE FACTS ABOUT WHERE A READING SITS: a shaded range of the value
 * axis with a word ({@see ChartRange} — "above +0.5 is El Niño"), the one
 * label the reader has picked (`highlight`, banded behind every series),
 * and which panel of a pair on one axis this chart is ({@see ChartPanel}:
 * the upper writes no index ticks of its own, and both line their plots up).
 *
 * AND WHAT A KIND OF ITS OWN NEEDS: what each part of a whole wears
 * (`parts`), the figure in its hole (`centre`) and the word when it holds
 * nothing (`empty`); a nested doughnut's outer ring (`children`); a band's
 * edges (`band`); a scatter's two axes and its four regions (`xAxis`,
 * `yAxis`, `quadrants`, `axes`); a second measure's axis (`y2Axis`). And the
 * options a kind may take — the curve, gaps drawn dashed, a gradient or a
 * radial fill, a log scale, a colour past a limit (`threshold`), a long run
 * thinned (`decimation`), the hours of a day on the value axis (`clock`),
 * the plot's border (`frame`), and the index axis read as time (`time`).
 *
 * AND WHAT AN EXTENSION KIND IS MADE OF, none of it a series of points: the
 * two panes of a stacked-panels chart (`panes`, each series naming its pane);
 * a heatmap's rows and the value at each label of each row (`rows`,
 * `values`) and the ramp it is coloured by (`ramp`); a calendar heatmap's
 * first day and a value per day from it (`start`, `values`); a treemap's
 * groups and leaves (the labels and `children`, a group's short caption in
 * `names`); a sankey's sources (the labels), outcomes (`targets`) and the
 * flow from each to each (`flows`). A boxplot's or a violin's series carry
 * every reading at each label; a funnel's one series its stages.
 *
 * WHAT A KIND CANNOT BE IS REFUSED WHERE IT IS STATED: two measures on two
 * axes are exactly two, the second on the right; a grouped bar holds at most
 * four series; a band has an edge for every label; a nested doughnut's
 * children come in their parents' order and sum to them; a point is the
 * shape its kind reads; a stacked-panels chart has two panes and a series in
 * the second; a grid has a value at every label of every row, and a ramp; a
 * calendar starts somewhere; a treemap's leaf names a group there is; a
 * sankey has a flow from every source to every target; a boxplot has a
 * reading at every label; a funnel never widens; a decimated run is read
 * over time.
 */
final readonly class AtlasChart
{
    /**
     * @param list<string>                            $labels   the axis, one per point in every series; on a whole, its parts
     * @param list<ChartSeries>                       $series
     * @param list<ChartRange>                        $ranges   shaded ranges of the value axis, each with its word
     * @param list<ChartPart>                         $parts    what each part of a whole wears, one per label; unstated, positions 1..n
     * @param list<ChartChild>                        $children a nested doughnut's outer ring, in its parents' order; a treemap's leaves
     * @param list<ChartPane>                         $panes    a stacked-panels chart's two panes, the first on top
     * @param list<string>                            $rows     a heatmap's rows, top to bottom
     * @param list<float|null>|list<list<float|null>> $values   a heatmap's value at each label of each row
     *                                                          (`values[row][label]`); a calendar heatmap's
     *                                                          value per day from its start
     * @param array<string, string>                   $names    a short name per label, written where the full one does not fit
     * @param list<ChartFlowTarget>                   $targets  where a sankey's flows end
     * @param list<list<float>>                       $flows    a sankey's flow from each label to each target: `flows[label][target]`
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
         * one — the house draws 3, and 1.5 on a stack; null leaves the bars square.
         */
        public ?float $barRadius = null,
        public array $ranges = [],
        /** The index of the one label the reader has picked, banded behind every series; null bands none. */
        public ?int $highlight = null,
        /** Which panel of a pair on one axis this chart is; null for a chart that stands alone. */
        public ?ChartPanel $panel = null,
        public array $parts = [],
        /** The figure in the hole of a doughnut or a gauge. */
        public ?ChartCentre $centre = null,
        /** The word in the hole of a whole that holds nothing yet; null draws an empty whole as nothing. */
        public ?string $empty = null,
        /** How every line runs between readings, where a series states none; null is the house's gentle curve. */
        public ?ChartCurve $curve = null,
        public ChartGaps $gaps = ChartGaps::Blank,
        public ?ChartFill $fill = null,
        public ?ChartQuadrants $quadrants = null,
        /** A dashed line round the plot. The house does not draw it: the card is the border. */
        public bool $frame = false,
        public ?ChartThreshold $threshold = null,
        public ChartScale $scale = ChartScale::Linear,
        public ChartAxes $axes = ChartAxes::Edge,
        public ?ChartDecimation $decimation = null,
        /** A scatter's or a bubble's horizontal axis. */
        public ?ChartAxis $xAxis = null,
        /** A scatter's or a bubble's vertical axis; on a bar or a line, the value axis' title. */
        public ?ChartAxis $yAxis = null,
        /** The right-hand axis of a chart of two measures. */
        public ?ChartAxis $y2Axis = null,
        public ?ChartBand $band = null,
        public array $children = [],
        /** What a nested doughnut's outer ring is called in the hover. */
        public string $childLabel = 'Parts',
        /** The value axis is the hours of a day, written 06:00. */
        public bool $clock = false,
        /** What a bubble's size measures, written after it in the hover. */
        public string $sizeLabel = '',
        public ?ChartTime $time = null,
        public array $panes = [],
        public array $rows = [],
        public array $values = [],
        /** The ramp a heatmap's or a calendar heatmap's cells are coloured by. */
        public ?ChartRamp $ramp = null,
        /** A calendar heatmap's first day. */
        public ?\DateTimeImmutable $start = null,
        public array $names = [],
        public array $targets = [],
        public array $flows = [],
    ) {
        if (null !== $highlight && ($highlight < 0 || $highlight >= \count($labels))) {
            throw new \InvalidArgumentException(\sprintf('A highlight names one of the %d labels; %d is none of them.', \count($labels), $highlight));
        }
        if (\in_array($kind, [ChartKind::MultiAxisLine, ChartKind::ScatterMultiAxis], true)
            && (2 !== \count($series) || ChartSide::Right !== $series[1]->axis)) {
            throw new \InvalidArgumentException(\sprintf('A %s states exactly two measures, the second on the right axis.', $kind->value));
        }
        if (ChartKind::GroupedBar === $kind && \count($series) > 4) {
            throw new \InvalidArgumentException(\sprintf('A grouped bar holds at most four series; this one states %d.', \count($series)));
        }
        if (ChartKind::Band === $kind
            && (null === $band || \count($band->from) !== \count($labels) || \count($band->to) !== \count($labels))) {
            throw new \InvalidArgumentException('A band states a from and a to for every label.');
        }
        if (ChartKind::NestedDoughnut === $kind) {
            self::refuseUnsummedChildren($labels, $series, $children);
        }
        foreach ($series as $one) {
            self::refuseMisshapenPoints($kind, $one, null !== $decimation);
        }
        if (null !== $decimation && null === $time) {
            throw new \InvalidArgumentException('A decimated run is read over time: it states its time axis.');
        }
        match ($kind) {
            ChartKind::StackedPanels => self::refuseLonePane($panes, $series),
            ChartKind::Heatmap => self::refuseRaggedGrid($labels, $rows, $values, $ramp),
            ChartKind::CalendarHeatmap => self::refuseUnplacedDays($values, $ramp, $start),
            ChartKind::Treemap => self::refuseOrphanedLeaves($labels, $children),
            ChartKind::Sankey => self::refuseRaggedFlows($labels, $targets, $flows),
            ChartKind::Boxplot, ChartKind::Violin => self::refuseUnreadLabels($kind, $labels, $series),
            ChartKind::Funnel => self::refuseWideningStages($series),
            default => null,
        };
    }

    /**
     * @param list<ChartPane>   $panes
     * @param list<ChartSeries> $series
     */
    private static function refuseLonePane(array $panes, array $series): void
    {
        if (2 !== \count($panes) || [] === array_filter($series, static fn (ChartSeries $one): bool => 1 === $one->pane)) {
            throw new \InvalidArgumentException('A stacked-panels chart states two panes, and at least one series in the second.');
        }
    }

    /**
     * @param list<string> $labels
     * @param list<string> $rows
     * @param array<mixed> $values
     */
    private static function refuseRaggedGrid(array $labels, array $rows, array $values, ?ChartRamp $ramp): void
    {
        if (\count($values) !== \count($rows) || [] !== array_filter($values, static fn (mixed $row): bool => !\is_array($row) || \count($row) !== \count($labels))) {
            throw new \InvalidArgumentException(\sprintf('A heatmap states one value per label in every row: %d rows of %d.', \count($rows), \count($labels)));
        }
        if (null === $ramp) {
            throw new \InvalidArgumentException('A heatmap states its ramp.');
        }
    }

    /** @param array<mixed> $values */
    private static function refuseUnplacedDays(array $values, ?ChartRamp $ramp, ?\DateTimeImmutable $start): void
    {
        if (null === $start) {
            throw new \InvalidArgumentException('A calendar heatmap states its start.');
        }
        if ([] !== array_filter($values, static fn (mixed $value): bool => \is_array($value))) {
            throw new \InvalidArgumentException('A calendar heatmap states one value per day from its start.');
        }
        if (null === $ramp) {
            throw new \InvalidArgumentException('A calendar heatmap states its ramp.');
        }
    }

    /**
     * @param list<string>     $labels
     * @param list<ChartChild> $children
     */
    private static function refuseOrphanedLeaves(array $labels, array $children): void
    {
        foreach ($children as $child) {
            if ($child->of >= \count($labels)) {
                throw new \InvalidArgumentException(\sprintf('A treemap child names a group by position; "%s" names %d.', $child->label, $child->of));
            }
        }
    }

    /**
     * @param list<string>          $labels
     * @param list<ChartFlowTarget> $targets
     * @param list<list<float>>     $flows
     */
    private static function refuseRaggedFlows(array $labels, array $targets, array $flows): void
    {
        if (\count($flows) !== \count($labels) || [] !== array_filter($flows, static fn (array $row): bool => \count($row) !== \count($targets))) {
            throw new \InvalidArgumentException(\sprintf('A sankey states one flow per target from every source: %d rows of %d.', \count($labels), \count($targets)));
        }
    }

    /**
     * @param list<string>      $labels
     * @param list<ChartSeries> $series
     */
    private static function refuseUnreadLabels(ChartKind $kind, array $labels, array $series): void
    {
        foreach ($series as $one) {
            foreach ($one->points as $i => $point) {
                if ($point instanceof ChartReadings && [] === $point->readings) {
                    throw new \InvalidArgumentException(\sprintf('A %s states a list of readings for every label; "%s" has none.', $kind->value, $labels[$i] ?? (string) $i));
                }
            }
        }
    }

    /** @param list<ChartSeries> $series */
    private static function refuseWideningStages(array $series): void
    {
        $stages = [] === $series ? [] : $series[0]->points;
        foreach ($stages as $i => $stage) {
            $before = $stages[$i - 1] ?? null;
            if (\is_float($stage) && \is_float($before) && $stage > $before) {
                throw new \InvalidArgumentException('A funnel never widens: each stage holds at most what the stage before it held.');
            }
        }
    }

    /**
     * @param list<string>      $labels
     * @param list<ChartSeries> $series
     * @param list<ChartChild>  $children
     */
    private static function refuseUnsummedChildren(array $labels, array $series, array $children): void
    {
        $totals = [] === $series ? [] : $series[0]->points;
        $sums = array_fill(0, \count($totals), 0.0);
        $last = 0;
        foreach ($children as $child) {
            if ($child->of < $last || $child->of >= \count($totals)) {
                throw new \InvalidArgumentException(\sprintf('A nested doughnut states its children in their parents\' order; "%s" is out of it.', $child->label));
            }
            $last = $child->of;
            $sums[$child->of] += $child->value;
        }
        foreach ($totals as $i => $total) {
            if (!\is_float($total) || abs($sums[$i] - $total) > 1e-9) {
                throw new \InvalidArgumentException(\sprintf('The children of "%s" sum to %s, not %s.', $labels[$i] ?? (string) $i, $sums[$i], \is_float($total) ? $total : 'a figure'));
            }
        }
    }

    /** A point is the shape its kind reads, and nothing else. */
    private static function refuseMisshapenPoints(ChartKind $kind, ChartSeries $series, bool $decimated): void
    {
        [$shape, $test] = match (true) {
            ChartKind::Bubble === $kind => ['an item with a size (a ChartPoint stating one)', static fn (mixed $p): bool => $p instanceof ChartPoint && null !== $p->size],
            \in_array($kind, [ChartKind::Scatter, ChartKind::ScatterMultiAxis], true), $decimated => ['an item (a ChartPoint)', static fn (mixed $p): bool => $p instanceof ChartPoint],
            ChartKind::FloatingBar === $kind => ['a span or nothing (a ChartSpan or null)', static fn (mixed $p): bool => null === $p || $p instanceof ChartSpan],
            \in_array($kind, [ChartKind::Boxplot, ChartKind::Violin], true) => ['a list of readings (a ChartReadings)', static fn (mixed $p): bool => $p instanceof ChartReadings],
            default => ['a figure or nothing (a float or null)', static fn (mixed $p): bool => null === $p || \is_float($p)],
        };
        foreach ($series->points as $index => $point) {
            if (!$test($point)) {
                throw new \InvalidArgumentException(\sprintf('A %s reads %s at every point; point %d of "%s" is not one.', $kind->value, $shape, $index, $series->label));
            }
        }
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

    /**
     * A chart nobody published a point in is not drawn at all — and on a kind
     * whose figures are its own, nobody published a figure: no cell holds a
     * value, there is no leaf, no flow runs.
     */
    public function isEmpty(): bool
    {
        if (\in_array($this->kind, [ChartKind::Heatmap, ChartKind::CalendarHeatmap], true)) {
            foreach ($this->values as $value) {
                foreach (\is_array($value) ? $value : [$value] as $cell) {
                    if (null !== $cell) {
                        return false;
                    }
                }
            }

            return true;
        }
        if (ChartKind::Treemap === $this->kind) {
            return [] === $this->children;
        }
        if (ChartKind::Sankey === $this->kind) {
            return [] === array_filter(array_merge(...$this->flows), static fn (float $flow): bool => $flow > 0.0);
        }
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
