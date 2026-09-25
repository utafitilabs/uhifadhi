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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVERY VISUAL ON AN AREA PAGE IS THE ATLAS'S.
 *
 * A thumbnail's satellite snippet and its boundary outline are drawn by
 * `atlas_thumbnail()`; a template that writes its own has forked the
 * component, and the fork drifts the first time either copy is edited.
 */
final class VisualsAreTheAtlasTest extends TestCase
{
    /** What each drawing mark is, and the atlas call that draws it instead. */
    private const array FORBIDDEN = [
        'a thumbnail outline — write atlas_thumbnail()' => '/\bclass="ax-outline\b/',
        'a thumbnail snippet — write atlas_thumbnail()' => '/\bclass="ax-sat\b/',
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
