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

namespace Uhifadhi\Bundle\AtlasBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Uhifadhi\Bundle\AtlasBundle\Model\SatelliteSource;

/**
 * The one contract by which a deployment's configured imagery reaches the browser.
 *
 * A host writes ONE thing in its layout:
 *
 *     <body {{ map_basemap_attributes() }}>
 *
 * and every map on every page — the host's area maps, each module's plates —
 * draws the configured source. There is no per-template wiring and no per-module
 * wiring, which is the point: the map-legend contract says the same layer must
 * render identically everywhere, and the surest way to keep that promise is to
 * give the whole document exactly one place to read the answer from.
 *
 * WHY AN ATTRIBUTE AND NOT AN ENDPOINT. The scripts need this before the first
 * tile, on every page, for a value that changes only when a deployment is
 * reconfigured. A fetch would add a round trip to every map mount to learn
 * something the server already knew while rendering the page. It is published as
 * a data attribute for the same reason the Google key always was — the map
 * controllers are Stimulus controllers on a page the server rendered.
 *
 * Registered wherever there is a Twig, without a controller or a route, so a
 * host that installs this bundle for its assets alone still gets the function.
 *
 * IT ALSO DECLARES `render_map()`, the whole of what a module writes to have a
 * map. The two belong in one extension because they are two halves of the same
 * promise: the body attribute settles what the ground is, and the plate settles
 * what is drawn on it and what it is dressed in. The rendering itself is a
 * runtime ({@see MapPlateRuntime}), so a page with no map pays for none of it.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — the extension declares, the runtime renders
 * @see vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php — registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension'); a reusable bundle is not autoconfigured, so AtlasBundle::loadExtension() writes that tag by hand
 */
final class MapExtension extends AbstractExtension
{
    public function __construct(
        private readonly SatelliteSource $satellite,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // is_safe: the value is json_encode() output placed inside a
            // double-quoted attribute, and the encoder escapes the one character
            // that could close it (") as " only when JSON_HEX_QUOT is set —
            // which it is not. So the quotes are escaped HERE, explicitly, by
            // htmlspecialchars, and the function is marked safe because it has
            // done that escaping itself rather than because escaping is unneeded.
            new TwigFunction('map_basemap_attributes', $this->basemapAttributes(...), ['is_safe' => ['html']]),
            // The raw payload, for a host that would rather place the attribute
            // itself (a component's root element, say) than take the whole tag.
            new TwigFunction('map_basemap_payload', $this->basemapPayload(...)),
            // The plate. Declared here and rendered in a RUNTIME, so a page that
            // draws no map builds neither Twig's renderer nor UX Map's: the
            // function is always known, the machinery arrives on first use.
            new TwigFunction('render_map', [MapPlateRuntime::class, 'renderMap'], ['is_safe' => ['html']]),
            /*
             * AND THE CHART, THE PLATE'S SIBLING. Named `atlas_chart`
             * rather than `render_chart` because the library ships a
             * function of that name and this platform's chart is not the
             * library's: a module states a kind and a series, and what
             * that looks like is the atlas's to decide. Both exist; the
             * one to write is this.
             */
            new TwigFunction('atlas_chart', [ChartRuntime::class, 'renderChart'], ['is_safe' => ['html']]),

            /*
             * AND THE MONTH, THE THIRD SIBLING. `atlas_calendar` for
             * the same reason `atlas_chart` is not `render_chart`: a
             * surface NAMES the feed it wants — as it names a plate's
             * subject — and what a month looks like is the atlas's to
             * decide, not the caller's.
             */
            new TwigFunction('atlas_calendar', [CalendarRuntime::class, 'renderCalendar'], ['is_safe' => ['html']]),

            /*
             * AND A FIGURE'S HISTORY, THE SMALLEST OF THEM: the line under a
             * KPI card's figure and beside a matrix cell's movement.
             */
            new TwigFunction('atlas_sparkline', [SparklineRuntime::class, 'renderSparkline'], ['is_safe' => ['html']]),

            /*
             * AND A RANKING DRAWN AS ROWS: a label, a track and the figure
             * off the end — with the dot key that reads it, which a matrix
             * of dots also wears on its own.
             */
            new TwigFunction('atlas_bars', [BarsRuntime::class, 'renderBars'], ['is_safe' => ['html']]),
            new TwigFunction('atlas_key', [BarsRuntime::class, 'renderKey'], ['is_safe' => ['html']]),

            /*
             * AND A HEAT TABLE, with the legend it is read by: two calls,
             * because a card puts the table in its body and the legend in
             * its foot.
             */
            new TwigFunction('atlas_heatmap', [HeatmapRuntime::class, 'renderTable'], ['is_safe' => ['html']]),
            new TwigFunction('atlas_heat_legend', [HeatmapRuntime::class, 'renderLegend'], ['is_safe' => ['html']]),
        ];
    }

    public function basemapAttributes(): string
    {
        return \sprintf('data-map-satellite="%s"', htmlspecialchars($this->satellite->toJson(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * @return array{provider: string, maxZoom: int, key?: string, urlTemplate?: string, attribution?: string}
     */
    public function basemapPayload(): array
    {
        return $this->satellite->toBrowserPayload();
    }
}
