import { Controller } from '@hotwired/stimulus';

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
 * AT MOUNT, AND AGAIN WHEN THE THEME FLIPS. `getComputedStyle` answers with
 * whatever `--cat-3` means right now, and after dark it means something else;
 * a chart that resolved once would be a chart drawn in yesterday's palette
 * until the page was reloaded. The same MutationObserver the map plate keeps,
 * for the same reason.
 *
 * IT CHANGES NOTHING ELSE. Every other option the chart carries is the
 * builder's, untouched — this walks the built configuration, swaps token
 * strings for values, and hands it back.
 *
 * AND IT WRITES THE FIGURE ON THE BAR, where the builder asked for one.
 * Chart.js core draws no value labels and the host ships no plugin for them,
 * so the plate carries an INLINE plugin — the `plugins: [...]` array on the
 * chart config the docs describe — put on in the same pre-connect, before
 * the bridge hands the config to `new Chart()`. The builder writes the
 * plugin's options under its id, which is where Chart.js scopes them; the
 * plugin reads them back in its draw hook.
 *
 * @see https://symfony.com/bundles/ux-chartjs/current/index.html — `chartjs:pre-connect`
 * @see https://www.chartjs.org/docs/latest/developers/plugins.html — inline plugins: `new Chart(ctx, { plugins: [{ ... }] })`; "Plugins must define a unique id in order to be configurable"; options under `options.plugins.{plugin-id}`
 * @see https://www.chartjs.org/docs/latest/api/interfaces/Plugin.html — `afterDatasetsDraw(chart, args, options)`
 * @see https://www.chartjs.org/docs/latest/developers/api.html — `getDatasetMeta(index).data`, `isDatasetVisible(index)`
 */
const TOKEN = /^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/;

/** The id the builder writes the figures' options under: ChartBuilder::FIGURES_PLUGIN. */
const FIGURES = 'figures';

/** The gap between a bar's end and its figure, in canvas pixels. */
const FIGURE_GAP = 6;

/** The properties a colour can reach a dataset or a scale through. */
const PAINTED = ['backgroundColor', 'borderColor', 'color', 'pointBackgroundColor', 'pointBorderColor'];

/* Where the builder states how faded a nought's hairline is drawn — the same
   id ChartBuilder::NOUGHTS writes it under. */
const NOUGHTS = 'noughts';

/*
 * THE SAME COLOR AT AN OPACITY. A token resolves to one of two shapes: the
 * palette's six-digit hex or a channel color (`rgb(62 217 168)`), and each
 * carries an alpha the way Chart.js's own color parser and the canvas both
 * read it — an eighth and ninth hex digit, or `/ alpha` inside rgb(). Any
 * other shape is returned as it came: an unfaded stub beats no stub.
 */
function fade(color, opacity) {
    if ('string' !== typeof color) {
        return color;
    }
    if (/^#[0-9a-fA-F]{6}$/.test(color)) {
        return color + Math.round(opacity * 255).toString(16).padStart(2, '0');
    }
    const rgb = /^rgb\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*\)$/.exec(color);
    if (rgb) {
        return `rgb(${rgb[1]} ${rgb[2]} ${rgb[3]} / ${opacity})`;
    }

    return color;
}

export default class extends Controller {
    connect() {
        this.swatches = new Map();

        this.onPreConnect = (event) => {
            this.paint(event.detail.config);
            this.hairline(event.detail.config);
            this.figure(event.detail.config);
        };
        this.element.addEventListener('chartjs:pre-connect', this.onPreConnect);

        /* The theme is a class on <html>, and it is put there before the first
           paint by the shell's own inline script — so what this catches is a
           later flip, made with the toggle while a chart is on screen. */
        this.onThemeFlip = () => this.repaint();
        this.themeWatch = new MutationObserver(this.onThemeFlip);
        this.themeWatch.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        this.onConnect = (event) => { this.chart = event.detail.chart; };
        this.element.addEventListener('chartjs:connect', this.onConnect);
    }

    disconnect() {
        this.element.removeEventListener('chartjs:pre-connect', this.onPreConnect);
        this.element.removeEventListener('chartjs:connect', this.onConnect);
        this.themeWatch?.disconnect();
        this.themeWatch = null;
    }

    /** Every painted property of every dataset, resolved in place. */
    paint(config) {
        for (const dataset of config?.data?.datasets ?? []) {
            for (const property of PAINTED) {
                if (property in dataset) {
                    dataset[property] = this.resolve(dataset[property]);
                }
            }
        }
    }

    /**
     * THE FIGURES PLUGIN, put on a chart whose options ask for one. Inline,
     * so it is this chart's and no other's; identified, so its options are
     * the block the builder wrote under the same id.
     */
    /*
     * A NOUGHT'S HAIRLINE, FADED. The builder already asked for a two-pixel
     * stub; here, once the series' token is a color, each bar series' fill
     * and stroke become one color per bar, and a nought's is the faded one —
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

    figure(config) {
        if (!config.options?.plugins?.[FIGURES]) {
            return;
        }

        const element = this.element;

        config.plugins = [...(config.plugins ?? []), {
            id: FIGURES,
            afterDatasetsDraw(chart, args, options) {
                const ctx = chart.ctx;
                const ink = getComputedStyle(element);
                const sideways = 'y' === chart.options.indexAxis;
                const unit = options.unit ? ` ${options.unit}` : '';

                ctx.save();
                ctx.font = `600 10px ${ink.getPropertyValue('--font-mono').trim() || 'monospace'}`;
                ctx.fillStyle = ink.color;

                chart.data.datasets.forEach((dataset, index) => {
                    if (!chart.isDatasetVisible(index) || 'line' === (dataset.type ?? chart.config.type)) {
                        return;
                    }

                    chart.getDatasetMeta(index).data.forEach((bar, point) => {
                        const value = dataset.data[point];
                        if (null === value || undefined === value) {
                            return;
                        }

                        const figure = Number(value).toFixed(options.precision ?? 0) + unit;
                        if (sideways) {
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'middle';
                            ctx.fillText(figure, bar.x + FIGURE_GAP, bar.y);
                        } else {
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            ctx.fillText(figure, bar.x, bar.y - FIGURE_GAP / 2);
                        }
                    });
                });

                ctx.restore();
            },
        }];
    }

    /**
     * THE SAME CHART, IN THE PALETTE THAT IS ON NOW. The tokens are gone from
     * the built configuration by the time this runs — they were resolved at
     * mount — so the source of truth is the ORIGINAL config Chart.js keeps,
     * and what is re-read is the token cache, cleared so every value is asked
     * for again.
     */
    repaint() {
        if (!this.chart) {
            return;
        }

        this.swatches.clear();
        this.paint(this.chart.config._config ?? this.chart.config);
        this.chart.update('none');
    }

    /**
     * A token's value, or whatever was handed over where it is not one.
     *
     * Cached per element: a stacked chart asks for the same six tokens once a
     * dataset, and `getComputedStyle` is a layout read.
     */
    resolve(value) {
        if ('string' !== typeof value) {
            return value;
        }

        const token = TOKEN.exec(value);
        if (!token) {
            return value;
        }

        if (!this.swatches.has(token[1])) {
            this.swatches.set(token[1], getComputedStyle(this.element).getPropertyValue(token[1]).trim() || value);
        }

        return this.swatches.get(token[1]);
    }
}
