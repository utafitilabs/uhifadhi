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

namespace Uhifadhi\Contracts\Performance;

/**
 * THE SHAPES A CHART IN THIS PLATFORM COMES IN — one list, for a topic's
 * chart and for every chart the atlas draws.
 *
 * A KIND, NOT A LIBRARY CALL. A caller says what its series IS — a run over
 * time, a comparison across categories, parts of a whole, a movement either
 * side of nought — and the atlas decides what that looks like. A module
 * handing over chart options would be a module deciding what the platform's
 * charts look like, and the second module would decide differently.
 *
 * THE BACKING VALUES ARE THE ATLAS'S KIND NAMES, the same strings the design
 * workspace states a chart with, so a design's statement and a module's
 * read alike.
 */
enum ChartKind: string
{
    /** A run over time. */
    case Line = 'line';

    /** A run over time filled down to the floor: a running total. */
    case Area = 'area';

    /** A level that holds, then changes at an event: people on duty, patrols out. */
    case Stepped = 'stepped';

    /** A comparison across periods or categories. */
    case Bar = 'bar';

    /** A comparison across categories whose names are long, read sideways, in their declared order. */
    case HorizontalBar = 'horizontal-bar';

    /** Parts of a whole, period by period. */
    case Stacked = 'stacked';

    /** Parts of a whole, category by category, read sideways where the names are long. */
    case StackedHorizontal = 'stacked-horizontal';

    /** A movement either side of nought — gained and lost, met and missed. */
    case Diverging = 'diverging';

    /**
     * A RANKING: bars that run sideways, the name on the left, the longest
     * on top — the same comparison a Bar makes, read as a league table.
     */
    case Ranked = 'ranked';

    /** Counts per period with a target or a rate drawn as a line over them. */
    case Combo = 'combo';

    /** A part of a whole, with room for a figure in the hole. */
    case Doughnut = 'doughnut';

    /** A part of a whole without a central figure. */
    case Pie = 'pie';

    /** One value against its capacity: half a doughnut. */
    case Gauge = 'gauge';

    /** A count around a cycle — by hour of the day, by month of the year. */
    case PolarArea = 'polar-area';

    /** A profile across a handful of comparable measures. */
    case Radar = 'radar';

    /** Two measures per item. */
    case Scatter = 'scatter';

    /** Two measures per item and a third as the size. */
    case Bubble = 'bubble';

    /** A range per item, start to end: a shift, a closure, a season. */
    case FloatingBar = 'floating-bar';

    /** Two to four series side by side in each category. */
    case GroupedBar = 'grouped-bar';

    /** The parts of a total over time, each layer on the one below: the top line is the whole. */
    case StackedArea = 'stacked-area';

    /** A line with the range around it: the median, the fastest and the slowest. */
    case Band = 'band';

    /** Two measures over time on two scales, left and right. */
    case MultiAxisLine = 'multi-axis-line';

    /** Two levels of one whole: the kinds inside, their parts outside. */
    case NestedDoughnut = 'nested-doughnut';

    /** The parts of each period, with a rate or a total as a line on a second scale. */
    case StackedBarLine = 'stacked-bar-line';

    /** Two measures per item against one shared measure, on two scales. */
    case ScatterMultiAxis = 'scatter-multi-axis';

    /** Two measures of different size over one time axis, each in a pane of its own. */
    case StackedPanels = 'stacked-panels';

    /** A count on a grid of two categories: hour of the day by day of the week. */
    case Heatmap = 'heatmap';

    /** A count per day, laid out as the calendar is: a column a week, a row a weekday. */
    case CalendarHeatmap = 'calendar-heatmap';

    /** Two levels of one whole as nested rectangles, each sized by its count. */
    case Treemap = 'treemap';

    /** Counts flowing from one set of categories into another: kind to outcome. */
    case Sankey = 'sankey';

    /** How the readings at each label spread: the middle half, the whiskers, the outliers. */
    case Boxplot = 'boxplot';

    /** How the readings at each label spread, drawn as a density. */
    case Violin = 'violin';

    /** One measure through ordered stages, each holding at most what the one before it held. */
    case Funnel = 'funnel';
}
