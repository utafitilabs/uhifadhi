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
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

/**
 * WHAT A SURFACE WRITES TO DRAW AN AREA'S FACE, `{{ atlas_thumbnail(t) }}`:
 * the satellite snippet for the boundary's box and the boundary outline over
 * it, both filling whatever frame the caller puts them in.
 *
 * STATIC, NOT A MAP. One keyless `<img>` and one pre-projected `<path>`: a
 * register of twenty cards mounts no Leaflet. A face with no boundary draws
 * nothing, and the caller's frame shows its neutral ground.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — a lazy-loaded extension's work lives in a RuntimeExtensionInterface class named from the extension as [Runtime::class, 'method']
 * @see vendor/symfony/twig-bundle/DependencyInjection/Compiler/RuntimeLoaderPass.php — the 'twig.runtime' tag AtlasBundle::loadExtension() writes by hand, collected into twig.runtime_loader
 */
final readonly class ThumbnailRuntime implements RuntimeExtensionInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public function renderThumbnail(Thumbnail $thumbnail): string
    {
        if (!$thumbnail->hasBoundary) {
            return '';
        }

        return $this->twig->render('@Atlas/thumbnail.html.twig', ['thumbnail' => $thumbnail, 'viewBox' => Thumbnail::VIEW_BOX]);
    }
}
