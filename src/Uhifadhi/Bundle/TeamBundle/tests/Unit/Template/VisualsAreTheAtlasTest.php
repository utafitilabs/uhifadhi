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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVERY VISUAL ON A TEAM PAGE IS THE ATLAS'S.
 *
 * A figure's history, a column of bars, a ranking — each is drawn by one
 * atlas component and handed data here. A template that writes its own
 * polyline or its own bar has forked the component, and the fork drifts the
 * first time either copy is edited; the page still returns 200 and looks
 * nearly right, which is how the fork survives.
 *
 * So the drawing marks themselves are refused in this bundle's templates.
 * An icon is an `<svg>` too, and is not a visual: what is refused is the
 * mark a chart is made of.
 */
final class VisualsAreTheAtlasTest extends TestCase
{
    /** What each drawing mark is, and the atlas call that draws it instead. */
    private const array FORBIDDEN = [
        'a sparkline polyline — write atlas_sparkline()' => '/<polyline\b/',
        'a plotted chart — write atlas_chart()' => '/<svg\b[^>]*\bclass="ch\b/',
        'a ranked bar — write atlas_bars()' => '/\bclass="sxbars?\b/',
    ];

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('twig' === $file->getExtension()) {
                yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('templates')]
    public function testTheTemplateDrawsNoVisualOfItsOwn(string $path): void
    {
        $source = (string) file_get_contents($path);

        foreach (self::FORBIDDEN as $mark => $pattern) {
            self::assertDoesNotMatchRegularExpression($pattern, $source, \sprintf('%s draws %s.', basename($path), $mark));
        }
    }
}
