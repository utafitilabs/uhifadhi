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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ON THIS PAGE — the index's behaviour and its sheet, read as text.
 *
 * The design's vanilla script (house-index.js) moved into the shell's
 * `onpage` controller, behaviour unchanged: the scroll-spy that marks the
 * current row, the click that scrolls to the sticky offset and flashes the
 * target, the collapse to a rail remembered under `uhifadhi-pp-nav` and
 * forced at 900px and below, and the four-call API. The sheet's values are
 * the design's (house-index.css), under the shell's names. What a browser
 * does with them is render-verified.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/team-module/vendor/house-index.js
 * @see /Users/eemjema/Programming/DesignsProjects/team-module/vendor/house-index.css
 */
final class OnpageControllerTest extends TestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--onpage';

    public function testTheCollapseIsRememberedAndForcedWhenNarrow(): void
    {
        $js = self::controller();

        self::assertStringContainsString("const KEY = 'uhifadhi-pp-nav'", $js);
        self::assertStringContainsString("const CLOSED = 'onpage-closed'", $js);
        self::assertStringContainsString("matchMedia('(max-width:900px)')", $js);
        self::assertStringContainsString("localStorage.setItem(KEY, 'closed')", $js);
        self::assertStringContainsString('localStorage.removeItem(KEY)', $js);
        self::assertStringContainsString('if (!this.narrow.matches)', $js, 'a toggle at 900px and below is not remembered');
        self::assertStringContainsString("'Expand index' : 'Collapse index'", $js);
    }

    public function testTheScrollSpyMarksTheCurrentRow(): void
    {
        $js = self::controller();

        self::assertStringContainsString("setAttribute('aria-current', 'true')", $js);
        self::assertStringContainsString("removeAttribute('aria-current')", $js);
        self::assertStringContainsString('+ 24', $js, 'the sticky offset is the top bar plus 24px');
        self::assertStringContainsString('this.offset + 16', $js, 'the line is 16px under the offset');
        self::assertStringContainsString("querySelector('.topbar')", $js);
        self::assertStringContainsString('requestAnimationFrame', $js);
        self::assertStringContainsString('{ passive: true }', $js);
    }

    public function testAClickScrollsToTheOffsetAndFlashesTheTarget(): void
    {
        $js = self::controller();

        self::assertStringContainsString("classList.add('onpage-flash')", $js);
        self::assertStringContainsString("classList.remove('onpage-flash')", $js);
        self::assertStringContainsString("'animationend'", $js);
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion:reduce)')", $js);
        self::assertStringContainsString("behavior: this.still.matches ? 'auto' : 'smooth'", $js);
        self::assertStringContainsString('history.replaceState', $js);
        self::assertStringContainsString('setTimeout(release, 1000)', $js, 'the row is held current until the scroll ends');
    }

    public function testTheApiIsPublishedAndANoopWithoutAnIndex(): void
    {
        $js = self::controller();

        self::assertStringContainsString('window.shellOnpage = NOOP', $js);
        foreach (['row: (id)', 'current: (id)', 'hide: (id, hidden)', 'count: (id, n)'] as $call) {
            self::assertStringContainsString($call, $js);
        }
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function theSheetsRules(): \Generator
    {
        yield 'the grid' => ['/\.onpage \{ display: grid; grid-template-columns: 220px minmax\(0, 1fr\); gap: 24px; align-items: start; \}/'];
        yield 'the cards one level down' => ['/\.onpage-main > \.c \{ margin-bottom: 20px; \}/'];
        yield 'the scroll margin' => ['/\.onpage \[id\] \{ scroll-margin-top: 80px; \}/'];
        yield 'the sticky nav' => ['/\.onpage-nav \{ position: sticky; top: 80px;[^}]*max-height: calc\(100vh - 96px\);/'];
        yield 'the row' => ['/a\.onpage-row \{[^}]*min-height: 40px; padding: 0 12px 0 16px; border-radius: 12px;[^}]*font-size: 13\.5px;/s'];
        yield 'the bar' => ['/a\.onpage-row::before \{[^}]*width: 3px; height: 18px;/s'];
        yield 'the current row' => ['/a\.onpage-row\[aria-current\] \{ background: color-mix\(in srgb, var\(--acc\) 10%, transparent\); color: var\(--acc\); font-weight: 600; \}/'];
        yield 'the glowing bar' => ['/a\.onpage-row\[aria-current\]::before \{[^}]*box-shadow: 0 0 6px 1px color-mix\(in srgb, var\(--acc\) 55%, transparent\), 0 0 14px 3px color-mix\(in srgb, var\(--acc\) 22%, transparent\);/s'];
        yield 'the head' => ['/\.onpage-h \{[^}]*font-family: var\(--font-mono\); font-size: 9\.5px; font-weight: 600;[^}]*letter-spacing: \.16em;/s'];
        yield 'the toggle' => ['/\.onpage-tg \{[^}]*width: 44px; height: 44px;/s'];
        yield 'the dot' => ['/\.onpage-dot \{[^}]*width: 6px; height: 6px; border-radius: 50%;/s'];
        yield 'the count' => ['/\.onpage-n \{[^}]*font-size: 11px;/s'];
        yield 'the flash' => ['/\.onpage-flash \{ animation: onpage-flash 2s ease-out; \}/'];
        yield 'the flash rests on the card shadow' => ['/\.c \{ --_onpage-flash-rest: var\(--shadow\); \}/'];
        yield 'the keyframes' => ['/@keyframes onpage-flash \{\s*0%, 40% \{ box-shadow: 0 0 14px 3px[^}]*\}\s*100% \{ box-shadow: var\(--_onpage-flash-rest, none\); \}\s*\}/s'];
        yield 'held under reduced motion' => ['/@media \(prefers-reduced-motion: reduce\) \{ \.onpage-flash \{ animation: onpage-flash-still 2s step-end; \} \}/'];
        yield 'the rail' => ['/html\.onpage-closed \.onpage \{ grid-template-columns: 48px minmax\(0, 1fr\); \}/'];
        yield 'the rail pill' => ['/html\.onpage-closed a\.onpage-row \{[^}]*width: 36px; height: 36px; min-height: 36px; padding: 0; border-radius: 4px;/s'];
    }

    #[DataProvider('theSheetsRules')]
    public function testTheSheetCarriesTheDesignsValues(string $pattern): void
    {
        self::assertMatchesRegularExpression($pattern, self::sheet());
    }

    public function testTheControllerIsDeclaredInBothManifests(): void
    {
        $bundle = self::manifest(\dirname(__DIR__, 3).'/assets/package.json');
        self::assertSame('controllers/onpage_controller.js', $bundle['onpage']['main'] ?? null);
        self::assertSame(self::NAME, $bundle['onpage']['name'] ?? null);
        self::assertTrue($bundle['onpage']['enabled'] ?? null);

        $root = self::manifest(\dirname(__DIR__, 7).'/assets/package.json');
        self::assertSame('../src/Uhifadhi/Bundle/ShellBundle/assets/controllers/onpage_controller.js', $root['onpage']['main'] ?? null);
        self::assertSame(self::NAME, $root['onpage']['name'] ?? null);
        self::assertTrue($root['onpage']['enabled'] ?? null);
    }

    private static function controller(): string
    {
        $js = file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/onpage_controller.js');
        self::assertIsString($js, 'the shell ships assets/controllers/onpage_controller.js');

        return $js;
    }

    private static function sheet(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function manifest(string $path): array
    {
        $package = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertIsArray($package['symfony'] ?? null);
        $controllers = $package['symfony']['controllers'] ?? null;
        self::assertIsArray($controllers);

        /** @var array<string, array<string, mixed>> $controllers */
        return $controllers;
    }
}
