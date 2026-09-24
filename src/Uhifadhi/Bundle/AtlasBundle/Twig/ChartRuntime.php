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

use Symfony\UX\Chartjs\Twig\ChartExtension;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasChart;

/**
 * WHAT A MODULE WRITES TO HAVE A CHART: `{{ render_chart(chart) }}`.
 *
 * THE PLATE'S SIBLING, DOWN TO THE SHAPE OF THE CALL. A module states an
 * {@see AtlasChart} — a kind, an axis, its series, perhaps a target —
 * and this renders the card the platform draws every chart in: a fixed
 * box, the caption under it, and the library's canvas inside.
 *
 * THE HEIGHT COMES THROUGH THE SAME DOOR A PLATE'S DOES. A custom
 * property handed in attributes sizes the CHART BOX, because a property
 * set on the canvas would size nothing; everything else is the canvas's
 * own.
 *
 * AN EMPTY CHART IS NOT DRAWN. A box with axes and no line in it reads
 * as a measurement of nought; the caller is told there is nothing and
 * says so in its own words.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 * @see vendor/symfony/twig-bundle/Resources/config/twig.php — 'twig.runtime_loader', the ContainerRuntimeLoader that builds this class on the first call
 */
final readonly class ChartRuntime implements RuntimeExtensionInterface
{
    /** The chart card, rendered through the namespace the bundle prepends. */
    private const string TEMPLATE = '@Atlas/chart.html.twig';

    /** A property handed in attributes sizes the BOX, not the canvas. */
    public const string CUSTOM_PROPERTY_PREFIX = '--';

    /**
     * THE CONTROLLER THAT RESOLVES A SERIES' CATEGORY where the chart is
     * drawn. Chart.js paints onto a canvas, and a canvas does not resolve a
     * custom property the way an element does — so the colours cross as
     * tokens and are turned into values at mount, and again when the theme
     * flips. The plate carries it, not the canvas: the canvas is UX Map's
     * and UX Chart.js's own element, and a second controller on it would be
     * a module fighting the bridge for it.
     */
    public const string CONTROLLER = 'uhifadhi--atlas-bundle--chart-plate';

    public function __construct(
        private Environment $twig,
        private ChartBuilder $charts,
        private ChartExtension $chartjs,
    ) {
    }

    /**
     * @param array<string, bool|string> $attributes attributes for the CANVAS — an aria-label, a
     *                                               module's own data attribute; a key written as a
     *                                               custom property (`--chart-height`) sizes the box
     */
    public function renderChart(AtlasChart $chart, string $title = '', string $caption = '', array $attributes = []): string
    {
        $boxStyle = [];
        foreach ($attributes as $property => $value) {
            if (str_starts_with($property, self::CUSTOM_PROPERTY_PREFIX) && \is_string($value)) {
                $boxStyle[$property] = $value;
                unset($attributes[$property]);
            }
        }

        return $this->twig->render(self::TEMPLATE, [
            'controller' => self::CONTROLLER,
            'title' => $title,
            'caption' => $caption,
            'empty' => $chart->isEmpty(),
            'unit' => $chart->unit,
            'canvas' => $chart->isEmpty() ? '' : $this->chartjs->renderChart($this->charts->chart($chart), $attributes),
            'boxStyle' => implode(';', array_map(
                static fn (string $property, string $value): string => $property.':'.$value,
                array_keys($boxStyle),
                array_values($boxStyle),
            )),
        ]);
    }
}
