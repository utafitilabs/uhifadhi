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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Integration\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\ThumbnailRuntime;

/** `atlas_thumbnail()` IN A REAL CONTAINER: the satellite snippet and the boundary outline over it. */
#[CoversClass(ThumbnailRuntime::class)]
final class AtlasThumbnailTest extends TestCase
{
    private const string SQUARE = '{"type":"Polygon","coordinates":[[[35.0,-3.0],[35.2,-3.0],[35.2,-3.2],[35.0,-3.2],[35.0,-3.0]]]}';

    public function testABoundaryIsTheSnippetWithTheOutlineOverIt(): void
    {
        $thumbnail = Thumbnail::fromGeoJson(self::SQUARE);
        $html = self::render($thumbnail);

        self::assertStringStartsWith('<img class="ax-sat" src="'.htmlspecialchars((string) $thumbnail->imageUrl, \ENT_QUOTES).'" alt="" loading="lazy" decoding="async">', $html);
        self::assertStringContainsString('<svg class="ax-outline" viewBox="0 0 320 118" preserveAspectRatio="xMidYMid meet" aria-hidden="true"><path d="'.$thumbnail->path.'"/></svg>', $html);
    }

    /** No boundary, no snippet and no outline: the caller's neutral ground shows. */
    public function testNoBoundaryDrawsNothing(): void
    {
        self::assertSame('', self::render(Thumbnail::neutral()));
    }

    private static function render(Thumbnail $thumbnail): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate('{{ atlas_thumbnail(t) }}')->render(['t' => $thumbnail]);

        $kernel->shutdown();

        return trim($html);
    }
}
