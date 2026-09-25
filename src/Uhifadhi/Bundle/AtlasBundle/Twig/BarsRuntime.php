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
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;

/**
 * WHAT A SURFACE WRITES TO DRAW RANKED BARS, `{{ atlas_bars(bars) }}`, AND
 * THE KEY THAT READS A MATRIX OF DOTS, `{{ atlas_key(key) }}`.
 *
 * SERVER-DRAWN, LIKE THE SPARKLINE. A ranked bar is a label, a track and a
 * figure in three spans; its length is a percentage the model works out, and
 * the rows are the design's own markup, so no chart engine is mounted for a
 * card of nine rows.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 */
final readonly class BarsRuntime implements RuntimeExtensionInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public function renderBars(RankedBars $bars): string
    {
        return $this->twig->render('@Atlas/bars.html.twig', [
            'rows' => $bars->rows(),
            'fill' => $bars->fill->value,
            'key' => $bars->key,
            'empty' => $bars->empty,
        ]);
    }

    public function renderKey(DotKey $key): string
    {
        return $this->twig->render('@Atlas/key.html.twig', ['key' => $key]);
    }
}
