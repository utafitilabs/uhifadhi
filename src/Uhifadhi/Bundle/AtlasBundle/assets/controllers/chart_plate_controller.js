import { Controller } from '@hotwired/stimulus';
import { Chart } from 'chart.js';

/*
 * A CHART'S COLOURS ARE TOKENS UNTIL THE MOMENT IT IS DRAWN.
 *
 * Chart.js paints onto a canvas, and a canvas is not the document: a
 * `var(--cat-3)` written into a dataset is not resolved by the browser the way
 * it would be on any element, it is handed to the 2D context as a string it
 * cannot parse, and the series draws as nothing. So the colours cross the wire
 * as TOKENS — which is what lets the palette turn over with the theme, and what
 * keeps a chart's third series the same mark as the third zone on the plate
 * beside it — and this resolves them against the element the chart is mounted
 * on, at the last possible moment.
 *
 * EVERY STRING, WHEREVER IT SITS. A doughnut's colours are an array, one per
 * slice; a radar's point labels, the legend's and the hover's ink, a plugin's
 * options are blocks of their own. The resolver walks the whole configuration
 * and swaps every token it meets; the data and the labels are the statement's
 * and are left alone.
 *
 * AT MOUNT, AND AGAIN WHEN THE THEME FLIPS. `getComputedStyle` answers with
 * whatever `--cat-3` means right now, and after dark it means something else.
 * The stated configuration is copied before anything is resolved — Chart.js
 * keeps and mutates the very object it is handed (`initConfig()`, `Config`
 * holds it as `_config`) — and a flip derives the drawn one from that copy
 * again and hands Chart.js the new options, which the docs support:
 * "mutating the options property in place or passing in a new options object
 * are supported".
 *
 * WHAT JSON CANNOT CARRY IS PUT ON HERE. The builder states facts; a function
 * must do some of them — a gradient laid across the plot as it is now, a run
 * over a silence drawn dashed, a log axis's ticks, the legend's filter, the
 * hover's words, a paired panel's axis width — and the plate attaches each on
 * every paint, since a flip starts from the stated copy, which has none.
 *
 * AND IT DRAWS WHAT CHART.JS CORE DOES NOT, as INLINE plugins — the
 * `plugins: [...]` array on the chart config — put on in the same
 * pre-connect, before the bridge hands the config to `new Chart()`: the figure
 * on a bar or beside a slice, the shaded ranges and the picked label, the
 * figure in a hole, a whole that holds nothing, four regions behind a scatter,
 * the plot's border. The builder writes each one's options under its id, which
 * is where Chart.js scopes them; a plugin is put on only where its options
 * were stated.
 *
 * AT LEAST TWICE THE PIXELS. A 1x wall monitor, or a canvas a frame scales,
 * draws soft at the device's own ratio.
 *
 * A PACKAGE IS LOADED ONLY FOR THE CHART THAT NEEDS IT. A time axis needs
 * the date adapter, an extension kind (a heatmap, a treemap, a sankey, a
 * boxplot or a violin, a funnel) its package; a page of bars loads none of
 * them. The builder names what a statement needs, the card carries the
 * list as this controller's `needs` value and holds the canvas in a
 * `<template>` — the library makes the chart the moment its canvas
 * connects — and this imports each package by the name the importmap
 * carries, registers what it exports with Chart.js (the ESM builds do not
 * register themselves; the date adapter does, on import), and then puts the
 * canvas on the page, where the library's controller connects as ever. A
 * dynamic `import()` is a lazy import to AssetMapper: not preloaded, fetched
 * when it runs.
 *
 * @see https://symfony.com/bundles/ux-chartjs/current/index.html — `chartjs:pre-connect`
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — inline plugins: `new Chart(ctx, { plugins: [{ ... }] })`; "Plugins must define a unique id in order to be configurable"; options under `options.plugins.{plugin-id}`
 * @see https://www.chartjs.org/docs/latest/api/interfaces/Plugin.html — `beforeDraw`, `beforeDatasetsDraw`, `afterDatasetsDraw`, `afterDraw` (chart, args, options)
 * @see https://www.chartjs.org/docs/latest/developers/api.html — `getDatasetMeta(index).data`, `isDatasetVisible(index)`
 * @see https://www.chartjs.org/docs/latest/general/options.html#scriptable-options — a function of the context in place of a value
 * @see https://www.chartjs.org/docs/latest/charts/line.html#segment — `segment.borderDash`, `segment.borderColor`, with `ctx.p0.skip`, `ctx.p1.skip`
 * @see https://www.chartjs.org/docs/latest/configuration/legend.html#legend-label-configuration — `labels.filter`, `labels.generateLabels`
 * @see https://www.chartjs.org/docs/latest/configuration/tooltip.html#tooltip-callbacks — `callbacks.label`, `callbacks.title`
 * @see https://www.chartjs.org/docs/latest/axes/labelling.html#creating-custom-tick-formats — `ticks.callback`
 * @see https://www.chartjs.org/docs/latest/axes/#callbacks — `afterFit(axis)`
 * @see https://www.chartjs.org/docs/latest/configuration/device-pixel-ratio.html — `devicePixelRatio`
 * @see https://www.chartjs.org/docs/latest/developers/updates.html — updating options; `update('none')`
 * @see chart.js 4.5.1 dist/chart.js, initConfig() and class Config — the config object is kept, not copied
 * @see https://www.chartjs.org/docs/latest/getting-started/integration.html#bundlers-webpack-rollup-etc — `Chart.register(...)` of the controllers and elements a chart uses
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/time.html#date-adapters — a time axis needs a date adapter
 * @see https://symfony.com/doc/current/frontend/asset_mapper.html — "AssetMapper correctly rewrites dynamic imports when the path is a string literal"
 * @see vendor/symfony/asset-mapper/Compiler/JavaScriptImportPathCompiler.php and ImportMap/ImportMapGenerator.php — an `import(` is marked lazy and left out of the preloads
 * @see vendor/symfony/ux-chartjs/assets/dist/controller.js — `new Chart()` in connect(), straight after `chartjs:pre-connect`: nothing asynchronous can run between them
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/template — the content of a template is not rendered and is not in the document
 */
const TOKEN = /^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/;

/** A token at an alpha, as the design writes a faded colour. */
const MIX = /^color-mix\(\s*in srgb\s*,\s*var\(\s*(--[a-zA-Z0-9-]+)\s*\)\s+([\d.]+)%\s*,\s*transparent\s*\)$/;

/* The ids the builder writes each plugin's options under: ChartBuilder's
   FIGURES_PLUGIN, NOUGHTS, RANGES_PLUGIN, PANELS_PLUGIN, CENTRE_PLUGIN,
   QUADRANTS_PLUGIN, EMPTY_PLUGIN, FRAME_PLUGIN and AXIS_ENDS_PLUGIN. */
const FIGURES = 'figures';
const NOUGHTS = 'noughts';
const RANGES = 'ranges';
const PANELS = 'panels';
const CENTRE = 'centre';
const QUADRANTS = 'quadrants';
const EMPTY = 'empty';
const FRAME = 'frame';
const AXIS_ENDS = 'axisEnds';

/*
 * THE PACKAGES, by the names the importmap carries (ChartBuilder::packages()),
 * each with what Chart.js must be told of: a chart type's controller and the
 * element it draws. Each is imported once a page, however many charts want it.
 */
const PACKAGES = {
    'chartjs-adapter-date-fns': () => import('chartjs-adapter-date-fns').then(() => []),
    'chartjs-chart-matrix': () => import('chartjs-chart-matrix').then((m) => [m.MatrixController, m.MatrixElement]),
    'chartjs-chart-treemap': () => import('chartjs-chart-treemap').then((m) => [m.TreemapController, m.TreemapElement]),
    'chartjs-chart-sankey': () => import('chartjs-chart-sankey').then((m) => [m.SankeyController, m.Flow]),
    '@sgratzl/chartjs-chart-boxplot': () => import('@sgratzl/chartjs-chart-boxplot').then((m) => [m.BoxPlotController, m.BoxAndWiskers, m.ViolinController, m.Violin]),
    'chartjs-chart-funnel': () => import('chartjs-chart-funnel').then((m) => [m.FunnelController, m.TrapezoidElement]),
};
const loading = new Map();

/** A package imported and registered, once a page. */
function load(name) {
    if (!loading.has(name)) {
        const importer = PACKAGES[name];
        loading.set(name, importer
            ? importer().then((parts) => { if (parts.length) { Chart.register(...parts); } })
            : Promise.reject(new Error(`No chart package "${name}": the packages are ${Object.keys(PACKAGES).join(', ')}.`)));
    }

    return loading.get(name);
}

/** A name drawn on two lines is one name in words. */
function named(label) {
    return Array.isArray(label) ? label.join(' ') : label;
}

/** The least pixel ratio a chart is drawn at. */
const MIN_PIXEL_RATIO = 2;

/* The house type the canvas writes itself, at the pairs of the house scale:
   the figure on a bar or a slice, the figure in a hole and its caption, a
   range's or a region's word. */
const FIGURE_FONT = '700 10.5px ';
const CENTRE_FIGURE = '700 26px ';
const CENTRE_CAPTION = '600 10.5px ';
const RANGE_WORD = '400 8.5px ';

/** The gap between a bar's end and its figure, in canvas pixels. */
const FIGURE_GAP = 6;

/** The inset of a range's or a region's word from its corner, in canvas pixels. */
const WORD_INSET = 4;

/** A centred axis's title clear of the tick row under the x line, and of the y line to its right. */
const AXIS_END_GAP = 3;
const AXIS_END_INSET = 4;

/*
 * THE SAME COLOUR AT AN OPACITY. A token resolves to one of two shapes: the
 * palette's six-digit hex or a channel colour (`rgb(62 217 168)`, with or
 * without an alpha of its own), and each carries an alpha the way Chart.js's
 * own colour parser and the canvas both read it — an eighth and ninth hex
 * digit, or `/ alpha` inside rgb(). Any other shape is returned as it came.
 */
function fade(color, opacity) {
    if ('string' !== typeof color) {
        return color;
    }
    if (/^#[0-9a-fA-F]{6}$/.test(color)) {
        return color + Math.round(opacity * 255).toString(16).padStart(2, '0');
    }
    const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)$/.exec(color);
    if (rgb) {
        const alpha = undefined === rgb[4] ? 1 : Number(rgb[4]);

        return `rgb(${rgb[1]} ${rgb[2]} ${rgb[3]} / ${+(alpha * opacity).toFixed(4)})`;
    }

    return color;
}

/** Whether a value is a power of ten: where a log axis draws its lines and figures. */
function power(value) {
    const exponent = Math.log10(value);

    return value > 0 && Math.abs(exponent - Math.round(exponent)) < 1e-9;
}

/** The hours of a day written as a clock: 6.5 is 06:30. */
function clock(value) {
    const hours = Math.floor(value);
    const minutes = Math.round((value - hours) * 60);

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

export default class extends Controller {
    static values = { needs: Array };

    static targets = ['held'];

    connect() {
        this.swatches = new Map();

        this.onPreConnect = (event) => {
            const config = event.detail.config;
            /* The configuration as stated, tokens and all, before any of it
               is resolved: what a theme flip starts from. */
            this.stated = structuredClone(config);
            this.paint(config);
            config.plugins = [...(config.plugins ?? []), ...this.plugins(config)];
        };
        this.element.addEventListener('chartjs:pre-connect', this.onPreConnect);

        /* The theme is a class on <html>, put there before the first paint by
           the shell's own inline script — so what this catches is a later
           flip, made with the toggle while a chart is on screen. */
        this.onThemeFlip = () => this.repaint();
        this.themeWatch = new MutationObserver(this.onThemeFlip);
        this.themeWatch.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        this.onConnect = (event) => { this.chart = event.detail.chart; };
        this.element.addEventListener('chartjs:connect', this.onConnect);

        if (this.hasHeldTarget) {
            Promise.all(this.needsValue.map(load)).then(
                () => { for (const held of this.heldTargets) { held.replaceWith(held.content.cloneNode(true)); } },
                (error) => console.error('The chart was not drawn: a package it needs did not load.', error),
            );
        }
    }

    disconnect() {
        this.element.removeEventListener('chartjs:pre-connect', this.onPreConnect);
        this.element.removeEventListener('chartjs:connect', this.onConnect);
        this.themeWatch?.disconnect();
        this.themeWatch = null;
    }

    /** The stated configuration made drawable, in place: tokens resolved, the hatch laid, the callables on. */
    paint(config) {
        this.walk(config);

        for (const dataset of config?.data?.datasets ?? []) {
            if (!dataset.texture) {
                continue;
            }
            if (Array.isArray(dataset.backgroundColor)) {
                dataset.backgroundColor = dataset.backgroundColor.map((color, i) => (dataset.texture[i] ? this.hatch(color) : color));
            } else if ('string' === typeof dataset.backgroundColor) {
                dataset.backgroundColor = this.hatch(dataset.backgroundColor);
            }
        }

        this.hairline(config);
        this.callables(config);
        if (config.options) {
            config.options.devicePixelRatio = Math.max(MIN_PIXEL_RATIO, window.devicePixelRatio || 1);
        }

        return config;
    }

    /**
     * EVERY STRING IN THE CONFIGURATION, resolved in place — a dataset's
     * colour, an array of slice colours, a scale's ticks, grid and point
     * labels, the legend's and the hover's ink, a plugin's options. The data
     * and the labels are the statement's and are left as they are.
     */
    walk(node, key) {
        if (('data' === key || 'labels' === key) && Array.isArray(node)) {
            return node;
        }
        if (Array.isArray(node)) {
            for (let i = 0; i < node.length; i++) {
                node[i] = this.walk(node[i]);
            }

            return node;
        }
        if (node && 'object' === typeof node) {
            for (const k of Object.keys(node)) {
                node[k] = this.walk(node[k], k);
            }

            return node;
        }

        return this.resolve(node);
    }

    /** THE HOUSE HATCH: the colour at a fifth under 45° strokes of itself, as a repeating pattern. */
    hatch(color) {
        const tile = document.createElement('canvas');
        tile.width = 6;
        tile.height = 6;
        const ctx = tile.getContext('2d');
        if (!ctx) {
            return color;
        }
        ctx.fillStyle = fade(color, 0.22);
        ctx.fillRect(0, 0, 6, 6);
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(-1, 7); ctx.lineTo(7, -1);
        ctx.moveTo(-1, 1); ctx.lineTo(1, -1);
        ctx.moveTo(5, 7); ctx.lineTo(7, 5);
        ctx.stroke();

        return ctx.createPattern(tile, 'repeat') || color;
    }

    /*
     * A NOUGHT'S HAIRLINE, FADED. The builder already asked for a two-pixel
     * stub; here, once the series' token is a colour, each bar series' fill
     * and stroke become one colour per bar, and a nought's is the faded one —
     * so the stub reads as "none here" rather than as a short bar.
     */
    hairline(config) {
        const noughts = config.options?.plugins?.[NOUGHTS];
        if (!noughts) {
            return;
        }

        const opacity = noughts.opacity;
        for (const dataset of config?.data?.datasets ?? []) {
            if ('line' === (dataset.type ?? config.type)) {
                continue;
            }
            for (const property of ['backgroundColor', 'borderColor']) {
                const color = dataset[property];
                if ('string' === typeof color) {
                    dataset[property] = dataset.data.map((value) => (0 === value ? fade(color, opacity) : color));
                }
            }
        }
    }

    /**
     * WHAT JSON CANNOT CARRY: the hover's words, a log axis's ticks and grid,
     * the clock on a value axis, nought's stronger line, the legend's filter
     * and a nested doughnut's rows, a silence drawn dashed, a gradient laid
     * across the plot, a paired panel's axis width. Each is stated by the
     * builder under an `atlas…` key or as a fact, and replaced here by the
     * function that does it.
     */
    callables(config) {
        const options = config.options ?? {};
        const type = config.type;

        const tip = options.plugins?.tooltip;
        if (tip) {
            tip.callbacks = {
                label: (item) => this.tooltipLabel(item, tip, type),
                title: (items) => this.tooltipTitle(items, type),
            };
        }

        const scales = options.scales ?? {};
        for (const scale of Object.values(scales)) {
            const ticks = scale.ticks;
            if (ticks?.atlasLog) {
                delete ticks.atlasLog;
                ticks.callback = (value) => (power(value) ? Number(value).toLocaleString('en-GB') : '');
            }
            if (ticks?.atlasNames) {
                /* A calendar's month over the week its first day falls in. */
                const names = ticks.atlasNames;
                delete ticks.atlasNames;
                ticks.callback = (value, i) => names[i] || '';
            }
            if (ticks?.atlasClock) {
                delete ticks.atlasClock;
                ticks.callback = (value) => `${String(value).padStart(2, '0')}:00`;
            }
            const grid = scale.grid;
            if (grid?.atlasLog) {
                delete grid.atlasLog;
                const line = grid.color;
                grid.color = (ctx) => (ctx.tick && power(ctx.tick.value) ? line : 'transparent');
            }
            if (grid?.zeroColor) {
                const zero = grid.zeroColor;
                const rest = grid.color;
                delete grid.zeroColor;
                grid.color = (ctx) => (ctx.tick && 0 === ctx.tick.value ? zero : rest);
            }
        }

        const legend = options.plugins?.legend;
        const labels = legend?.labels;
        if (labels?.atlasRamp) {
            /* A RAMP'S LEGEND: one row per band, the band's step beside it. */
            const bands = labels.atlasRamp;
            const ink = labels.color;
            delete labels.atlasRamp;
            labels.generateLabels = () => bands.words.map((word, i) => ({
                text: word + (i === bands.words.length - 1 && bands.unit ? ` ${bands.unit}` : ''),
                fillStyle: bands.colours[i], strokeStyle: bands.colours[i], lineWidth: 0, fontColor: ink, hidden: false, index: i,
            }));
            legend.onClick = () => {};
        }
        if (labels?.atlasFilter) {
            const flag = labels.atlasFilter;
            delete labels.atlasFilter;
            labels.filter = (row, data) => !data.datasets[row.datasetIndex][flag];
        }
        if (labels && undefined !== labels.atlasRing) {
            /* The rows of ONE ring of a nested doughnut, not dataset 0's. */
            const ring = labels.atlasRing;
            const ink = labels.color;
            delete labels.atlasRing;
            labels.generateLabels = (chart) => {
                const dataset = chart.data.datasets[ring];

                return (dataset.labels || chart.data.labels).map((text, i) => ({
                    text, fillStyle: dataset.backgroundColor[i], strokeStyle: dataset.backgroundColor[i], lineWidth: 0, fontColor: ink, hidden: false, index: i,
                }));
            };
            legend.onClick = () => {};
        }

        for (const dataset of config?.data?.datasets ?? []) {
            if (dataset.gapStyle) {
                /* A RUN WITH NO READING AT EITHER END is drawn dashed in the muted ink. */
                const gap = dataset.gapStyle;
                const silent = (ctx) => ctx.p0.skip || ctx.p1.skip;
                dataset.segment = {
                    borderDash: (ctx) => (silent(ctx) ? gap.dash : undefined),
                    borderColor: (ctx) => (silent(ctx) ? gap.color : undefined),
                };
            }
            if (dataset.atlasCell) {
                /* A CELL'S SIZE is the plot's share per column and row, less
                   the surface gap — square where the statement asks. */
                const cell = dataset.atlasCell;
                delete dataset.atlasCell;
                const size = (chart) => {
                    const area = chart.chartArea || { width: 0, height: 0 };
                    const w = area.width / cell.columns;
                    const h = area.height / cell.rows;

                    return cell.square ? { w: Math.min(w, h), h: Math.min(w, h) } : { w, h };
                };
                dataset.width = (ctx) => Math.max(1, size(ctx.chart).w - cell.gap);
                dataset.height = (ctx) => Math.max(1, size(ctx.chart).h - cell.gap);
            }
            if (dataset.atlasTree) {
                /* A GROUP behind its caption at a faint tint of its colour, a
                   LEAF at the outer ring's tint: the hue says which group. */
                const tree = dataset.atlasTree;
                delete dataset.atlasTree;
                const kindOf = (raw) => {
                    const rows = (raw._data || {}).children || [];

                    return rows.length ? rows[0].kind : null;
                };
                dataset.backgroundColor = (ctx) => {
                    if ('data' !== ctx.type || !ctx.raw) {
                        return 'transparent';
                    }

                    return ctx.raw.l ? tree.leaf[kindOf(ctx.raw)] || 'transparent' : tree.group[ctx.raw.g] || 'transparent';
                };
                dataset.labels = { ...dataset.labels, formatter: (ctx) => (ctx.raw && ctx.raw.l ? [ctx.raw.g, String(ctx.raw.v)] : '') };
                dataset.captions = { ...dataset.captions, formatter: (ctx) => (ctx.raw ? `${tree.names[ctx.raw.g] || ctx.raw.g} ${ctx.raw.v}` : '') };
            }
            if (dataset.atlasFlows) {
                /* A FLOW IN ITS SOURCE'S COLOUR; the target node in its own. */
                const flows = dataset.atlasFlows;
                delete dataset.atlasFlows;
                dataset.colorFrom = (ctx) => flows.from[ctx.dataIndex];
                dataset.colorTo = (ctx) => flows.to[ctx.dataIndex];
            }
            if (dataset.gradient?.radial) {
                /* From the radial scale's centre to its rim, made at draw time. */
                const ring = dataset.gradient;
                dataset.backgroundColor = (context) => {
                    const chart = context.chart;
                    const r = chart.scales?.r;
                    if (!chart.chartArea || !r || !r.drawingArea) {
                        return ring.to;
                    }
                    const gradient = chart.ctx.createRadialGradient(r.xCenter, r.yCenter, 0, r.xCenter, r.yCenter, r.drawingArea);
                    gradient.addColorStop(0, ring.from);
                    gradient.addColorStop(1, ring.to);

                    return gradient;
                };
            } else if (dataset.gradient) {
                /* Laid across the chart area as it is now, so made at draw time. */
                const stops = dataset.gradient;
                dataset.backgroundColor = (context) => {
                    const area = context.chart.chartArea;
                    if (!area) {
                        return stops.from;
                    }
                    const gradient = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                    gradient.addColorStop(0, stops.from);
                    gradient.addColorStop(1, stops.to);

                    return gradient;
                };
            }
        }

        this.panel(config);
    }

    /** The hover's line for one mark, in words: the series, the reading and its unit. */
    tooltipLabel(item, tip, type) {
        const dataset = item.dataset;
        const raw = item.raw;
        const unit = tip.unit ? ` ${tip.unit}` : '';
        const own = dataset.unit ? ` ${dataset.unit}` : unit;
        const name = dataset.edgeLabel || dataset.label;
        const xUnit = tip.xUnit ? ` ${tip.xUnit}` : '';
        const lines = item.chart.data.labels[item.dataIndex];

        if ('matrix' === type) {
            return raw.v + own;
        }
        if ('treemap' === type) {
            return `${raw.g || ''}: ${raw.v}${own}`;
        }
        if ('sankey' === type) {
            const shown = dataset.labels || {};

            return `${shown[raw.from] || raw.from} → ${shown[raw.to] || raw.to}: ${raw.flow}${own}`;
        }
        if ('boxplot' === type || 'violin' === type) {
            /* THE FIVE FIGURES OF A BOX in words; a violin's middle and ends. */
            const stats = item.parsed || {};
            const n = (v) => (null === v || undefined === v || Number.isNaN(v) ? '–' : String(Math.round(v * 10) / 10));
            const count = Array.isArray(raw) ? `${raw.length} readings, ` : '';
            if ('violin' === type) {
                return `${dataset.label}: ${count}median ${n(stats.median)}, from ${n(stats.min)} to ${n(stats.max)}${own}`;
            }

            return `${dataset.label}: ${count}median ${n(stats.median)}, middle half ${n(stats.q1)}–${n(stats.q3)}, whiskers ${n(stats.whiskerMin)}–${n(stats.whiskerMax)}${own}`;
        }
        if ('funnel' === type) {
            const first = dataset.data[0] || 1;

            return `${dataset.label}: ${raw}${own} (${Math.round(100 * raw / first)}% of ${String(item.chart.data.labels[0]).toLowerCase()})`;
        }
        if (Array.isArray(raw)) {
            /* A FLOATING BAR'S RANGE, start to end. */
            const write = tip.clock ? clock : (v) => v + own;

            return `${Array.isArray(lines) ? named(lines) : item.label || dataset.label}: ${write(raw[0])}–${write(raw[1])}`;
        }
        if (null === raw || undefined === raw) {
            return `${name}: no reading`;
        }
        if ('bubble' === type) {
            return `${dataset.label}: ${raw.x}${xUnit}, ${raw.y}${tip.yUnit ? ` ${tip.yUnit}` : ''}, ${raw.size}${tip.sizeLabel ? ` ${tip.sizeLabel}` : ''}`;
        }
        if ('scatter' === type) {
            const yUnit = dataset.unit || tip.yUnit;

            return `${dataset.label}: ${raw.x}${xUnit}, ${raw.y}${yUnit ? ` ${yUnit}` : ''}`;
        }
        if ('doughnut' === type || 'pie' === type || 'polarArea' === type) {
            const total = dataset.data.reduce((sum, v) => sum + (v || 0), 0);
            const part = (dataset.labels || [])[item.dataIndex] || item.label;

            return `${part}: ${raw}${own} (${total ? Math.round(100 * raw / total) : 0}%)`;
        }

        return `${name}: ${item.formattedValue}${own}`;
    }

    /** The hover's title: the label read, the item named, or nothing where the line names it. */
    tooltipTitle(items, type) {
        if (!items.length) {
            return '';
        }
        if ('bubble' === type || 'scatter' === type) {
            /* A chart whose items are named (one label per item) says whose it is. */
            return items[0].chart.data.labels[items[0].dataIndex] || '';
        }
        if ('matrix' === type) {
            return items[0].raw.t || '';
        }
        if ('treemap' === type) {
            /* A leaf's group above it; a group is its own title. */
            const raw = items[0].raw;
            const rows = raw.l && raw._data ? raw._data.children || [] : [];

            return rows.length ? rows[0].kind : '';
        }
        if ('doughnut' === type || 'pie' === type || 'sankey' === type) {
            return '';
        }
        /* A name drawn on two lines is one name in the hover. */
        const label = items[0].chart.data.labels[items[0].dataIndex];

        return Array.isArray(label) ? named(label) : items[0].label;
    }

    /**
     * A PAIRED PANEL HOLDS ITS VALUE AXIS AT ONE WIDTH. The scale's `afterFit`
     * is the hook Chart.js gives for sizing a scale after it has measured its
     * ticks, and a function is nothing JSON can carry — so the builder states
     * the width under the panels plugin's id and the plate puts the hook on.
     */
    panel(config) {
        const panels = config.options?.plugins?.[PANELS];
        if (!panels) {
            return;
        }

        const sideways = 'y' === config.options?.indexAxis;
        const value = config.options?.scales?.[sideways ? 'x' : 'y'];
        if (!value) {
            return;
        }
        value.afterFit = (scale) => {
            if (sideways) {
                scale.height = panels.axisWidth;
            } else {
                scale.width = panels.axisWidth;
            }
        };
    }

    /** The inline plugins this chart's options ask for, each under the id its options were written by. */
    plugins(config) {
        const stated = config.options?.plugins ?? {};
        const family = () => getComputedStyle(this.element).getPropertyValue('--font-mono').trim() || 'monospace';
        const element = this.element;
        const list = [];

        if (stated[FIGURES]) {
            list.push({ id: FIGURES, afterDatasetsDraw: (chart, args, options) => this.drawFigures(chart, options, element, family) });
        }
        if (stated[RANGES]) {
            list.push({ id: RANGES, beforeDatasetsDraw: (chart, args, options) => this.drawRanges(chart, options, family) });
        }
        if (stated[CENTRE]) {
            list.push({ id: CENTRE, afterDatasetsDraw: (chart, args, options) => this.drawCentre(chart, options, family) });
        }
        if (stated[QUADRANTS]) {
            list.push({ id: QUADRANTS, beforeDatasetsDraw: (chart, args, options) => this.drawQuadrants(chart, options, family) });
        }
        if (stated[EMPTY]) {
            list.push({ id: EMPTY, afterDraw: (chart, args, options) => this.drawEmpty(chart, options, family) });
        }
        if (stated[FRAME]) {
            list.push({ id: FRAME, beforeDraw: (chart, args, options) => this.drawFrame(chart, options) });
        }
        if (stated[AXIS_ENDS]) {
            list.push({ id: AXIS_ENDS, afterDraw: (chart, args, options) => this.drawAxisEnds(chart, options, family) });
        }

        return list;
    }

    /**
     * THE FIGURES PLUGIN: the figure past the end of every bar, or under a
     * bar that ends below nought; on a stack asked for totals, one figure at
     * the end of each stack, its visible parts summed, never a nought; and on
     * a whole, each slice's name and figure outside it at the middle of its
     * angle. A null point has no figure.
     *
     * SLICE NAMES ARE LAID OUT PER SIDE, not where they fall: sorted down the
     * side, pushed one line apart and kept inside the canvas, so two small
     * neighbours never print on top of each other.
     */
    drawFigures(chart, options, element, family) {
        const ctx = chart.ctx;
        const ink = getComputedStyle(element);
        const unit = options.unit ? ` ${options.unit}` : '';
        const arcs = 'doughnut' === chart.config.type || 'pie' === chart.config.type;
        const sideways = 'y' === chart.options.indexAxis;
        const font = options.size ? `700 ${options.size}px ` : FIGURE_FONT;
        const bars = (dataset) => 'line' !== (dataset.type ?? chart.config.type);
        const sides = { left: [], right: [] };

        ctx.save();
        ctx.font = font + (options.family || family());
        ctx.fillStyle = ink.color;

        let last = -1;
        chart.data.datasets.forEach((dataset, index) => {
            if (chart.isDatasetVisible(index) && bars(dataset)) {
                last = index;
            }
        });

        chart.data.datasets.forEach((dataset, index) => {
            if (!chart.isDatasetVisible(index) || !bars(dataset) || (null !== options.only && undefined !== options.only && index !== options.only)) {
                return;
            }
            if (options.totals && index !== last) {
                return;
            }

            chart.getDatasetMeta(index).data.forEach((mark, point) => {
                let value = dataset.data[point];
                if (options.totals) {
                    let sum = 0;
                    let any = false;
                    chart.data.datasets.forEach((other, j) => {
                        const v = other.data[point];
                        if (chart.isDatasetVisible(j) && bars(other) && null !== v && undefined !== v) {
                            sum += Number(v);
                            any = true;
                        }
                    });
                    value = any && sum ? sum : null;
                }
                if (null === value || undefined === value || mark.hidden) {
                    return;
                }

                const figure = Number(value).toFixed(options.precision ?? 0) + unit;
                if (arcs) {
                    const middle = (mark.startAngle + mark.endAngle) / 2;
                    const reach = mark.outerRadius + 8;
                    const x = mark.x + Math.cos(middle) * reach;
                    const y = mark.y + Math.sin(middle) * reach;
                    const right = Math.cos(middle) >= 0;
                    const name = options.names ? `${(dataset.labels || chart.data.labels)[point]} ` : '';
                    const nameWidth = ctx.measureText(name).width;
                    const figureWidth = ctx.measureText(figure).width;
                    sides[right ? 'right' : 'left'].push({ x: right ? x : x - nameWidth - figureWidth, y, name, figure, nameWidth });

                    return;
                }

                const below = Number(value) < 0;
                if (sideways) {
                    ctx.textAlign = below ? 'right' : 'left';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(figure, mark.x + (below ? -FIGURE_GAP : FIGURE_GAP), mark.y);
                } else {
                    ctx.textAlign = 'center';
                    ctx.textBaseline = below ? 'top' : 'bottom';
                    ctx.fillText(figure, mark.x, mark.y + (below ? FIGURE_GAP / 2 : -FIGURE_GAP / 2));
                }
            });
        });

        const line = Math.ceil(parseFloat((/([\d.]+)px/.exec(font) || [0, 11])[1]) * 1.35);
        const top = line / 2 + 2;
        const bottom = chart.height - line / 2 - 2;
        for (const side of [sides.left, sides.right]) {
            side.sort((a, b) => a.y - b.y);
            for (let i = 0; i < side.length; i++) {
                side[i].y = Math.max(side[i].y, top, i ? side[i - 1].y + line : top);
            }
            for (let j = side.length - 1; j >= 0; j--) {
                side[j].y = Math.min(side[j].y, bottom, j < side.length - 1 ? side[j + 1].y - line : bottom);
            }
            for (const label of side) {
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'left';
                ctx.fillStyle = options.muted || ink.color;
                ctx.fillText(label.name, label.x, label.y);
                ctx.fillStyle = ink.color;
                ctx.fillText(label.figure, label.x + label.nameWidth, label.y);
            }
        }

        ctx.restore();
    }

    /**
     * THE RANGES PLUGIN: a faint band over a stated range of the value axis
     * with its word at the band's edge, and a faint column behind the one
     * label the reader picked — both drawn BEFORE the datasets, so every
     * series sits on top of them, clipped to the plot.
     */
    drawRanges(chart, options, family) {
        const ctx = chart.ctx;
        const area = chart.chartArea;
        const sideways = 'y' === chart.options.indexAxis;
        const value = sideways ? chart.scales.x : chart.scales.y;
        const index = sideways ? chart.scales.y : chart.scales.x;
        if (!value || !index) {
            return;
        }

        ctx.save();
        ctx.beginPath();
        ctx.rect(area.left, area.top, area.right - area.left, area.bottom - area.top);
        ctx.clip();

        for (const range of options.ranges ?? []) {
            const a = value.getPixelForValue(null === range.from ? value.min : range.from);
            const b = value.getPixelForValue(null === range.to ? value.max : range.to);
            ctx.fillStyle = fade(range.color, options.opacity);
            if (sideways) {
                ctx.fillRect(Math.min(a, b), area.top, Math.abs(b - a), area.bottom - area.top);
            } else {
                ctx.fillRect(area.left, Math.min(a, b), area.right - area.left, Math.abs(b - a));
            }
            if (range.label) {
                ctx.fillStyle = range.color;
                ctx.font = RANGE_WORD + (options.family || family());
                if ('letterSpacing' in ctx) {
                    ctx.letterSpacing = '1.19px';
                }
                const word = String(range.label).toUpperCase();
                /* The word sits at the band's far end, just inside its edge. */
                if (sideways) {
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(word, Math.min(a, b) + WORD_INSET, area.bottom - WORD_INSET);
                } else if (null === range.to) {
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(word, area.right - WORD_INSET, Math.max(a, b) - WORD_INSET);
                } else {
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'top';
                    ctx.fillText(word, area.right - WORD_INSET, Math.min(a, b) + WORD_INSET);
                }
            }
        }

        if (null !== options.highlight && undefined !== options.highlight) {
            const ticks = index.ticks.length || chart.data.labels.length;
            const span = ticks > 1 ? Math.abs(index.getPixelForTick(1) - index.getPixelForTick(0)) : (sideways ? area.bottom - area.top : area.right - area.left);
            const centre = index.getPixelForValue(options.highlight);
            ctx.fillStyle = fade(options.highlightColor, options.highlightOpacity);
            if (sideways) {
                ctx.fillRect(area.left, centre - span / 2, area.right - area.left, span);
            } else {
                ctx.fillRect(centre - span / 2, area.top, span, area.bottom - area.top);
            }
        }

        ctx.restore();
    }

    /**
     * THE FIGURE IN THE HOLE. A whole ring centres its figure with the caption
     * under it; a half one sits it on the arc's baseline.
     */
    drawCentre(chart, options, family) {
        const arc = chart.getDatasetMeta(0).data[0];
        if (!arc) {
            return;
        }
        const ctx = chart.ctx;
        const face = options.family || family();
        const half = 'doughnut' === chart.config.type && 180 === chart.data.datasets[0].circumference;

        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = options.ink;
        ctx.font = CENTRE_FIGURE + face;
        ctx.textBaseline = half ? 'alphabetic' : 'middle';
        ctx.fillText(options.figure, arc.x, half ? arc.y - 18 : arc.y - 7);
        if (options.caption) {
            ctx.fillStyle = options.muted;
            ctx.font = CENTRE_CAPTION + face;
            if ('letterSpacing' in ctx) {
                ctx.letterSpacing = '0.63px';
            }
            ctx.fillText(String(options.caption).toUpperCase(), arc.x, half ? arc.y - 2 : arc.y + 16);
        }
        ctx.restore();
    }

    /**
     * FOUR REGIONS BEHIND A SCATTER, split at the stated x and y, each a
     * token at a range's strength and named by its word in its corner; the
     * two splits one step stronger than the grid, on whole pixels.
     */
    drawQuadrants(chart, options, family) {
        const area = chart.chartArea;
        const x = chart.scales.x;
        const y = chart.scales.y;
        if (!area || !x || !y) {
            return;
        }
        const ctx = chart.ctx;
        const cx = Math.round(Math.min(area.right, Math.max(area.left, x.getPixelForValue(options.x))));
        const cy = Math.round(Math.min(area.bottom, Math.max(area.top, y.getPixelForValue(options.y))));
        const boxes = [
            [area.left, area.top, cx - area.left, cy - area.top, 'left', 'top'],
            [cx, area.top, area.right - cx, cy - area.top, 'right', 'top'],
            [cx, cy, area.right - cx, area.bottom - cy, 'right', 'bottom'],
            [area.left, cy, cx - area.left, area.bottom - cy, 'left', 'bottom'],
        ];

        ctx.save();
        ctx.font = RANGE_WORD + (options.family || family());
        if ('letterSpacing' in ctx) {
            ctx.letterSpacing = '1.19px';
        }
        boxes.forEach(([left, top, width, height, align, baseline], i) => {
            const part = options.parts[i] || {};
            if (part.color && 'transparent' !== part.color) {
                ctx.fillStyle = fade(part.color, options.opacity);
                ctx.fillRect(left, top, width, height);
            }
            if (part.label) {
                ctx.fillStyle = options.ink;
                ctx.textAlign = align;
                ctx.textBaseline = baseline;
                ctx.fillText(
                    String(part.label).toUpperCase(),
                    'left' === align ? left + WORD_INSET : left + width - WORD_INSET,
                    'top' === baseline ? top + WORD_INSET : top + height - WORD_INSET,
                );
            }
        });
        ctx.strokeStyle = options.line;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(cx + 0.5, area.top); ctx.lineTo(cx + 0.5, area.bottom);
        ctx.moveTo(area.left, cy + 0.5); ctx.lineTo(area.right, cy + 0.5);
        ctx.stroke();
        ctx.restore();
    }

    /**
     * A WHOLE THAT HOLDS NOTHING draws its ring in the grid ink and says so in
     * the hole, so an empty day reads as empty and not as broken.
     */
    drawEmpty(chart, options, family) {
        let total = 0;
        for (const dataset of chart.data.datasets) {
            for (const v of dataset.data || []) {
                total += Number(v) || 0;
            }
        }
        if (total > 0) {
            return;
        }
        const area = chart.chartArea;
        const ctx = chart.ctx;
        const cx = (area.left + area.right) / 2;
        const cy = (area.top + area.bottom) / 2;
        const outer = Math.min(area.right - area.left, area.bottom - area.top) / 2;
        const inner = outer * options.cutout;

        ctx.save();
        ctx.strokeStyle = options.ring;
        ctx.lineWidth = outer - inner;
        ctx.beginPath();
        ctx.arc(cx, cy, (outer + inner) / 2, 0, 2 * Math.PI);
        ctx.stroke();
        ctx.fillStyle = options.muted;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = CENTRE_CAPTION + (options.family || family());
        if ('letterSpacing' in ctx) {
            ctx.letterSpacing = '0.63px';
        }
        ctx.fillText(String(options.caption).toUpperCase(), cx, cy);
        ctx.restore();
    }

    /**
     * A CENTRED AXIS'S TITLE AT THE AXIS'S END, in small capitals: the x
     * title under the x line's tick row, ending at the axis's right end; the
     * y title right of the y line, at its top. Chart.js would set each on
     * its ticks, so the builder turns its own off.
     */
    drawAxisEnds(chart, options, family) {
        const area = chart.chartArea;
        if (!area) {
            return;
        }
        const ctx = chart.ctx;
        /* Where the library draws a centred axis's line. */
        const lineY = (area.top + area.bottom) / 2;
        const lineX = (area.left + area.right) / 2;
        const small = 'fontVariantCaps' in ctx;
        const shown = (text) => (small ? text : text.toUpperCase());

        ctx.save();
        ctx.fillStyle = options.ink;
        ctx.font = `${options.weight} ${options.size}px ${options.family || family()}`;
        if (small) {
            ctx.fontVariantCaps = 'all-small-caps';
        }
        if (options.x) {
            ctx.textAlign = 'right';
            ctx.textBaseline = 'top';
            ctx.fillText(shown(options.x), area.right, lineY + options.tickPadding + options.size * 1.2 + AXIS_END_GAP);
        }
        if (options.y) {
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            ctx.fillText(shown(options.y), lineX + AXIS_END_INSET, area.top);
        }
        ctx.restore();
    }

    /** THE PLOT'S BORDER, dashed, on whole pixels. */
    drawFrame(chart, options) {
        const area = chart.chartArea;
        const ctx = chart.ctx;

        ctx.save();
        ctx.strokeStyle = options.color;
        ctx.lineWidth = options.width;
        ctx.setLineDash(options.dash || []);
        ctx.strokeRect(Math.round(area.left) + 0.5, Math.round(area.top) + 0.5, Math.round(area.right - area.left) - 1, Math.round(area.bottom - area.top) - 1);
        ctx.restore();
    }

    /**
     * THE SAME CHART, IN THE PALETTE THAT IS ON NOW. The tokens are gone from
     * the configuration Chart.js holds — they were resolved at mount — so the
     * drawn one is derived again from the copy taken before that, with the
     * token cache cleared so every value is asked for again: each dataset's
     * painted properties are handed over, and the options as a whole.
     */
    repaint() {
        if (!this.chart || !this.stated) {
            return;
        }

        this.swatches.clear();
        const fresh = this.paint(structuredClone(this.stated));

        this.chart.data.datasets.forEach((dataset, index) => {
            const painted = fresh.data?.datasets?.[index] ?? {};
            for (const property of Object.keys(painted)) {
                if ('data' !== property) {
                    dataset[property] = painted[property];
                }
            }
        });
        this.chart.options = fresh.options;
        this.chart.update('none');
    }

    /** A token's value, a faded token's colour, or whatever was handed over where it is neither. */
    resolve(value) {
        if ('string' !== typeof value) {
            return value;
        }

        const token = TOKEN.exec(value);
        if (token) {
            return this.token(token[1], value);
        }

        const mix = MIX.exec(value);
        if (mix) {
            return fade(this.token(mix[1], value), Number(mix[2]) / 100);
        }

        return value;
    }

    /**
     * What a custom property means on this element right now.
     *
     * Cached per element until the next flip: a stacked chart asks for the
     * same tokens once a dataset, and `getComputedStyle` is a layout read.
     */
    token(name, fallback) {
        if (!this.swatches.has(name)) {
            this.swatches.set(name, getComputedStyle(this.element).getPropertyValue(name).trim() || fallback);
        }

        return this.swatches.get(name);
    }
}
