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
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\BarFill;
use Uhifadhi\Bundle\AtlasBundle\Model\DotKey;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyEntry;
use Uhifadhi\Bundle\AtlasBundle\Model\KeyMark;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\BarsRuntime;

/**
 * `atlas_bars()` AND `atlas_key()` IN A REAL CONTAINER: the design's own
 * rows — a label, a track, the figure off the end — written once.
 */
#[CoversClass(BarsRuntime::class)]
final class AtlasBarsTest extends TestCase
{
    /** One row is the design's three spans: the label, the track with its fill, the bold figure and its note. */
    public function testARowIsTheDesignsLabelTrackAndFigure(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([
            new Bar('North', 31.0, figure: '31', note: ' · 38 %'),
            new Bar('South', 9.0, figure: '9', note: ' · 11 %'),
        ])]);

        self::assertSame(
            '<div class="sxbars">'
            .'<div class="sxbar"><span class="l">North</span><span class="t"><i class="f" style="width:100%"></i></span><span class="n"><b>31</b> · 38 %</span></div>'
            .'<div class="sxbar"><span class="l">South</span><span class="t"><i class="f" style="width:29%"></i></span><span class="n"><b>9</b> · 11 %</span></div>'
            .'</div>',
            self::squash($html),
        );
    }

    /** The two-part bar draws the rest beside the fill; a soft fill is the other class. */
    public function testATwoPartBarDrawsTheRestAndASoftFillIsItsOwnClass(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([new Bar('North', 31.0, rest: 4.0, figure: '31', note: '/35 · 4 vacant')])]);
        self::assertStringContainsString('<span class="t"><i class="f" style="width:88.6%"></i><i class="v" style="width:11.4%"></i></span>', $html);

        $soft = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([new Bar('North', 2.0)], BarFill::Soft)]);
        self::assertStringContainsString('<i class="b" style="width:100%"></i>', $soft);
    }

    /** A quiet row is dimmed and, where it holds nothing, its track is empty. */
    public function testAQuietRowIsDimmedAndAnEmptyOneDrawsNoFill(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([
            new Bar('North', 5.0),
            new Bar('Nowhere', 0.0, note: 'no position yet'),
        ])]);

        self::assertStringContainsString('<div class="sxbar q"><span class="l">Nowhere</span><span class="t"></span><span class="n">no position yet</span></div>', self::squash($html));
    }

    /** The key is drawn under the rows, one dot per entry, and an entry may be words alone. */
    public function testTheKeyIsDrawnUnderTheRows(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars(
            [new Bar('North', 5.0, rest: 1.0)],
            key: new DotKey([new KeyEntry('filled'), new KeyEntry('vacant', KeyMark::Rest), new KeyEntry('scaled to the largest', null)]),
        )]);

        self::assertStringContainsString(
            '</div><div class="sxmxkey"><span><i class="sxdot"></i>filled</span><span><i class="sxdot v"></i>vacant</span><span>scaled to the largest</span></div>',
            self::squash($html),
        );
    }

    /** No row: the card's own sentence, inside the rows' box. */
    public function testNoRowIsTheCardsOwnSentence(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([], empty: 'No department yet.')]);

        self::assertSame('<div class="sxbars"><p class="use">No department yet.</p></div>', self::squash($html));
    }

    /** A label is text, never markup. */
    public function testALabelAndANoteAreEscaped(): void
    {
        $html = self::render('{{ atlas_bars(bars) }}', ['bars' => new RankedBars([new Bar('<b>x</b>', 1.0, note: '<i>y</i>')])]);

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;i&gt;y&lt;/i&gt;', $html);
    }

    /** The key alone, for a matrix of dots: present, inherited, absent. */
    public function testTheKeyAloneIsTheSameComponent(): void
    {
        $html = self::render('{{ atlas_key(key) }}', ['key' => new DotKey([
            new KeyEntry('attached'),
            new KeyEntry('inherited', KeyMark::Inherited),
            new KeyEntry('not attached', KeyMark::Absent),
            new KeyEntry('soft', KeyMark::Soft),
        ])]);

        self::assertSame(
            '<div class="sxmxkey"><span><i class="sxdot"></i>attached</span><span><i class="sxdot inh"></i>inherited</span><span><i class="sxdot no"></i>not attached</span><span><i class="sxdot b"></i>soft</span></div>',
            self::squash($html),
        );
    }

    /** @param array<string, mixed> $context */
    private static function render(string $template, array $context): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        /** @var Environment $twig */
        $twig = $kernel->getContainer()->get('test.twig');
        $html = $twig->createTemplate($template)->render($context);

        $kernel->shutdown();

        return $html;
    }

    /** The markup with the whitespace between tags taken out, which is not the markup's. */
    private static function squash(string $html): string
    {
        return trim((string) preg_replace('/>\s+</', '><', $html));
    }
}
