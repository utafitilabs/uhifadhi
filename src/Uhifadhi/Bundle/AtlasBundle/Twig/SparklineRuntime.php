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
use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;

/**
 * WHAT A SURFACE WRITES TO DRAW A FIGURE'S HISTORY: `{{ atlas_sparkline(spark) }}`.
 *
 * THE CHART'S SMALLEST SIBLING. A card or a matrix cell states the history
 * and the tone; the box, the points and the breaks are drawn here, so a KPI
 * card and a matrix cell cannot disagree about what a sparkline is.
 *
 * SERVER-DRAWN, LIKE THE MONTH. A line of six points in a 70-pixel box is a
 * handful of coordinates; a canvas and a library per matrix cell would be a
 * chart engine mounted fifty times to draw fifty polylines.
 *
 * A LINE WITH FEWER THAN TWO READINGS IS NOT DRAWN, and nothing stands in
 * for it: a box with no line in it reads as a flat run.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 */
final readonly class SparklineRuntime implements RuntimeExtensionInterface
{
    private const string TEMPLATE = '@Atlas/sparkline.html.twig';

    public function __construct(private Environment $twig)
    {
    }

    public function renderSparkline(Sparkline $spark): string
    {
        $runs = $spark->runs();
        if ([] === $runs) {
            return '';
        }

        return $this->twig->render(self::TEMPLATE, [
            'runs' => $runs,
            'tone' => $spark->tone->value,
            'box' => $spark->size->value,
            'width' => (int) $spark->size->width(),
            'height' => (int) $spark->size->height(),
        ]);
    }
}
