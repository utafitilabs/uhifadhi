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

namespace Uhifadhi\Bundle\AtlasBundle\Chart;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartAxes;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartAxis;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartChild;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartCurve;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFigures;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartFill;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartGaps;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPane;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPanel;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPart;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartPoint;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartQuadrant;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartRamp;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartRange;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartReadings;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartScale;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSide;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSpan;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartTime;
use Uhifadhi\Bundle\AtlasBundle\Model\LineStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\PointMarker;
use Uhifadhi\Contracts\Atlas\PlatePalette;
use Uhifadhi\Contracts\Performance\ChartKind;

/**
 * WHAT A STATED CHART BECOMES — the one place the platform decides what a
 * chart looks like.
 *
 * THE MAP BUILDER'S SIBLING. Everything a module would otherwise have to
 * know about Chart.js is here and nowhere else: the type behind each kind,
 * the scales, the legend, the grid, the tension of a line, the colours. A
 * module states a kind and a series; two modules cannot disagree about what
 * a bar chart is.
 *
 * THE SAME CONFIGURATION THE DESIGN BUILDS. The design workspace draws its
 * chart catalogue through a door whose `build()` this mirrors key for key
 * (ChartBuilderParityTest), so a design can never draw a chart the platform
 * cannot produce. What that door decides at draw time — the pixel ratio —
 * the plate decides here too, in chart_plate_controller.js.
 *
 * JSON ONLY, NOTHING CALLABLE. A colour is a TOKEN (`var(--cat-3)`, a faded
 * one written `color-mix(in srgb, var(--cat-3) 18%, transparent)`) resolved
 * by the plate where the chart is drawn, at mount and on a theme flip. What
 * a function must do — a gradient laid across the plot, a dashed run over a
 * silence, a log axis's ticks, the legend's filter — is stated here as a
 * fact under an `atlas…` key or a plugin's id, and the plate puts the
 * function on.
 *
 * NO ANIMATION, AND NO ASPECT RATIO OF ITS OWN. A chart in a card is given a
 * box by the card, and one that animated on every render would be a page
 * that moves while it is being read.
 *
 * NULLS SURVIVE. Chart.js draws a gap where a point is null, which is the
 * truthful reading of a period nobody reported.
 *
 * THE HOUSE TYPE, at the pairs of the house scale: ticks, legend and point
 * labels 10.5px 400 mono; the figure on a bar 10.5px 700; the hover's title
 * and body 11px 600 and 400; an axis title 9.5px 600, uppercase; the figure
 * in a hole 26px 700 and its caption 10.5px 600; a range's word 8.5px. The
 * grid is the line token, `--ln`; nought on a diverging chart and a centred
 * axis one step stronger, `--ln2`.
 *
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart — a sideways bar is `indexAxis: 'y'`; "any options specified on the x-axis in a bar chart, are applied to the y-axis in a horizontal bar chart"
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#floating-bars — a bar's data as `[start, end]`
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#dataset-properties — `minBarLength`, `maxBarThickness`, `borderRadius`, `borderSkipped`, `stack`
 * @see https://www.chartjs.org/docs/latest/charts/line.html — `tension`, `cubicInterpolationMode`, `stepped`, `spanGaps`, `fill`, `pointStyle`, `segment`
 * @see https://www.chartjs.org/docs/latest/charts/area.html — `fill: 'origin'`, `fill: '-1'` (to the dataset before)
 * @see https://www.chartjs.org/docs/latest/charts/mixed.html — a dataset of another `type` on the same chart
 * @see https://www.chartjs.org/docs/latest/charts/doughnut.html — `cutout`, `circumference`, `rotation`, `hoverOffset`
 * @see https://www.chartjs.org/docs/latest/charts/polar.html, https://www.chartjs.org/docs/latest/axes/radial/ — the `r` scale, `pointLabels.centerPointLabels`, `angleLines`
 * @see https://www.chartjs.org/docs/latest/charts/radar.html, https://www.chartjs.org/docs/latest/charts/scatter.html, https://www.chartjs.org/docs/latest/charts/bubble.html — `{x, y}` and `{x, y, r}` data
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/ — `position: 'center'`; a second value axis by `yAxisID`; `stacked`
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/logarithmic.html — `type: 'logarithmic'`
 * @see https://www.chartjs.org/docs/latest/axes/labelling.html#scale-title-configuration — `title.display`, `title.text`
 * @see https://www.chartjs.org/docs/latest/axes/styling.html — `ticks.color`, `ticks.font`, `ticks.padding`, `grid.color`, `grid.lineWidth`, `grid.drawTicks`, `border.display`
 * @see https://www.chartjs.org/docs/latest/configuration/decimation.html — `plugins.decimation`; the dataset unparsed (`parsing: false`), its index axis `x`, the x axis linear or time
 * @see https://www.chartjs.org/docs/latest/configuration/legend.html, https://www.chartjs.org/docs/latest/configuration/tooltip.html, https://www.chartjs.org/docs/latest/configuration/interactions.html
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — "Plugin options are located under the options.plugins config and are scoped by the plugin ID"
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/time.html — `type: 'time'`, `time.unit`, `time.displayFormats`, `time.tooltipFormat`, `time.isoWeekday`, `ticks.source`; "requires both a date library and a corresponding adapter to be present"
 * @see https://github.com/chartjs/chartjs-adapter-date-fns/tree/v3.0.0 — formats in date-fns tokens
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/#common-options-to-all-cartesian-axes — `stack`, `stackWeight`: value axes stacked in one column
 * @see https://chartjs-chart-matrix.pages.dev/ — chartjs-chart-matrix 3.1.0: type `matrix`, data `{x, y, v}`, `width`, `height`
 * @see https://chartjs-chart-treemap.pages.dev/ — chartjs-chart-treemap 4.2.2: type `treemap`, `tree`, `key`, `groups`, `spacing`, `captions`, `labels`
 * @see https://github.com/kurkle/chartjs-chart-sankey/tree/v0.19.1 — type `sankey`, data `{from, to, flow}`, `colorFrom`, `colorTo`, `colorMode`, `labels`, `priority`, `size`, `nodeWidth`, `nodePadding`
 * @see https://github.com/sgratzl/chartjs-chart-boxplot/tree/v4.4.5 — types `boxplot` and `violin`, a list of readings per label, `coef`, `outlierRadius`, `itemRadius`, `meanRadius`, `medianColor`, `points`
 * @see https://github.com/sgratzl/chartjs-chart-funnel/tree/v4.2.5 — type `funnel`, `shrinkAnchor`, `shrinkFraction`
 */
final readonly class ChartBuilder
{
    /** Where the plate's figures plugin reads its options: the id it is put on under. */
    public const string FIGURES_PLUGIN = 'figures';

    /** Where the plate reads how faded a nought's hairline is drawn. */
    public const string NOUGHTS = 'noughts';

    /** Where the plate's ranges plugin — shaded ranges and the picked label — reads its options. */
    public const string RANGES_PLUGIN = 'ranges';

    /** Where a chart that is one panel of a pair states the width its value axis is held at. */
    public const string PANELS_PLUGIN = 'panels';

    /** Where the plate's centre plugin — the figure in a hole — reads its options. */
    public const string CENTRE_PLUGIN = 'centre';

    /** Where the plate's quadrants plugin — four regions behind a scatter — reads its options. */
    public const string QUADRANTS_PLUGIN = 'quadrants';

    /** Where the plate's empty plugin — a whole that holds nothing — reads its options. */
    public const string EMPTY_PLUGIN = 'empty';

    /** Where the plate's frame plugin — the plot's border — reads its options. */
    public const string FRAME_PLUGIN = 'frame';

    /** Where the plate's axis-ends plugin — a centred axis's title at its end — reads its options. */
    public const string AXIS_ENDS_PLUGIN = 'axisEnds';

    /** The date adapter a time axis is drawn with. */
    public const string DATE_ADAPTER = 'chartjs-adapter-date-fns';

    /** The palette's own count: a position past it wraps back to the first category. */
    private const int CATEGORIES = PlatePalette::CATEGORIES;

    private const string MONO = 'var(--font-mono)';
    private const string INK = 'var(--tx)';
    private const string MUTED = 'var(--fog)';
    private const string GRID = 'var(--ln)';
    private const string STRONG_LINE = 'var(--ln2)';
    /** The card's ground: the gap drawn between two parts of a whole or a stack. */
    private const string SURFACE = 'var(--p1)';
    private const string ACCENT = 'var(--acc)';
    private const string TARGET_INK = 'var(--fog)';
    private const string MARK_RING = 'var(--cv)';

    private const float TICK_SIZE = 10.5;
    private const int TICK_WEIGHT = 400;
    private const int TICK_GAP = 5;
    private const float TITLE_SIZE = 9.5;
    private const int TITLE_WEIGHT = 600;
    private const int TOOLTIP_SIZE = 11;

    private const int FIGURE_ROOM_BESIDE = 36;
    private const int FIGURE_ROOM_ABOVE = 16;
    /** Room past each end of a diverging chart's unstated value axis, for the figures. */
    private const string FIGURE_GRACE = '10%';
    /** The room round a whole for the slice names written outside it. */
    private const int ARC_ROOM = 34;

    private const float RANGE_OPACITY = 0.08;
    private const float HIGHLIGHT_OPACITY = 0.14;

    /** Past this many points a line wears no dots: twelve months carry one a point; twenty-six weeks of dots are noise. */
    private const int DENSE_RUN = 16;
    private const int LINE_WIDTH = 2;
    private const int DOT = 2;
    /** A dot that wears a marker is drawn larger, so the shape reads. */
    private const float SHAPED_DOT = 3.5;
    private const int MARK_RADIUS = 4;
    private const array REFERENCE_DASH = [6, 4];
    private const array TARGET_DASH = [5, 4];
    private const float OVERLAY_WIDTH = 1.4;
    private const int HAIRLINE = 2;
    private const float HAIRLINE_OPACITY = 0.28;
    private const int HAIRLINE_RADIUS = 1;
    private const int PANEL_AXIS_WIDTH = 44;

    /** Per cent of the series' colour under an area, a band and a radar's ring. */
    private const int AREA_FILL = 18;
    /** Per cent under each layer of a stacked area: the layers never overlap. */
    private const int STACK_FILL = 42;
    /** Per cent at the top of a gradient fill, fading to nought at the floor. */
    private const int GRADIENT_TOP = 38;
    /** Per cent at the centre of a radial gradient, full at the rim. */
    private const int RADIAL_INNER = 22;
    /** Per cent of its parent's colour on a nested doughnut's outer ring. */
    private const int OUTER_TINT = 58;
    private const int BUBBLE_FILL = 70;
    private const int BUBBLE_MAX_RADIUS = 18;
    private const int BUBBLE_MIN_RADIUS = 4;
    private const array FRAME_DASH = [4, 4];

    /** A scatter's circle; a triangle, a square or a diamond reads smaller and is drawn larger. */
    private const int POINT_RADIUS = 4;
    private const int POINT_SHAPED = 6;
    /** How much larger a line's triangle, square or diamond is drawn than its dot. */
    private const int SHAPE_STEP = 1;
    private const array DRAWN_SMALL = [PointMarker::Triangle, PointMarker::Rect, PointMarker::RectRot];
    /** A name beside a horizontal bar longer than this is drawn on two lines. */
    private const int WRAP_AT = 18;

    /** The surface gap between two cells of a heatmap, and the corner of each. */
    private const int CELL_GAP = 2;
    private const int CELL_RADIUS = 2;
    /** The steps of a ramp's hue over the card, per cent, nearest the ground first. */
    private const array RAMP_STEPS = [18, 36, 56, 78, 100];
    /** The dataviz default sequential hue. */
    private const string SEQUENTIAL = 'var(--cat-3)';
    /** Per cent of its group's colour behind a treemap group's caption, and under a leaf. */
    private const int TREE_GROUP = 24;
    private const int TREE_LEAF = 58;
    private const int TREE_GAP = 2;
    /** Per cent of the series' colour inside a box or a violin. */
    private const int BOX_FILL = 30;
    /** A sankey flow's strength over the card. */
    private const float FLOW_ALPHA = 0.45;
    private const int NODE_WIDTH = 10;
    private const int NODE_PADDING = 14;
    /** The steps of a funnel's one hue, per cent, the first stage the strongest. */
    private const array STAGE_STEPS = [100, 86, 72, 58, 45];
    /** Room right of a funnel for its figures. */
    private const int STAGE_ROOM = 48;
    private const array WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** The package each extension kind is drawn by, by the name the importmap carries. */
    private const array PACKAGES = [
        'heatmap' => 'chartjs-chart-matrix',
        'calendar-heatmap' => 'chartjs-chart-matrix',
        'treemap' => 'chartjs-chart-treemap',
        'sankey' => 'chartjs-chart-sankey',
        'boxplot' => '@sgratzl/chartjs-chart-boxplot',
        'violin' => '@sgratzl/chartjs-chart-boxplot',
        'funnel' => 'chartjs-chart-funnel',
    ];

    /** The markers in order, where several series share a plot and state none. */
    private const array SHAPES = [PointMarker::Circle, PointMarker::Triangle, PointMarker::Rect, PointMarker::RectRot, PointMarker::CrossRot, PointMarker::Star];

    public function __construct(private ChartBuilderInterface $charts)
    {
    }

    public function chart(AtlasChart $chart): Chart
    {
        $built = $this->charts->createChart(self::typeOf($chart->kind));
        $built->setOptions(self::options($chart));

        if (self::extended($chart->kind)) {
            $built->setData(self::extendedData($chart));

            return $built;
        }

        $datasets = [];
        foreach ($chart->series as $position => $series) {
            $datasets[] = self::dataset($series, $position, $chart);
        }
        if (ChartKind::Band === $chart->kind) {
            $datasets = [...self::band($chart), ...$datasets];
        }
        if (ChartKind::NestedDoughnut === $chart->kind) {
            array_unshift($datasets, self::outerRing($chart));
        }
        if (null !== $chart->target) {
            $datasets[] = self::target($chart);
        }

        // A NAME BESIDE A HORIZONTAL BAR is drawn on two lines where it is long.
        $labels = self::sideways($chart->kind) ? array_map(self::twoLines(...), $chart->labels) : $chart->labels;
        $built->setData(['labels' => $labels, 'datasets' => $datasets]);

        return $built;
    }

    /**
     * WHAT A STATEMENT NEEDS ON TOP OF CHART.JS, by the names the importmap
     * carries: the date adapter where an axis is time (a stacked-panels
     * chart is read over time), and the package that draws its kind. The
     * plate loads each before the chart is made.
     *
     * @return list<string>
     */
    public function packages(AtlasChart $chart): array
    {
        $packages = [];
        if (null !== $chart->time || ChartKind::StackedPanels === $chart->kind) {
            $packages[] = self::DATE_ADAPTER;
        }
        if (isset(self::PACKAGES[$chart->kind->value])) {
            $packages[] = self::PACKAGES[$chart->kind->value];
        }

        return $packages;
    }

    /**
     * A label as one or two lines, broken at the space nearest its middle
     * (the first of two as near); one without a space is kept whole.
     *
     * @return string|list<string>
     */
    private static function twoLines(string $label): string|array
    {
        $length = mb_strlen($label);
        if ($length <= self::WRAP_AT) {
            return $label;
        }
        $middle = $length / 2;
        $best = -1;
        for ($i = 0; $i < $length; ++$i) {
            if (' ' === mb_substr($label, $i, 1) && ($best < 0 || abs($i - $middle) < abs($best - $middle))) {
                $best = $i;
            }
        }

        return $best < 0 ? $label : [mb_substr($label, 0, $best), mb_substr($label, $best + 1)];
    }

    private static function typeOf(ChartKind $kind): string
    {
        return match ($kind) {
            ChartKind::Line, ChartKind::Area, ChartKind::Stepped, ChartKind::StackedArea, ChartKind::Band, ChartKind::MultiAxisLine => Chart::TYPE_LINE,
            ChartKind::Bar, ChartKind::HorizontalBar, ChartKind::Stacked, ChartKind::StackedHorizontal, ChartKind::Diverging, ChartKind::Ranked,
            ChartKind::Combo, ChartKind::FloatingBar, ChartKind::GroupedBar, ChartKind::StackedBarLine, ChartKind::StackedPanels => Chart::TYPE_BAR,
            ChartKind::Doughnut, ChartKind::Gauge, ChartKind::NestedDoughnut => Chart::TYPE_DOUGHNUT,
            ChartKind::Pie => Chart::TYPE_PIE,
            ChartKind::PolarArea => Chart::TYPE_POLAR_AREA,
            ChartKind::Radar => Chart::TYPE_RADAR,
            ChartKind::Scatter, ChartKind::ScatterMultiAxis => Chart::TYPE_SCATTER,
            ChartKind::Bubble => Chart::TYPE_BUBBLE,
            ChartKind::Heatmap, ChartKind::CalendarHeatmap => 'matrix',
            ChartKind::Treemap => 'treemap',
            ChartKind::Sankey => 'sankey',
            ChartKind::Boxplot => 'boxplot',
            ChartKind::Violin => 'violin',
            ChartKind::Funnel => 'funnel',
        };
    }

    /** The extension kinds: their datasets are not one per series. */
    private static function extended(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Heatmap, ChartKind::CalendarHeatmap, ChartKind::Treemap, ChartKind::Sankey, ChartKind::Boxplot, ChartKind::Violin, ChartKind::Funnel], true);
    }

    /** A grid of cells on two category axes. */
    private static function matrix(ChartKind $kind): bool
    {
        return ChartKind::Heatmap === $kind || ChartKind::CalendarHeatmap === $kind;
    }

    /** Bars standing on a category axis. */
    private static function upright(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Bar, ChartKind::Stacked, ChartKind::GroupedBar, ChartKind::Combo, ChartKind::StackedBarLine], true);
    }

    /** A run read along a line. */
    private static function lines(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Line, ChartKind::Area, ChartKind::Stepped, ChartKind::StackedArea, ChartKind::Band, ChartKind::MultiAxisLine], true);
    }

    /** Bars that run sideways, the names down the left — a change either side of nought too. */
    private static function sideways(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Diverging, ChartKind::Ranked, ChartKind::HorizontalBar, ChartKind::StackedHorizontal, ChartKind::FloatingBar], true);
    }

    private static function stacked(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Stacked, ChartKind::StackedHorizontal, ChartKind::StackedBarLine], true);
    }

    /** A whole drawn as arcs: its parts are its labels. */
    private static function arcs(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Doughnut, ChartKind::Pie, ChartKind::Gauge, ChartKind::NestedDoughnut], true);
    }

    /** Items placed by two measures. */
    private static function points(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::Scatter, ChartKind::Bubble, ChartKind::ScatterMultiAxis], true);
    }

    /** Kinds read across the whole period at once: the hover answers for every series at the hovered label. */
    private static function across(ChartKind $kind): bool
    {
        return \in_array($kind, [ChartKind::StackedArea, ChartKind::Band, ChartKind::MultiAxisLine, ChartKind::StackedBarLine, ChartKind::StackedPanels], true);
    }

    private static function cat(int $n): string
    {
        return \sprintf('var(--cat-%d)', $n);
    }

    /** A token at a strength, written as the design writes a faded colour; a value that is no token is handed on as it is. */
    private static function mix(string $token, int $percent): string
    {
        return 1 === preg_match('/^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/', $token, $name)
            ? \sprintf('color-mix(in srgb, var(%s) %d%%, transparent)', $name[1], $percent)
            : $token;
    }

    /**
     * What a series wears: the accent, a stated token, or its category —
     * stated, or its position in the palette.
     */
    private static function colourOf(ChartSeries $series, int $position): string
    {
        return $series->accent ? self::ACCENT : $series->swatch ?? self::cat($series->cat ?? ($position % self::CATEGORIES) + 1);
    }

    /** What part `$index` of a whole wears; unstated, its position in the palette. */
    private static function partOf(AtlasChart $chart, int $index): ChartPart
    {
        return $chart->parts[$index] ?? new ChartPart();
    }

    private static function partColour(AtlasChart $chart, int $index): string
    {
        $part = self::partOf($chart, $index);

        return $part->swatch ?? self::cat($part->cat ?? ($index % self::CATEGORIES) + 1);
    }

    private static function marker(ChartSeries $series, int $position): string
    {
        return ($series->marker ?? self::SHAPES[$position % \count(self::SHAPES)])->value;
    }

    private static function drawnSmall(string $style): bool
    {
        return \in_array(PointMarker::tryFrom($style), self::DRAWN_SMALL, true);
    }

    private static function value(float|ChartPoint|ChartSpan|ChartReadings|null $point): ?float
    {
        return \is_float($point) ? $point : null;
    }

    /**
     * @param list<float|ChartPoint|ChartSpan|ChartReadings|null> $points
     *
     * @return list<mixed>
     */
    private static function data(array $points): array
    {
        return array_map(static fn (float|ChartPoint|ChartSpan|ChartReadings|null $point): mixed => match (true) {
            $point instanceof ChartPoint => ['x' => $point->x, 'y' => $point->y],
            $point instanceof ChartSpan => [$point->from, $point->to],
            $point instanceof ChartReadings => $point->readings,
            default => $point,
        }, $points);
    }

    /** @return array<string, mixed> */
    private static function dataset(ChartSeries $series, int $position, AtlasChart $chart): array
    {
        $kind = $chart->kind;
        $colour = self::colourOf($series, $position);
        $several = \count($chart->series) > 1;
        $points = $series->points;
        $set = ['label' => $series->label, 'data' => self::data($points)];
        if ('' !== $series->unit) {
            $set['unit'] = $series->unit;
        }
        if (ChartSide::Right === $series->axis) {
            $set['yAxisID'] = 'y1';
        }

        if (self::arcs($kind)) {
            $set['backgroundColor'] = array_map(static fn (int $i): string => self::partColour($chart, $i), array_keys($chart->labels));
            $set['borderColor'] = self::SURFACE;
            $set['borderWidth'] = 2;
            $set['hoverOffset'] = 0;
            $textures = array_map(static fn (int $i): ?string => self::partOf($chart, $i)->texture?->value, array_keys($chart->labels));
            if ([] !== array_filter($textures)) {
                $set['texture'] = $textures;
            }
            if (ChartKind::NestedDoughnut === $kind) {
                $set['labels'] = $chart->labels;
            }
            if (ChartKind::Gauge === $kind) {
                // ONE VALUE AGAINST ITS CAPACITY: half a doughnut, opening down.
                $set['circumference'] = 180;
                $set['rotation'] = -90;
            }

            return $set;
        }

        if (ChartKind::PolarArea === $kind) {
            // ONE MEASURE AROUND A CYCLE: every sector one colour, the
            // magnitude read from the radius, the surface drawing the gaps.
            $set['backgroundColor'] = $colour;
            $set['borderColor'] = self::SURFACE;
            $set['borderWidth'] = 2;
            if (ChartFill::Radial === $chart->fill) {
                $set['gradient'] = ['radial' => true, 'from' => self::mix($colour, self::RADIAL_INNER), 'to' => $colour];
            }

            return $set;
        }

        if (ChartKind::StackedPanels === $kind) {
            // THE PANE IT IS DRAWN IN, as bars or as a line.
            $set['yAxisID'] = 1 === $series->pane ? 'y2' : 'y';
            $set['borderColor'] = $colour;
            $set['backgroundColor'] = $colour;
            if ($series->bar) {
                $set['type'] = Chart::TYPE_BAR;
                $set['borderWidth'] = 0;
                $set['borderRadius'] = $chart->barRadius ?? 0;
                $set['borderSkipped'] = false;
            } else {
                $set['type'] = Chart::TYPE_LINE;
                $set['tension'] = ChartCurve::Gentle->tension();
                $set['fill'] = false;
                $set['borderWidth'] = self::LINE_WIDTH;
                $set['pointRadius'] = \count($points) > self::DENSE_RUN ? 0 : self::SHAPED_DOT;
                $set['pointStyle'] = self::marker($series, $position);
                $set['pointBackgroundColor'] = $colour;
                $set['pointBorderColor'] = $colour;
            }

            return $set;
        }

        if (ChartKind::Radar === $kind) {
            $set['borderColor'] = $colour;
            $set['backgroundColor'] = self::mix($colour, self::AREA_FILL);
            $set['pointBackgroundColor'] = $colour;
            $set['pointBorderColor'] = self::SURFACE;
            $set['pointRadius'] = 3;
            $set['borderWidth'] = self::LINE_WIDTH;
            $set['fill'] = true;
            if ($several) {
                $set['pointStyle'] = self::marker($series, $position);
            }
            // A MEASURE NOT TAKEN is a gap in the ring, never a nought.
            $set['spanGaps'] = false;

            return $set;
        }

        if (self::points($kind)) {
            if (ChartKind::Bubble === $kind) {
                // THE THIRD MEASURE IS THE AREA, so the radius is its square
                // root, scaled so the largest item is the largest bubble.
                $sizes = array_map(static fn (mixed $p): float => $p instanceof ChartPoint ? $p->size ?? 0.0 : 0.0, $points);
                $largest = [] === $sizes ? 0.0 : max($sizes);
                $set['data'] = array_map(static fn (mixed $p): array => $p instanceof ChartPoint ? [
                    'x' => $p->x,
                    'y' => $p->y,
                    'r' => $largest > 0.0 ? max(self::BUBBLE_MIN_RADIUS, self::BUBBLE_MAX_RADIUS * sqrt(($p->size ?? 0.0) / $largest)) : self::BUBBLE_MIN_RADIUS,
                    'size' => $p->size,
                ] : [], $points);
                $set['backgroundColor'] = self::mix($colour, self::BUBBLE_FILL);
                $set['borderColor'] = self::SURFACE;
                $set['borderWidth'] = 1.5;
            } else {
                $set['backgroundColor'] = $colour;
                $set['borderColor'] = self::SURFACE;
                $set['borderWidth'] = 1;
                $style = $several ? self::marker($series, $position) : $series->marker->value ?? PointMarker::Circle->value;
                $set['pointStyle'] = $style;
                // A TRIANGLE, A SQUARE OR A DIAMOND reads smaller than a circle of the same radius.
                $set['pointRadius'] = self::drawnSmall($style) ? self::POINT_SHAPED : self::POINT_RADIUS;
                $set['pointHoverRadius'] = $set['pointRadius'] + 1;
            }

            return $set;
        }

        $set['backgroundColor'] = $colour;
        $set['borderColor'] = $colour;
        $set['borderWidth'] = 1.5;

        if (self::lines($kind)) {
            // A GENTLE CURVE, NOT A SPLINE — and a level that changes at
            // events is no curve at all: it holds, then steps.
            $set['tension'] = ChartKind::Stepped === $kind ? 0 : ChartCurve::Gentle->tension();
            $curve = $series->curve ?? $chart->curve;
            if (null !== $curve) {
                $set['tension'] = $curve->tension();
                $set['cubicInterpolationMode'] = ChartCurve::Monotone === $curve ? 'monotone' : 'default';
            }
            $set['borderWidth'] = self::LINE_WIDTH;
            $set['fill'] = ChartKind::Area === $kind ? 'origin' : false;
            $set['backgroundColor'] = ChartKind::Area === $kind ? self::mix($colour, self::AREA_FILL) : 'transparent';
            if (ChartKind::StackedArea === $kind) {
                // EACH LAYER FILLS DOWN TO THE ONE BELOW IT, the first to the floor.
                $set['fill'] = 0 === $position ? 'origin' : '-1';
                $set['backgroundColor'] = self::mix($colour, self::STACK_FILL);
            }
            $set['pointRadius'] = \count($points) > self::DENSE_RUN ? 0 : ($several ? self::SHAPED_DOT : self::DOT);
            $set['pointBackgroundColor'] = $colour;
            $set['pointBorderColor'] = $colour;
            if ($several || null !== $series->marker) {
                $set['pointStyle'] = self::marker($series, $position);
                if (0 !== $set['pointRadius'] && self::drawnSmall($set['pointStyle'])) {
                    $set['pointRadius'] += self::SHAPE_STEP;
                }
            }
            if ($series->dash) {
                $set['borderDash'] = self::REFERENCE_DASH;
            }
            if (ChartKind::Stepped === $kind) {
                $set['stepped'] = $series->stepped->value ?? true;
            }
            if (ChartKind::Area === $kind && ChartFill::Gradient === $chart->fill) {
                $set['gradient'] = ['from' => self::mix($colour, self::GRADIENT_TOP), 'to' => self::mix($colour, 0)];
            }
            if (ChartGaps::Dashed === $chart->gaps) {
                // A READING THAT NEVER CAME is crossed dashed in the muted
                // ink; the plate draws the run as a segment style.
                $set['spanGaps'] = true;
                $set['gapStyle'] = ['dash' => self::REFERENCE_DASH, 'color' => self::MUTED];
            }
        } elseif (ChartKind::StackedBarLine === $kind && null !== $series->line) {
            // THE RATE OVER THE PARTS: a full line with its own marker, drawn on top.
            $set['type'] = Chart::TYPE_LINE;
            $set['tension'] = ChartCurve::Gentle->tension();
            $set['fill'] = false;
            $set['borderWidth'] = self::LINE_WIDTH;
            $set['backgroundColor'] = $colour;
            $set['pointRadius'] = self::SHAPED_DOT;
            $set['pointStyle'] = ($series->marker ?? PointMarker::RectRot)->value;
            $set['pointBackgroundColor'] = $colour;
            $set['pointBorderColor'] = $colour;
            $set['order'] = 0;
        } elseif (null !== $series->line) {
            // A LINE OVER THE BARS: the normal the bars are read against is
            // dashed; a second measurement is solid.
            $set['type'] = Chart::TYPE_LINE;
            $set['tension'] = ChartCurve::Gentle->tension();
            $set['fill'] = false;
            $set['pointRadius'] = 0;
            $set['borderWidth'] = self::OVERLAY_WIDTH;
            $set['backgroundColor'] = 'transparent';
            if (LineStyle::Dashed === $series->line) {
                $set['borderDash'] = self::REFERENCE_DASH;
            }
        }

        if (null !== $series->marked) {
            // ONE POINT WEARS THE DOT, the rest of the line none.
            $marked = $series->marked;
            $set['pointRadius'] = array_map(static fn (int $i): int => $i === $marked ? self::MARK_RADIUS : 0, array_keys($points));
            $set['pointBackgroundColor'] = $colour;
            $set['pointBorderColor'] = self::MARK_RING;
            $set['pointBorderWidth'] = 2;
        }

        if (self::stacked($kind) && null === $series->line) {
            if (ChartKind::StackedBarLine === $kind) {
                $set['order'] = 1;
            }
            $set['stack'] = 'one';
            // PARTS OF ONE BAR ARE SEPARATED BY THE SURFACE, not by their hue alone.
            $set['borderColor'] = self::SURFACE;
            $set['borderWidth'] = 1;
        }

        if (null !== $series->texture) {
            $set['texture'] = $series->texture->value;
        }

        if (null !== $chart->threshold && null === $series->line) {
            // A COLOUR PER BAR, OR PER POINT ON A LINE: past the value the
            // token above, the rest the token below or their own colour.
            $limit = $chart->threshold;
            $past = static fn (float|ChartPoint|ChartSpan|ChartReadings|null $v): bool => null !== self::value($v) && self::value($v) > $limit->value;
            $below = $limit->below ?? $colour;
            if (self::lines($kind)) {
                $set['pointBackgroundColor'] = array_map(static fn (mixed $v): string => $past($v) ? $limit->above : $below, $points);
                $set['pointBorderColor'] = array_map(static fn (mixed $v): string => $past($v) ? self::MARK_RING : $below, $points);
                $set['pointBorderWidth'] = array_map(static fn (mixed $v): float => $past($v) ? 1.5 : 0.0, $points);
                $set['pointRadius'] = array_map(static fn (mixed $v): int|float => $past($v) ? self::MARK_RADIUS + 0.5 : self::DOT, $points);
            } else {
                $set['backgroundColor'] = array_map(static fn (mixed $v): string => $past($v) ? $limit->above : $below, $points);
                $set['borderColor'] = $set['backgroundColor'];
            }
        }

        if (null !== $chart->decimation) {
            // A LONG DEVICE SERIES, handed to the library unparsed so its
            // decimation plugin can thin it.
            $set['parsing'] = false;
            $set['indexAxis'] = 'x';
            $set['pointRadius'] = 0;
            $set['pointHoverRadius'] = 3;
        }

        if (!self::lines($kind) && null === $series->line) {
            if (ChartNoughts::Hairline === $chart->noughts) {
                // A nought, whose value is the base, is moved half this length
                // and clamped inside the scale, so the stub stands on the axis
                // (chart.js 4.5.1, BarController::_calculateBarValuePixels).
                $set['minBarLength'] = self::HAIRLINE;
            }
            if (null !== $chart->barWidth) {
                $set['maxBarThickness'] = $chart->barWidth;
            }
            if (null !== $chart->barRadius || ChartNoughts::Hairline === $chart->noughts) {
                // ONE RADIUS PER BAR, the stub's where the bar is a nought;
                // `borderSkipped: false` rounds the corners on the axis too.
                $set['borderRadius'] = array_map(
                    static fn (mixed $point): float => ChartNoughts::Hairline === $chart->noughts && 0.0 === $point ? self::HAIRLINE_RADIUS : $chart->barRadius ?? 0.0,
                    $points,
                );
                $set['borderSkipped'] = false;
            }
        }

        return $set;
    }

    /**
     * THE TARGET IS A FACT: a flat dashed line across the whole axis — on a
     * radar the same ring all the way round — drawn as a series of its own
     * so it carries a legend row and a hover like everything else.
     *
     * @return array<string, mixed>
     */
    private static function target(AtlasChart $chart): array
    {
        $line = [
            'label' => $chart->targetLabel,
            'data' => array_fill(0, \count($chart->labels), $chart->target),
            'borderColor' => self::TARGET_INK,
            'backgroundColor' => 'transparent',
            'borderDash' => self::TARGET_DASH,
            'borderWidth' => 1.5,
            'pointRadius' => 0,
            'fill' => false,
        ];
        if (ChartKind::Radar !== $chart->kind) {
            $line['type'] = Chart::TYPE_LINE;
        }

        return $line;
    }

    /**
     * A RANGE AROUND THE LINE: two edges drawn as datasets of their own, the
     * upper filled down to the lower in the line's colour. The lower edge
     * carries no legend row (the plate's filter drops it); the upper carries
     * the band's name; each answers the hover with its own word.
     *
     * @return list<array<string, mixed>>
     */
    private static function band(AtlasChart $chart): array
    {
        $band = $chart->band;
        if (null === $band || [] === $chart->series) {
            return [];
        }
        $colour = self::colourOf($chart->series[0], 0);
        $edge = static fn (array $points, string $word): array => [
            'label' => $band->label,
            'edgeLabel' => $word,
            'data' => $points,
            'borderColor' => 'transparent',
            'backgroundColor' => self::mix($colour, self::AREA_FILL),
            'borderWidth' => 0,
            'pointRadius' => 0,
            'pointHoverRadius' => 0,
            'tension' => ChartCurve::Gentle->tension(),
            'unit' => $chart->unit,
        ];

        return [
            ['fill' => false, 'legendHidden' => true] + $edge($band->from, $band->fromLabel),
            ['fill' => '-1'] + $edge($band->to, $band->toLabel),
        ];
    }

    /**
     * A NESTED DOUGHNUT'S OUTER RING: the children, each in its parent's
     * colour at a tint, so the hue says which kind and the name beside the
     * arc says which part.
     *
     * @return array<string, mixed>
     */
    private static function outerRing(AtlasChart $chart): array
    {
        return [
            'label' => $chart->childLabel,
            'labels' => array_map(static fn (ChartChild $child): string => $child->label, $chart->children),
            'data' => array_map(static fn (ChartChild $child): float => $child->value, $chart->children),
            'backgroundColor' => array_map(static fn (ChartChild $child): string => self::mix(self::partColour($chart, $child->of), self::OUTER_TINT), $chart->children),
            'borderColor' => self::SURFACE,
            'borderWidth' => 2,
            'hoverOffset' => 0,
            'unit' => $chart->unit,
        ];
    }

    /** @return array<string, mixed> */
    private static function font(float|int $size, int $weight): array
    {
        return ['family' => self::MONO, 'size' => $size, 'weight' => $weight];
    }

    /** @return array<string, mixed> */
    private static function ticks(): array
    {
        return ['color' => self::MUTED, 'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT), 'padding' => self::TICK_GAP];
    }

    /** @return array<string, mixed> */
    private static function axisTitle(string $text): array
    {
        return '' === $text
            ? ['display' => false]
            : ['display' => true, 'text' => mb_strtoupper($text), 'color' => self::MUTED, 'font' => self::font(self::TITLE_SIZE, self::TITLE_WEIGHT), 'padding' => ['top' => 6, 'bottom' => 2]];
    }

    /** @return array<string, mixed> */
    private static function options(AtlasChart $chart): array
    {
        $kind = $chart->kind;
        $series = $chart->series;
        $sideways = self::sideways($kind);
        $plugins = [];
        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            // A PAGE THAT MOVES WHILE IT IS BEING READ is a page nobody can
            // compare two figures on.
            'animation' => false,
        ];
        if (self::across($kind)) {
            $options['interaction'] = ['mode' => 'index', 'intersect' => false];
        }

        // THE LEGEND: the library's own, at the foot, where more than one
        // thing is plotted — on a whole, where it has more than one part. A
        // cycle's sectors are named round its rim, so a polar area has none.
        $many = match (true) {
            ChartKind::PolarArea === $kind => false,
            self::arcs($kind) => \count($chart->labels) > 1,
            default => \count($series) > 1 || null !== $chart->target || null !== $chart->band,
        };
        $shaped = (self::lines($kind) || \in_array($kind, [ChartKind::Scatter, ChartKind::ScatterMultiAxis, ChartKind::Radar], true)) && \count($series) > 1;
        $labels = [
            'color' => self::MUTED,
            'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT),
            'boxWidth' => 8,
            'boxHeight' => 8,
            'padding' => 12,
            'usePointStyle' => $shaped,
        ];
        if ($shaped) {
            $labels['pointStyleWidth'] = 10;
        }
        if (ChartKind::Band === $kind) {
            $labels['atlasFilter'] = 'legendHidden';
        }
        if (ChartKind::NestedDoughnut === $kind) {
            // The legend names the inner ring (the kinds); the outer ring's
            // parts are named beside their arcs.
            $labels['atlasRing'] = 1;
        }
        $plugins['legend'] = [
            'display' => ChartLegend::Canvas === $chart->legend && $many && ChartKind::Gauge !== $kind,
            'position' => 'bottom',
            'labels' => $labels,
        ];

        // A HOVER ANSWERS WHAT A FIGURE DOES NOT; the plate writes its words.
        $plugins['tooltip'] = [
            'enabled' => true,
            'backgroundColor' => 'var(--raised)',
            'borderColor' => self::STRONG_LINE,
            'borderWidth' => 1,
            'titleColor' => self::INK,
            'bodyColor' => self::INK,
            'titleFont' => self::font(self::TOOLTIP_SIZE, 600),
            'bodyFont' => self::font(self::TOOLTIP_SIZE, 400),
            'padding' => 8,
            'cornerRadius' => 7,
            'boxWidth' => 8,
            'boxHeight' => 8,
            'boxPadding' => 4,
            'usePointStyle' => $shaped,
            'unit' => $chart->unit,
            'xUnit' => $chart->xAxis->unit ?? '',
            'yUnit' => $chart->yAxis->unit ?? '',
            'sizeLabel' => $chart->sizeLabel,
            'clock' => $chart->clock,
        ];

        if (self::matrix($kind) || \in_array($kind, [ChartKind::Treemap, ChartKind::Sankey, ChartKind::Funnel], true)) {
            return self::extendedOptions($chart, $options, $plugins);
        }

        if (self::arcs($kind)) {
            $options['cutout'] = match ($kind) {
                ChartKind::Pie => 0,
                ChartKind::Gauge => '72%',
                ChartKind::NestedDoughnut => '38%',
                default => '68%',
            };
            if (null !== $chart->empty) {
                $plugins[self::EMPTY_PLUGIN] = ['caption' => $chart->empty, 'ring' => self::GRID, 'muted' => self::MUTED, 'family' => self::MONO, 'cutout' => 0.68];
                // NOTHING IN THE WHOLE, NOTHING TO KEY: no legend.
                $held = false;
                foreach ($series as $one) {
                    foreach ($one->points as $point) {
                        $held = $held || (\is_float($point) && $point > 0.0);
                    }
                }
                if (!$held) {
                    $plugins['legend']['display'] = false;
                }
            }
            if (null !== $chart->centre) {
                $plugins[self::CENTRE_PLUGIN] = ['figure' => $chart->centre->figure, 'caption' => $chart->centre->caption, 'ink' => self::INK, 'muted' => self::MUTED, 'family' => self::MONO];
            }
            if (null !== $chart->figures) {
                $plugins[self::FIGURES_PLUGIN] = self::figures($chart->figures) + ['names' => true, 'muted' => self::MUTED, 'only' => ChartKind::NestedDoughnut === $kind ? 0 : null];
                $options['layout'] = ['padding' => self::ARC_ROOM];
            }
            $options['plugins'] = $plugins;

            return $options;
        }

        if (ChartKind::PolarArea === $kind || ChartKind::Radar === $kind) {
            $r = [
                'beginAtZero' => true,
                'grid' => ['color' => self::GRID],
                'ticks' => ['color' => self::MUTED, 'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT), 'backdropColor' => 'transparent', 'showLabelBackdrop' => false, 'z' => 1],
                'pointLabels' => ['color' => self::MUTED, 'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT)],
            ];
            if (ChartKind::Radar === $kind) {
                $r['angleLines'] = ['color' => self::GRID];
            } else {
                $r['pointLabels']['display'] = true;
                $r['pointLabels']['centerPointLabels'] = true;
            }
            if (null !== $chart->axis) {
                $r['max'] = $chart->axis->max;
                if (null !== $chart->axis->step) {
                    $r['ticks']['stepSize'] = $chart->axis->step;
                }
            }
            $options['scales'] = ['r' => $r];
            $options['plugins'] = $plugins;

            return $options;
        }

        if (self::points($kind)) {
            // TWO MEASURES, BOTH OF THEM VALUES: a grid on each axis, each
            // titled with what it measures.
            $x = self::measureAxis($chart->xAxis);
            $y = self::measureAxis($chart->yAxis);
            if (ChartAxes::Centre === $chart->axes) {
                foreach ([&$x, &$y] as &$axis) {
                    $axis['position'] = 'center';
                    $axis['border'] = ['display' => true, 'color' => self::STRONG_LINE, 'width' => 1];
                    // The library sets a title on its ticks; the plate sets it at the axis's end.
                    $axis['title'] = ['display' => false];
                }
                unset($axis);
                $plugins[self::AXIS_ENDS_PLUGIN] = [
                    'x' => $chart->xAxis->title ?? '',
                    'y' => $chart->yAxis->title ?? '',
                    'ink' => self::MUTED,
                    'family' => self::MONO,
                    'size' => self::TICK_SIZE,
                    'weight' => self::TICK_WEIGHT,
                    'tickPadding' => self::TICK_GAP,
                ];
            }
            $options['scales'] = ['x' => $x, 'y' => $y];
            if (ChartKind::ScatterMultiAxis === $kind) {
                $options['scales']['y1'] = self::rightAxis($chart->y2Axis);
            }
            if (null !== $chart->quadrants) {
                $plugins[self::QUADRANTS_PLUGIN] = [
                    'x' => $chart->quadrants->x,
                    'y' => $chart->quadrants->y,
                    'parts' => array_map(static fn (ChartQuadrant $part): array => [
                        'color' => $part->swatch ?? (null !== $part->cat ? self::cat($part->cat) : 'transparent'),
                        'label' => $part->label,
                    ], $chart->quadrants->parts),
                    'opacity' => self::RANGE_OPACITY,
                    'ink' => self::MUTED,
                    'line' => self::STRONG_LINE,
                    'family' => self::MONO,
                ];
            }
            self::frame($chart, $plugins);
            $options['plugins'] = $plugins;

            return $options;
        }

        // THE INDEX AXIS CARRIES THE NAMES AND NO GRID; THE VALUE AXIS STARTS
        // AT NOUGHT AND CARRIES THE GRID, which is the whole frame.
        $index = [
            'grid' => ['display' => false],
            'border' => ['display' => false],
            'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 8] + self::ticks(),
        ];
        if ($sideways || (self::upright($kind) && null === $chart->time)) {
            // EVERY BAR NAMED: a bar a reader cannot name is no bar.
            $index['ticks']['autoSkip'] = false;
            $index['ticks']['maxRotation'] = 0;
        }
        $value = [
            'beginAtZero' => ChartKind::Diverging !== $kind,
            'grid' => ['color' => self::GRID, 'lineWidth' => 1, 'drawTicks' => false],
            'border' => ['display' => false],
            'ticks' => self::ticks(),
        ];
        if (ChartKind::Diverging === $kind) {
            // NOUGHT IS THE LINE THE BARS LEAVE FROM, a step stronger than the rest.
            $value['grid']['zeroColor'] = self::STRONG_LINE;
        }
        if (null !== $chart->axis) {
            $value['max'] = $chart->axis->max;
            if (null !== $chart->axis->step && ChartScale::Log !== $chart->scale) {
                $value['ticks']['stepSize'] = $chart->axis->step;
            }
            if (null !== $chart->axis->min) {
                $value['min'] = $chart->axis->min;
            }
        }
        if ('' !== ($chart->yAxis->title ?? '')) {
            $value['title'] = self::axisTitle($chart->yAxis->title ?? '');
        }
        if ($chart->clock) {
            $value['ticks']['atlasClock'] = true;
        }
        if (ChartKind::StackedArea === $kind) {
            $value['stacked'] = true;
        }
        if (null !== $chart->time) {
            $index = self::timeAxis($chart->time, $chart, $index);
        }
        if (ChartScale::Log === $chart->scale) {
            // A GRIDLINE AND A FIGURE AT EACH POWER OF TEN ONLY: the plate
            // puts the functions on.
            $value['type'] = 'logarithmic';
            $value['beginAtZero'] = false;
            $value['ticks']['atlasLog'] = true;
            $value['grid']['atlasLog'] = true;
        }
        if (null !== $chart->decimation) {
            // The library thins a run only on a linear or time x axis — here
            // the time axis every decimated run states — from data it does
            // not parse.
            $options['parsing'] = false;
            $options['normalized'] = true;
            $plugins['decimation'] = ['enabled' => $chart->decimation->enabled, 'algorithm' => $chart->decimation->algorithm->value];
            if (null !== $chart->decimation->samples) {
                $plugins['decimation']['samples'] = $chart->decimation->samples;
            }
            $options['interaction'] = ['mode' => 'nearest', 'axis' => 'x', 'intersect' => false];
        }
        if (null !== $chart->panel) {
            // BOTH PANELS OF A PAIR PLACE THEIR CATEGORIES ALIKE, so the same
            // week lands on the same pixel in either.
            $index['offset'] = true;
        }
        if (ChartPanel::Upper === $chart->panel) {
            // The lower panel, on the same labels, writes the names once for both.
            $index['ticks']['display'] = false;
        }
        if (self::stacked($kind)) {
            $index['stacked'] = true;
            $value['stacked'] = true;
        }
        $options['scales'] = $sideways ? ['x' => $value, 'y' => $index] : ['x' => $index, 'y' => $value];
        foreach ($series as $one) {
            if (ChartSide::Right === $one->axis) {
                $options['scales']['y1'] = self::rightAxis($chart->y2Axis);
                break;
            }
        }
        if (ChartKind::StackedPanels === $kind && 2 === \count($chart->panes)) {
            // TWO VALUE AXES STACKED ON THE LEFT, one per pane, under one index
            // axis. Chart.js stacks axes bottom up in the order they are
            // declared: the second pane's first, so the first sits on top.
            $index['offset'] = true;
            $options['scales'] = ['x' => $index, 'y2' => self::paneAxis($chart->panes[1]), 'y' => self::paneAxis($chart->panes[0])];
        }
        self::frame($chart, $plugins);
        if ($sideways) {
            $options['indexAxis'] = 'y';
        }

        if (ChartNoughts::Hairline === $chart->noughts && !self::lines($kind)) {
            $plugins[self::NOUGHTS] = ['opacity' => self::HAIRLINE_OPACITY];
        }
        if (null !== $chart->panel) {
            // Applied by the plate in the scale's `afterFit`, which no JSON can carry.
            $plugins[self::PANELS_PLUGIN] = ['axisWidth' => self::PANEL_AXIS_WIDTH];
        }
        if ([] !== $chart->ranges || null !== $chart->highlight) {
            $plugins[self::RANGES_PLUGIN] = [
                'ranges' => array_map(static fn (ChartRange $range): array => [
                    'from' => $range->from,
                    'to' => $range->to,
                    'label' => $range->label,
                    'color' => $range->accent ? self::ACCENT : $range->swatch ?? self::cat($range->cat ?? 1),
                ], $chart->ranges),
                'opacity' => self::RANGE_OPACITY,
                'highlight' => $chart->highlight,
                'highlightColor' => self::ACCENT,
                'highlightOpacity' => self::HIGHLIGHT_OPACITY,
                'family' => self::MONO,
            ];
        }
        if (null !== $chart->figures) {
            // ROOM PAST THE LONGEST BAR, or the canvas clips its figure.
            $plugins[self::FIGURES_PLUGIN] = self::figures($chart->figures);
            $options['layout'] = ['padding' => $sideways
                ? ['right' => self::FIGURE_ROOM_BESIDE]
                : ['top' => self::FIGURE_ROOM_ABOVE, 'bottom' => 0]];
            if (ChartKind::Diverging === $kind && null === $chart->axis) {
                // ROOM INSIDE THE PLOT PAST EACH END, so a figure outside a bar
                // that reaches the axis's end clears the names; a stated axis
                // is kept as stated.
                $options['scales']['x']['grace'] = self::FIGURE_GRACE;
            }
        }

        $options['plugins'] = $plugins;

        return $options;
    }

    /**
     * THE INDEX AXIS AS TIME: ticks at the labels where there are labels, at
     * the unit's boundaries where not (a decimated run); weeks start on
     * Monday; bars sit between their ticks, a line's points on them.
     *
     * @param array<string, mixed> $index
     *
     * @return array{type: string, time: array<string, mixed>, grid: mixed, border: mixed, ticks: array<string, mixed>, offset: bool}
     */
    private static function timeAxis(ChartTime $time, AtlasChart $chart, array $index): array
    {
        /** @var array<string, mixed> $ticks */
        $ticks = \is_array($index['ticks'] ?? null) ? $index['ticks'] : [];

        return [
            'type' => 'time',
            'time' => [
                'unit' => $time->unit->value,
                'displayFormats' => [$time->unit->value => $time->format ?? $time->unit->format()],
                'tooltipFormat' => $time->tooltip ?? $time->unit->tooltip(),
                'isoWeekday' => true,
            ],
            'grid' => $index['grid'],
            'border' => $index['border'],
            'ticks' => ['source' => [] === $chart->labels ? 'auto' : 'labels'] + $ticks,
            'offset' => !self::lines($chart->kind),
        ];
    }

    /**
     * One pane of a stacked-panels chart: its own ticks, grid and title.
     *
     * @return array<string, mixed>
     */
    private static function paneAxis(ChartPane $pane): array
    {
        $axis = [
            'type' => 'linear',
            'position' => 'left',
            'stack' => 'panes',
            'stackWeight' => $pane->weight,
            'offset' => true,
            'beginAtZero' => true,
            'grid' => ['color' => self::GRID, 'lineWidth' => 1, 'drawTicks' => false],
            'border' => ['display' => false],
            'ticks' => self::ticks(),
            'title' => self::axisTitle($pane->title),
        ];
        if (null !== $pane->max) {
            $axis['max'] = $pane->max;
        }
        if (null !== $pane->step) {
            $axis['ticks']['stepSize'] = $pane->step;
        }

        return $axis;
    }

    /**
     * THE EXTENSION KINDS' DATA — what each package's dataset is made of,
     * built from the statement as the core kinds are: tokens, never colours.
     *
     * @return array<string, mixed>
     */
    private static function extendedData(AtlasChart $chart): array
    {
        $first = $chart->series[0] ?? null;

        return match ($chart->kind) {
            ChartKind::Heatmap => ['labels' => [], 'datasets' => [self::heatmap($chart, $first)]],
            ChartKind::CalendarHeatmap => ['labels' => [], 'datasets' => [self::calendarHeatmap($chart, $first)]],
            ChartKind::Treemap => ['datasets' => [self::treemap($chart)]],
            ChartKind::Sankey => ['datasets' => [self::sankey($chart, $first)]],
            ChartKind::Funnel => ['labels' => $chart->labels, 'datasets' => [self::funnel($chart, $first)]],
            default => ['labels' => $chart->labels, 'datasets' => array_map(
                static fn (ChartSeries $series, int $position): array => self::distribution($series, $position, $chart),
                $chart->series,
                array_keys($chart->series),
            )],
        };
    }

    /**
     * A VALUE AT EACH LABEL (the columns) OF EACH ROW, the cell coloured by
     * the ramp's band its value falls in.
     *
     * @return array<string, mixed>
     */
    private static function heatmap(AtlasChart $chart, ?ChartSeries $first): array
    {
        $cells = [];
        foreach ($chart->rows as $y => $row) {
            $values = $chart->values[$y] ?? [];
            foreach ($chart->labels as $x => $column) {
                $cells[] = ['x' => $column, 'y' => $row, 'v' => \is_array($values) ? $values[$x] ?? null : null, 't' => $row.' '.$column];
            }
        }

        return self::cells($chart, $first, $cells, \count($chart->labels), \count($chart->rows), false);
    }

    /**
     * ONE VALUE PER DAY FROM THE START, laid out as a calendar: a column per
     * week, Monday first; a row per weekday.
     *
     * @return array<string, mixed>
     */
    private static function calendarHeatmap(AtlasChart $chart, ?ChartSeries $first): array
    {
        [$days, $weeks] = self::calendar($chart);

        return self::cells($chart, $first, $days, \count($weeks), \count(self::WEEKDAYS), true);
    }

    /**
     * @param list<array{x: string, y: string, v: mixed, t: string}> $cells
     *
     * @return array<string, mixed>
     */
    private static function cells(AtlasChart $chart, ?ChartSeries $first, array $cells, int $columns, int $rows, bool $square): array
    {
        [$colours] = self::ramp($chart->ramp);
        $breaks = $chart->ramp->breaks ?? [];

        return [
            'label' => $first->label ?? '',
            'data' => $cells,
            'backgroundColor' => array_map(static function (array $cell) use ($colours, $breaks): string {
                $v = $cell['v'];
                if (!\is_float($v) || 0.0 === $v) {
                    return $colours[0];
                }
                foreach ($breaks as $i => $top) {
                    if ($v <= $top) {
                        return $colours[$i + 1];
                    }
                }

                return $colours[\count($colours) - 1];
            }, $cells),
            'borderWidth' => 0,
            'borderRadius' => self::CELL_RADIUS,
            // A CELL'S SIZE is the plot's share per column and row, less the
            // gap; the plate works it out from the plot as it is drawn.
            'atlasCell' => ['columns' => $columns, 'rows' => $rows, 'gap' => self::CELL_GAP, 'square' => $square],
            'unit' => $chart->unit,
        ];
    }

    /**
     * THE RAMP'S COLOURS AND ITS BANDS' WORDS: nought in the grid ink, then
     * one step of the hue per band; the breaks are the top of each band but
     * the last.
     *
     * @return array{list<string>, list<string>}
     */
    private static function ramp(?ChartRamp $ramp): array
    {
        $token = $ramp->token ?? self::SEQUENTIAL;
        $colours = [self::GRID, ...array_map(static fn (int $p): string => 100 === $p ? $token : self::mix($token, $p), self::RAMP_STEPS)];
        $words = ['0'];
        $from = 1.0;
        foreach ($ramp->breaks ?? [] as $top) {
            $words[] = $top === $from ? self::number($top) : self::number($from).'–'.self::number($top);
            $from = $top + 1.0;
        }
        $words[] = self::number($from).'+';

        return [$colours, $words];
    }

    /** A figure as the canvas writes it: a whole number without its point. */
    private static function number(float $value): string
    {
        return floor($value) === $value && abs($value) < 1e15 ? \sprintf('%d', $value) : (string) $value;
    }

    /**
     * The days from the start, each placed by its week and weekday, and each
     * week's month name — over the week the month's first day falls in, and
     * over the first.
     *
     * @return array{list<array{x: string, y: string, v: mixed, t: string}>, list<string>}
     */
    private static function calendar(AtlasChart $chart): array
    {
        $utc = new \DateTimeZone('UTC');
        $origin = new \DateTimeImmutable(($chart->start ?? new \DateTimeImmutable('today', $utc))->format('Y-m-d'), $utc);
        $offset = (int) $origin->format('N') - 1;
        $days = [];
        $weeks = [];
        foreach ($chart->values as $i => $v) {
            $day = $origin->modify(\sprintf('+%d days', $i));
            $column = intdiv($i + $offset, 7);
            $weekday = self::WEEKDAYS[(int) $day->format('N') - 1];
            while (\count($weeks) <= $column) {
                $weeks[] = '';
            }
            if ('1' === $day->format('j') || 0 === $i) {
                $weeks[$column] = $day->format('M');
            }
            $days[] = ['x' => (string) $column, 'y' => $weekday, 'v' => $v, 't' => $weekday.' '.$day->format('j M Y')];
        }

        return [$days, array_values($weeks)];
    }

    /**
     * THE NESTED DOUGHNUT'S STATEMENT AS NESTED RECTANGLES: the labels are the
     * groups, the children the leaves, each leaf in its group's hue at the
     * outer ring's tint and each group's caption behind a fainter one.
     *
     * @return array<string, mixed>
     */
    private static function treemap(AtlasChart $chart): array
    {
        $group = [];
        $leaf = [];
        foreach ($chart->labels as $i => $label) {
            $group[$label] = self::mix(self::partColour($chart, $i), self::TREE_GROUP);
            $leaf[$label] = self::mix(self::partColour($chart, $i), self::TREE_LEAF);
        }

        return [
            'label' => $chart->childLabel,
            'tree' => array_map(static fn (ChartChild $child): array => ['kind' => $chart->labels[$child->of] ?? '', 'part' => $child->label, 'value' => $child->value], $chart->children),
            'key' => 'value',
            'groups' => ['kind', 'part'],
            'spacing' => self::TREE_GAP / 2,
            'borderWidth' => 0,
            'borderRadius' => self::CELL_RADIUS,
            'captions' => ['display' => true, 'color' => self::INK, 'font' => self::font(self::TICK_SIZE, 700), 'padding' => 4],
            'labels' => ['display' => true, 'color' => self::INK, 'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT), 'overflow' => 'hidden', 'position' => 'middle'],
            'atlasTree' => ['group' => (object) $group, 'leaf' => (object) $leaf, 'names' => (object) $chart->names],
            'unit' => $chart->unit,
        ];
    }

    /**
     * FROM EACH LABEL (the sources, coloured as parts) TO EACH TARGET: a flow
     * wears its source's colour, a target node its own token. The nodes keep
     * the stated order down each column, which is how neighbouring flows are
     * kept apart by hue.
     *
     * @return array<string, mixed>
     */
    private static function sankey(AtlasChart $chart, ?ChartSeries $first): array
    {
        $data = [];
        $from = [];
        $to = [];
        foreach ($chart->labels as $i => $source) {
            foreach ($chart->targets as $j => $target) {
                $flow = $chart->flows[$i][$j] ?? 0.0;
                if ($flow > 0.0) {
                    $data[] = ['from' => $source, 'to' => $target->label, 'flow' => $flow];
                    $from[] = self::partColour($chart, $i);
                    $to[] = $target->swatch ?? self::cat($target->cat ?? 1);
                }
            }
        }
        $shown = [];
        $order = [];
        foreach ($chart->labels as $i => $label) {
            $shown[$label] = $chart->names[$label] ?? $label;
            $order[$label] = $i;
        }
        foreach ($chart->targets as $j => $target) {
            $shown[$target->label] = $target->label;
            $order[$target->label] = $j;
        }

        return [
            'label' => $first->label ?? '',
            'data' => $data,
            'colorMode' => 'from',
            'alpha' => self::FLOW_ALPHA,
            'borderWidth' => 0,
            'nodeWidth' => self::NODE_WIDTH,
            'nodePadding' => self::NODE_PADDING,
            'size' => 'max',
            'color' => self::INK,
            'font' => self::font(self::TICK_SIZE, self::TICK_WEIGHT),
            'labels' => (object) $shown,
            'priority' => (object) $order,
            'atlasFlows' => ['from' => $from, 'to' => $to],
            'unit' => $chart->unit,
        ];
    }

    /**
     * A DISTRIBUTION PER LABEL: every reading; the package works out the
     * quartiles (or the density) and the whiskers at 1.5 times the middle half.
     *
     * @return array<string, mixed>
     */
    private static function distribution(ChartSeries $series, int $position, AtlasChart $chart): array
    {
        $colour = self::colourOf($series, $position);
        $set = [
            'label' => $series->label,
            'data' => self::data($series->points),
            'backgroundColor' => self::mix($colour, self::BOX_FILL),
            'borderColor' => $colour,
            'borderWidth' => 1.5,
            'medianColor' => self::INK,
            'outlierBackgroundColor' => $colour,
            'outlierBorderColor' => $colour,
            'outlierRadius' => 2.5,
            'itemRadius' => 0,
            'meanRadius' => 0,
            'coef' => 1.5,
            'maxBarThickness' => 56,
            'unit' => '' !== $series->unit ? $series->unit : $chart->unit,
        ];
        if (ChartKind::Violin === $chart->kind) {
            $set['points'] = 100;
        }

        return $set;
    }

    /**
     * ONE MEASURE THROUGH ORDERED STAGES, each a step of one hue, the first
     * the strongest — an ordinal ramp, not a category per stage.
     *
     * @return array<string, mixed>
     */
    private static function funnel(AtlasChart $chart, ?ChartSeries $stages): array
    {
        $token = null === $stages ? self::cat(1) : self::colourOf($stages, 0);
        $points = $stages->points ?? [];

        return [
            'label' => $stages->label ?? '',
            'data' => self::data($points),
            'backgroundColor' => array_map(static function (int $i) use ($token): string {
                $p = self::STAGE_STEPS[min($i, \count(self::STAGE_STEPS) - 1)];

                return 100 === $p ? $token : self::mix($token, $p);
            }, array_keys($points)),
            'borderWidth' => 0,
            'shrinkAnchor' => 'top',
            'shrinkFraction' => 1,
            'unit' => $chart->unit,
        ];
    }

    /**
     * The options of the extension kinds that bring their own geometry.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plugins
     *
     * @return array<string, mixed>
     */
    private static function extendedOptions(AtlasChart $chart, array $options, array $plugins): array
    {
        $kind = $chart->kind;
        $house = \is_array($plugins['legend']) && \is_array($plugins['legend']['labels'] ?? null) ? $plugins['legend']['labels'] : [];
        if (self::matrix($kind)) {
            // A GRID OF CELLS on two category axes, no gridlines (the gaps are
            // the grid), and a legend of the ramp's bands.
            [$colours, $words] = self::ramp($chart->ramp);
            $weeks = ChartKind::CalendarHeatmap === $kind ? self::calendar($chart)[1] : null;
            $x = [
                'type' => 'category',
                'labels' => null === $weeks ? $chart->labels : array_map(strval(...), array_keys($weeks)),
                'offset' => true,
                'grid' => ['display' => false],
                'border' => ['display' => false],
                'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 8] + self::ticks(),
            ];
            $y = [
                'type' => 'category',
                'labels' => null === $weeks ? $chart->rows : self::WEEKDAYS,
                'offset' => true,
                'grid' => ['display' => false],
                'border' => ['display' => false],
                'ticks' => self::ticks(),
            ];
            if (null !== $weeks) {
                // The month over the week its first day falls in, every other
                // week unnamed — so no tick is skipped.
                $x['ticks']['autoSkip'] = false;
                $x['ticks']['atlasNames'] = $weeks;
            }
            $options['scales'] = ['x' => $x, 'y' => $y];
            $plugins['legend'] = [
                'display' => true,
                'position' => 'bottom',
                'labels' => $house + ['atlasRamp' => ['colours' => $colours, 'words' => $words, 'unit' => $chart->unit]],
            ];
        } elseif (ChartKind::Treemap === $kind) {
            // Every rectangle names itself and the groups their captions: no legend.
            $plugins['legend'] = ['display' => false];
        } elseif (ChartKind::Sankey === $kind) {
            $plugins['legend'] = ['display' => false];
            $options['layout'] = ['padding' => ['top' => 4, 'bottom' => 4, 'left' => 2, 'right' => 2]];
        } else {
            // A FUNNEL: the stages named down the left, the figure beside each.
            $options['indexAxis'] = 'y';
            $options['scales'] = [
                'x' => ['display' => false, 'beginAtZero' => false, 'grid' => ['display' => false]],
                'y' => ['display' => true, 'grid' => ['display' => false], 'border' => ['display' => false], 'ticks' => self::ticks()],
            ];
            $plugins['legend'] = ['display' => false];
            $plugins[self::FIGURES_PLUGIN] = ['unit' => $chart->figures->unit ?? '', 'precision' => 0];
            $options['layout'] = ['padding' => ['right' => self::STAGE_ROOM]];
        }
        $options['plugins'] = $plugins;

        return $options;
    }

    /** @return array<string, mixed> */
    private static function figures(ChartFigures $figures): array
    {
        $options = ['unit' => $figures->unit, 'precision' => $figures->precision];
        if ($figures->totals) {
            $options['totals'] = true;
        }
        if (null !== $figures->size) {
            $options['size'] = $figures->size;
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private static function measureAxis(?ChartAxis $stated): array
    {
        $axis = [
            'type' => 'linear',
            'beginAtZero' => true,
            'grid' => ['color' => self::GRID, 'drawTicks' => false],
            'border' => ['display' => false],
            'ticks' => self::ticks(),
            'title' => self::axisTitle($stated->title ?? ''),
        ];
        if (null !== $stated?->min) {
            $axis['min'] = $stated->min;
        }
        if (null !== $stated?->max) {
            $axis['max'] = $stated->max;
        }
        if (null !== $stated?->step) {
            $axis['ticks']['stepSize'] = $stated->step;
        }

        return $axis;
    }

    /**
     * THE SECOND MEASURE'S AXIS, on the right: its own ticks and title and no
     * grid of its own — one grid per chart.
     *
     * @return array<string, mixed>
     */
    private static function rightAxis(?ChartAxis $stated): array
    {
        $axis = [
            'type' => 'linear',
            'position' => 'right',
            'beginAtZero' => true,
            'grid' => ['display' => false],
            'border' => ['display' => false],
            'ticks' => self::ticks(),
            'title' => self::axisTitle($stated->title ?? ''),
        ];
        if (null !== $stated?->max) {
            $axis['max'] = $stated->max;
        }
        if (null !== $stated?->step) {
            $axis['ticks']['stepSize'] = $stated->step;
        }

        return $axis;
    }

    /**
     * THE PLOT'S BORDER, where a chart states one. The house does not: the
     * card is the border and the gridlines the frame.
     *
     * @param array<string, mixed> $plugins
     */
    private static function frame(AtlasChart $chart, array &$plugins): void
    {
        if ($chart->frame) {
            $plugins[self::FRAME_PLUGIN] = ['color' => self::STRONG_LINE, 'width' => 1, 'dash' => self::FRAME_DASH];
        }
    }
}
