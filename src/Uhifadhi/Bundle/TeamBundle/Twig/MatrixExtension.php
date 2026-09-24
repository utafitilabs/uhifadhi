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

namespace Uhifadhi\Bundle\TeamBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * WHAT A PAGE WRITES TO HAVE A TOPIC'S MATRIX: `{{ render_matrix(m) }}`.
 *
 * ONE RENDERER FOR EVERY TOPIC, host's and module's alike. The whole
 * point of publishing a {@see \Uhifadhi\Contracts\Performance\TopicMatrix}
 * rather than a table is that what a matrix looks like is decided once:
 * the shades, the three absences, the legend that says what a shade is
 * not. A module that drew its own would draw one almost like this, and
 * "almost" is what a reader has to stop and work out.
 *
 * THE RENDERING IS A RUNTIME, so a page with no matrix on it builds
 * neither the builder nor the template.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — the extension declares, a lazy-loaded runtime named as [Runtime::class, 'method'] renders
 * @see vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php — registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension'); a reusable bundle is not autoconfigured, so config/services.php writes that tag by hand
 */
final class MatrixExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'render_matrix',
                [MatrixRuntime::class, 'renderMatrix'],
                ['is_safe' => ['html']],
            ),
        ];
    }
}
