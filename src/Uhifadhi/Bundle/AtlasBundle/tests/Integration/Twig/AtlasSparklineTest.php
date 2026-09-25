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
use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\SparklineRuntime;

/**
 * `atlas_sparkline()` IN A REAL CONTAINER: the line a KPI card and a
 * matrix cell draw under a figure, written once.
 */
#[CoversClass(SparklineRuntime::class)]
final class AtlasSparklineTest extends TestCase
{
    /** The card's line: the box the design draws, one polyline per run, toned by a class. */
    public function testACardsLineIsTheDesignsBoxWithOnePolylinePerRun(): void
    {
        $html = self::render(new Sparkline([1.0, 3.0, null, 2.0, 3.0], SparkTone::Good, SparkSize::Card));

        self::assertSame(
            '<svg class="sk" width="100" height="26" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true">'
            .'<polyline class="up" points="0.0,23.0 25.0,3.0"/>'
            .'<polyline class="up" points="75.0,13.0 100.0,3.0"/>'
            .'</svg>',
            trim($html),
        );
    }

    /** A matrix cell's line is the same component at the cell's size. */
    public function testACellsLineIsTheSameComponentAtTheCellsSize(): void
    {
        $html = self::render(new Sparkline([4.0, 5.0], SparkTone::Bad, SparkSize::Cell));

        self::assertStringStartsWith('<svg class="spark" width="70" height="18" viewBox="0 0 70 18"', trim($html));
        self::assertStringContainsString('<polyline class="dn" points="0.0,15.0 70.0,3.0"/>', $html);
    }

    /** No line is no element: a box with nothing in it would read as a flat month. */
    public function testASparklineWithFewerThanTwoReadingsDrawsNothing(): void
    {
        self::assertSame('', trim(self::render(new Sparkline([null, 4.0]))));
    }

    /** A line is never a color: the tone is a class, and no stroke crosses the wire. */
    public function testTheLineCarriesNoColorOfItsOwn(): void
    {
        $html = self::render(new Sparkline([1.0, 2.0], SparkTone::Flat));

        self::assertStringNotContainsString('stroke', $html);
        self::assertStringNotContainsString('var(--', $html);
    }

    private static function render(Sparkline $spark): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate('{{ atlas_sparkline(spark) }}')->render(['spark' => $spark]);

        $kernel->shutdown();

        return $html;
    }
}
