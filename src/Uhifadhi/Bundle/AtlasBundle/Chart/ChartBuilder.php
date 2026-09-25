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
use Uhifadhi\Bundle\AtlasBundle\Model\ChartKind;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartNoughts;
use Uhifadhi\Bundle\AtlasBundle\Model\ChartSeries;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * WHAT A STATED CHART BECOMES — the one place the platform decides what a
 * chart looks like.
 *
 * THE MAP BUILDER'S SIBLING. Everything a module would otherwise have to
 * know about Chart.js is here and nowhere else: the type behind each
 * shape, the scales, the legend, the grid, the tension of a line, the
 * colours. A module states a kind and a series; two modules cannot
 * disagree about what a bar chart is.
 *
 * NO ANIMATION, AND NO ASPECT RATIO OF ITS OWN. A chart in a card is
 * given a box by the card, and one that animated on every render would
 * be a page that moves while it is being read.
 *
 * NULLS SURVIVE. Chart.js draws a gap where a point is null, which is
 * the truthful reading of a period nobody reported.
 *
 * THE TICKS, THE GRID AND THE AXIS LINE ARE THE HOUSE'S, on every chart.
 * The design's charts write their text in the mono face in `--fog`, their
 * grid in the fog at 22% and .6 wide, and their axis line in the fog at 55%
 * and .8 wide (team/overview.html, `.ch text`, `.ch line.grid`,
 * `.ch line.ax`). They cross as TOKENS — a faded line written as the design
 * writes it, `color-mix()` over the token — and chart_plate_controller.js
 * resolves each one where the chart is drawn, at mount and on a theme flip,
 * exactly as it resolves a series' category. No tooltips: the design draws
 * none.
 *
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#horizontal-bar-chart — a ranking is `indexAxis: 'y'`; "any options specified on the x-axis in a bar chart, are applied to the y-axis in a horizontal bar chart"
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/linear.html — `max` and `ticks.stepSize` on the value scale
 * @see https://www.chartjs.org/docs/latest/configuration/legend.html — `plugins.legend.display`
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — "Plugin options are located under the options.plugins config and are scoped by the plugin ID"
 * @see https://www.chartjs.org/docs/latest/configuration/layout.html — `layout.padding`, "The padding to add inside the chart"
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#dataset-properties — `minBarLength`, "Set this to ensure that bars have a minimum length in pixels"; `maxBarThickness`, "Set this to ensure that bars are not sized thicker than this"
 * @see chart.js 4.5.1 dist/chart.js, BarController::_calculateBarValuePixels — a bar shorter than `minBarLength` becomes that length; one whose value is the base moves half of it and is clamped inside the scale, so a nought's stub stands on the axis
 * @see https://www.chartjs.org/docs/latest/axes/styling.html#tick-configuration — `ticks.color`, "Color of ticks"; `ticks.font`; `ticks.padding`, "Sets the offset of the tick labels from the axis"
 * @see https://www.chartjs.org/docs/latest/axes/styling.html#grid-line-configuration — `grid.color`, `grid.lineWidth`, `grid.drawTicks`, "If true, draw lines beside the ticks in the axis area beside the chart"
 * @see https://www.chartjs.org/docs/latest/axes/styling.html#border-configuration — "options for the border that run perpendicular to the axis": `display`, `color`, `width`
 * @see https://www.chartjs.org/docs/latest/migration/v4-migration.html — "`scales[id].grid.drawBorder` has been renamed to `scales[id].border.display`"
 * @see https://www.chartjs.org/docs/latest/general/fonts.html — the font object's `family` and `size`
 * @see https://www.chartjs.org/docs/latest/configuration/tooltip.html — `options.plugins.tooltip.enabled`, "Are on-canvas tooltips enabled?"
 * @see https://www.chartjs.org/docs/latest/charts/bar.html#borderradius — "applied to all corners of the rectangle … except corners touching the borderSkipped"
 * @see chart.js 4.5.1 dist/chart.js, applyScaleDefaults() — the `scale` defaults the options above override: `grid.drawTicks`, `border.display`, `ticks.padding: 3`; Scale::drawBorder() draws `border` whatever `grid.display` says, so the index axis keeps its line with no grid
 */
final readonly class ChartBuilder
{
    /**
     * HOW MANY SERIES TAKE A CATEGORY OF THEIR OWN before the order
     * begins again — the palette's own count, so a chart and a zone key
     * beside it never disagree about how many distinct marks there are.
     *
     * THIS WAS SIX HEX COLOURS. They were picked once, they were right on
     * the night canvas and wrong on paper, and they were a seventh palette
     * beside the one the product has. A series is a CATEGORY now: the
     * caller states it or takes the next one in order, and the value is
     * resolved where the chart is drawn.
     */
    private const int CATEGORIES = PlatePalette::CATEGORIES;

    /**
     * THE ID OF THE PLATE'S OWN FIGURES PLUGIN — the inline plugin
     * chart_plate_controller.js puts on a chart whose options carry a block
     * under this key. Chart.js scopes a plugin's options by its id, so this
     * string is written here and read there, and nowhere else.
     */
    public const string FIGURES_PLUGIN = 'figures';

    /** Where the plate reads how faded a nought's hairline is drawn. */
    public const string NOUGHTS = 'noughts';

    /** A nought's stub, in pixels, and how far it is faded — the design's two-pixel column at .28. */
    private const int HAIRLINE = 2;
    private const float HAIRLINE_OPACITY = 0.28;

    /** The house accent, resolved by the plate like any category token. */
    private const string ACCENT = 'var(--acc)';

    /**
     * THE TICK FACE: the mono token at the size the design's chart text is
     * rendered at — 7.5 in a 640-wide viewBox drawn in the 574px card, 6.7px.
     * A canvas does not scale its text with its width the way an SVG does, so
     * the rendered size is the one carried over.
     */
    private const string TICK_FAMILY = 'var(--font-mono)';
    private const float TICK_SIZE = 6.7;
    private const string TICK_INK = 'var(--fog)';

    /** The gap between a tick and the axis, in pixels: the design's five. */
    private const int TICK_GAP = 5;

    /** The grid and the axis line, as the design writes them. */
    private const string GRID_INK = 'color-mix(in srgb, var(--fog) 22%, transparent)';
    private const float GRID_WIDTH = 0.6;
    private const string AXIS_INK = 'color-mix(in srgb, var(--fog) 55%, transparent)';
    private const float AXIS_WIDTH = 0.8;

    /** A nought's stub is rounded at one pixel whatever the bars are — the design's `rx="1"`. */
    private const float HAIRLINE_RADIUS = 1.0;

    /** THE ROOM A FIGURE NEEDS past the longest bar, in canvas pixels: "128 h" in the mono face at 10px. */
    private const int FIGURE_ROOM_BESIDE = 36;
    private const int FIGURE_ROOM_ABOVE = 16;

    public function __construct(private ChartBuilderInterface $charts)
    {
    }

    public function chart(AtlasChart $chart): Chart
    {
        $built = $this->charts->createChart(self::typeOf($chart->kind));

        $datasets = [];
        foreach ($chart->series as $position => $series) {
            $datasets[] = self::dataset($series, $position, $chart);
        }

        if (null !== $chart->target) {
            // THE TARGET IS A FLAT LINE ACROSS THE WHOLE AXIS, drawn as a
            // series of its own so it carries a legend row and a hover
            // like everything else on the chart.
            $datasets[] = [
                'label' => $chart->targetLabel,
                'data' => array_fill(0, \count($chart->labels), $chart->target),
                'type' => Chart::TYPE_LINE,
                'borderColor' => 'rgba(120,130,125,.85)',
                'borderDash' => [5, 4],
                'borderWidth' => 1.5,
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        $built->setData(['labels' => $chart->labels, 'datasets' => $datasets]);
        $built->setOptions(self::options($chart));

        return $built;
    }

    /** @return array<string, mixed> */
    private static function dataset(ChartSeries $series, int $position, AtlasChart $chart): array
    {
        $kind = $chart->kind;
        /*
         * THE TOKEN, NOT THE VALUE. Chart.js is handed `var(--cat-3)` and
         * the chart's own controller resolves it against the element it is
         * mounted on, at mount and again when the theme flips — the same
         * door the map plate's layers go through, for the same reason: a
         * colour decided in PHP is a colour that cannot follow a palette
         * it has never seen.
         *
         * A `swatch` still wins where a module states one, which is the
         * deprecated door and is honoured for one release.
         */
        $colour = $series->accent ? self::ACCENT : $series->swatch ?? \sprintf('var(--cat-%d)', $series->cat ?? ($position % self::CATEGORIES) + 1);

        $dataset = [
            'label' => $series->label,
            'data' => $series->points,
            'backgroundColor' => $colour,
            'borderColor' => $colour,
            'borderWidth' => 1.5,
        ];

        if (ChartKind::Line === $kind) {
            // A GENTLE CURVE, NOT A SPLINE: enough to read the shape,
            // never enough to invent a value between two months.
            $dataset['tension'] = 0.25;
            $dataset['fill'] = false;
            $dataset['pointRadius'] = 2;
            $dataset['backgroundColor'] = 'transparent';
        }

        if (ChartKind::Stacked === $kind) {
            $dataset['stack'] = 'one';
        }

        if (ChartKind::Line !== $kind) {
            if (ChartNoughts::Hairline === $chart->noughts) {
                // "Set this to ensure that bars have a minimum length in
                // pixels" — and a nought, whose value is the base, is moved
                // half that length and clamped inside the scale, so the stub
                // stands on the axis (Chart.js BarController::_calculateBarValuePixels).
                $dataset['minBarLength'] = self::HAIRLINE;
            }
            if (null !== $chart->barWidth) {
                // "Set this to ensure that bars are not sized thicker than this."
                $dataset['maxBarThickness'] = $chart->barWidth;
            }
            if (null !== $chart->barRadius || ChartNoughts::Hairline === $chart->noughts) {
                // ONE RADIUS PER BAR (an indexable option): the chart's own,
                // and the stub's where the bar is a nought. `borderSkipped:
                // false` rounds the corners on the axis too, as an SVG `rx` does.
                $dataset['borderRadius'] = array_map(
                    static fn (?float $point): float => ChartNoughts::Hairline === $chart->noughts && 0.0 === $point ? self::HAIRLINE_RADIUS : $chart->barRadius ?? 0.0,
                    $series->points,
                );
                $dataset['borderSkipped'] = false;
            }
        }

        return $dataset;
    }

    /** A ranking reads sideways; everything else stands up. */
    private static function ranked(AtlasChart $chart): bool
    {
        return ChartKind::Ranked === $chart->kind;
    }

    /** @return array<string, mixed> */
    private static function options(AtlasChart $chart): array
    {
        /*
         * THE INDEX AXIS CARRIES THE NAMES AND NO GRID; THE VALUE AXIS
         * STARTS AT NOUGHT AND CARRIES THE GRID. Which letter is which is
         * the one thing a ranking changes: `indexAxis: 'y'` turns the
         * bars sideways and the value scale becomes `x`.
         */
        $ticks = ['color' => self::TICK_INK, 'font' => ['family' => self::TICK_FAMILY, 'size' => self::TICK_SIZE], 'padding' => self::TICK_GAP];
        $index = [
            'grid' => ['display' => false],
            // THE AXIS LINE IS THE INDEX AXIS'S BORDER, the one line the bars stand on.
            'border' => ['display' => true, 'color' => self::AXIS_INK, 'width' => self::AXIS_WIDTH],
            'ticks' => ['maxRotation' => 0] + $ticks,
        ];
        $value = [
            'beginAtZero' => ChartKind::Diverging !== $chart->kind,
            'grid' => ['color' => self::GRID_INK, 'lineWidth' => self::GRID_WIDTH, 'drawTicks' => false],
            'border' => ['display' => false],
            'ticks' => $ticks,
        ];

        if (null !== $chart->axis) {
            $value['max'] = $chart->axis->max;
            $value['ticks']['stepSize'] = $chart->axis->step;
        }

        if (ChartKind::Stacked === $chart->kind) {
            $index['stacked'] = true;
            $value['stacked'] = true;
        }

        $scales = self::ranked($chart) ? ['x' => $value, 'y' => $index] : ['x' => $index, 'y' => $value];

        $plugins = [
            // THE LIBRARY'S LEGEND IS DRAWN WHERE MORE THAN ONE THING IS
            // PLOTTED — and never beside a chip legend, which would name
            // every series twice.
            'legend' => [
                'display' => ChartLegend::Canvas === $chart->legend && (\count($chart->series) > 1 || null !== $chart->target),
                'position' => 'bottom',
            ],
            // NO TOOLTIPS: the design draws none.
            'tooltip' => ['enabled' => false],
        ];

        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            // A PAGE THAT MOVES WHILE IT IS BEING READ is a page nobody
            // can compare two figures on.
            'animation' => false,
        ];

        if (self::ranked($chart)) {
            $options['indexAxis'] = 'y';
        }

        if (ChartNoughts::Hairline === $chart->noughts && ChartKind::Line !== $chart->kind) {
            // THE FADE IS THE PLATE'S, configured where the figures are: the
            // plate turns a nought's color into the same color at this
            // opacity when it resolves the series' tokens.
            $plugins[self::NOUGHTS] = ['opacity' => self::HAIRLINE_OPACITY];
        }

        if (null !== $chart->figures) {
            // THE FIGURE IS THE PLATE'S PLUGIN, configured where Chart.js
            // reads a plugin's options — under its id — and given room past
            // the longest bar, or the canvas clips the last one.
            $plugins[self::FIGURES_PLUGIN] = ['unit' => $chart->figures->unit, 'precision' => $chart->figures->precision];
            $options['layout'] = ['padding' => self::ranked($chart) ? ['right' => self::FIGURE_ROOM_BESIDE] : ['top' => self::FIGURE_ROOM_ABOVE]];
        }

        $options['plugins'] = $plugins;
        $options['scales'] = $scales;

        return $options;
    }

    private static function typeOf(ChartKind $kind): string
    {
        return match ($kind) {
            ChartKind::Line => Chart::TYPE_LINE,
            // A DIVERGING CHART IS BARS EITHER SIDE OF NOUGHT — the shape
            // is the data's, not a chart type of its own.
            // AND A RANKING IS BARS TURNED SIDEWAYS: the type stays `bar`
            // and `indexAxis` does the turning, in options().
            ChartKind::Bar, ChartKind::Stacked, ChartKind::Diverging, ChartKind::Ranked => Chart::TYPE_BAR,
        };
    }
}
