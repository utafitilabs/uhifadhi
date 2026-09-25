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

use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatLegend;
use Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable;

/**
 * WHAT A SURFACE WRITES TO DRAW A HEAT TABLE, `{{ atlas_heatmap(table) }}`,
 * AND ITS LEGEND, `{{ atlas_heat_legend(legend) }}`.
 *
 * A COMPONENT OF ITS OWN: its models (Model\Heatmap), its templates and its
 * sheet (heat.css) share nothing with the other atlas components but the
 * sparkline a figure cell draws through `atlas_sparkline()`. The table and
 * the legend are two calls because a card puts them in two places — the
 * table in its body, the legend in its foot.
 *
 * THE CARD, THE SCROLL AND THE SORT ARE THE CALLER'S. The table is drawn
 * with the markup a sort controller reads (`th.sortable[data-sort]`,
 * `td[data-v]`, `tr.pfscope`), and the caller wraps it in whatever frame and
 * controller it mounts.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 */
final readonly class HeatmapRuntime implements RuntimeExtensionInterface
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param string $openLabel what the way into a row is called
     * @param string $openMark  the mark after that label, as markup the caller renders (an icon)
     */
    public function renderTable(HeatTable $table, string $openLabel = 'Open', string $openMark = ''): string
    {
        return $this->twig->render('@Atlas/heatmap.html.twig', ['table' => $table, 'openLabel' => $openLabel, 'openMark' => $openMark]);
    }

    public function renderLegend(HeatLegend $legend): string
    {
        return $this->twig->render('@Atlas/heat_legend.html.twig', ['legend' => $legend]);
    }
}
