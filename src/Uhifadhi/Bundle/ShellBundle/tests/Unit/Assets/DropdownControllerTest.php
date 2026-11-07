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
 * THE DROPDOWN — its behaviour and its sheet, read as text.
 *
 * The design's vanilla script (house-dropdown.js) moved into the shell's
 * `dropdown` controller, behaviour unchanged: one panel open at a time, the
 * keyboard, the search that filters rows and recounts the group heads, the
 * list capped to end inside the viewport, the `.page` lifted above the page
 * foot while a panel is open, the bubbling choose event and the three-call
 * API. The sheet's values are the design's (house-dropdown.css), under the
 * shell's names. What a browser does with them is render-verified.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-dropdown.js
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-dropdown.css
 */
final class DropdownControllerTest extends TestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--dropdown';

    public function testTheListenersAcrossDropdownsAreInstalledOnce(): void
    {
        $js = self::controller();

        self::assertStringContainsString('let open = null', $js, 'one panel open at a time, across every dropdown');
        self::assertStringContainsString('if (connected++ === 0)', $js, 'the shared listeners go in with the first dropdown');
        self::assertStringContainsString('if (--connected === 0)', $js, 'and come out with the last');
        foreach (["document.addEventListener('click', onClick)", "document.addEventListener('keydown', onKey)", "document.addEventListener('input', onInput)", "document.addEventListener('focusout', onFocusOut)", "window.addEventListener('resize', onResize)"] as $listener) {
            self::assertStringContainsString($listener, $js);
        }
    }

    public function testTheKeyboardWorksAsTheDesignsScriptDoes(): void
    {
        $js = self::controller();

        self::assertStringContainsString("e.key === 'Escape'", $js);
        self::assertStringContainsString("e.key === 'ArrowDown' && p.panel.hidden", $js, 'ArrowDown on the trigger opens');
        self::assertStringContainsString("e.key === 'ArrowUp'", $js);
        self::assertStringContainsString("e.key === 'Home' && i > -1", $js);
        self::assertStringContainsString("e.key === 'End' && i > -1", $js);
        self::assertStringContainsString("e.key === 'Enter' && e.target === p.search", $js, 'Enter in the search chooses the first match');
        self::assertStringContainsString('!open.contains(e.relatedTarget)', $js, 'tabbing away closes');
    }

    public function testTheSearchFiltersRowsAndRecountsTheHeads(): void
    {
        $js = self::controller();

        self::assertStringContainsString("o.getAttribute('data-find') || o.textContent", $js);
        self::assertStringContainsString("g.querySelector('[data-dd-n]')", $js);
        self::assertStringContainsString("w.querySelector('.dd-none')", $js);
        self::assertStringContainsString('none.hidden = any', $js);
    }

    public function testTheListEndsInsideTheViewportAndThePageIsLifted(): void
    {
        $js = self::controller();

        self::assertStringContainsString('window.innerHeight - top - 16 - 8', $js);
        self::assertStringContainsString('Math.max(120, Math.min(360, room))', $js);
        self::assertStringContainsString("w.closest('.page')", $js);
        self::assertStringContainsString("classList.toggle('dd-lift', on)", $js);
        self::assertStringContainsString("w.classList.toggle('dd-set'", $js);
        self::assertStringContainsString("w.setAttribute('data-dd-value'", $js);
    }

    public function testAChoiceBubblesAsTheControllersEvent(): void
    {
        $js = self::controller();

        self::assertStringContainsString("const CHOOSE = '".self::NAME.":choose'", $js);
        self::assertStringContainsString('new CustomEvent(CHOOSE, { bubbles: true, detail: { value: v } })', $js);
        self::assertStringNotContainsString('pp-dd', $js, 'the design\'s names are gone');
        self::assertStringNotContainsString('ppDD', $js);
    }

    public function testTheApiIsPublished(): void
    {
        $js = self::controller();

        self::assertStringContainsString('window.shellDropdown = {', $js);
        foreach (['set: set', 'value: value', 'close: () => close(false)'] as $call) {
            self::assertStringContainsString($call, $js);
        }
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function theSheetsRules(): \Generator
    {
        yield 'the wrapper' => ['/\.dd \{ position: relative; min-width: 0; \}/'];
        yield 'the lift' => ['/\.page\.dd-lift \{ z-index: 5; \}/'];
        yield 'the trigger' => ['/\.dd-t \{[^}]*height: 45px; padding: 0 6px 0 14px;[^}]*border: 1\.5px solid var\(--ln\); border-radius: 999px;[^}]*font-size: 13\.5px; font-weight: 600;/s'];
        yield 'the leading icon' => ['/\.dd-i \{ width: 16px; height: 16px; flex: none; color: var\(--dim\); \}/'];
        yield 'the chevron' => ['/\.dd-c \{ width: 16px; height: 16px; flex: none; margin-right: 6px; color: var\(--fog\); transition: transform \.15s; \}/'];
        yield 'the chevron turns while open' => ['/\.dd-t\[aria-expanded="true"\] \.dd-c \{ transform: rotate\(180deg\); \}/'];
        yield 'the set state' => ['/\.dd\.dd-set \.dd-t \{ background: color-mix\(in srgb, var\(--acc\) 10%, transparent\); border-color: var\(--acc\); color: var\(--acc\); font-weight: 700; \}/'];
        yield 'the panel' => ['/\.dd-p \{[^}]*position: absolute; top: calc\(100% \+ 8px\); left: 0; z-index: 60;[^}]*width: max\(100%, 340px\); max-width: min\(440px, calc\(100vw - 32px\)\);[^}]*border-radius: 12px; box-shadow: var\(--shadow\);/s'];
        yield 'the panel at the end' => ['/\.dd-end \.dd-p \{ left: auto; right: 0; \}/'];
        yield 'the list never scrolls sideways' => ['/\.dd-l \{[^}]*max-height: 320px; overflow-y: auto; overflow-x: hidden;[^}]*grid-template-columns: minmax\(0, 1fr\);/s'];
        yield 'the group' => ['/\.dd-l > \[role="group"\] \{ display: grid; grid-template-columns: minmax\(0, 1fr\); gap: 0; \}/'];
        yield 'the group head' => ['/\.dd-h \{[^}]*font-family: var\(--font-mono\); font-size: 9\.5px; font-weight: 600; letter-spacing: \.16em;/s'];
        yield 'the row' => ['/\.dd-o \{[^}]*min-width: 0;[^}]*min-height: 40px; padding: 4px 12px 4px 16px;[^}]*border-radius: 8px;[^}]*font-size: 13\.5px; font-weight: 600;/s'];
        yield 'the bar' => ['/\.dd-o::before \{[^}]*width: 3px; height: 18px;/s'];
        yield 'the chosen row' => ['/\.dd-o\[aria-selected="true"\] \{ background: color-mix\(in srgb, var\(--acc\) 10%, transparent\); color: var\(--acc\); \}/'];
        yield 'the glowing bar' => ['/\.dd-o\[aria-selected="true"\]::before \{[^}]*box-shadow: 0 0 6px 1px color-mix\(in srgb, var\(--acc\) 55%, transparent\), 0 0 14px 3px color-mix\(in srgb, var\(--acc\) 22%, transparent\);/s'];
        yield 'the default row reads plain' => ['/\.dd-o\[aria-selected="true"\]\[data-default\] \{ background: transparent; color: var\(--tx\); \}/'];
        yield 'with a grey bar' => ['/\.dd-o\[aria-selected="true"\]\[data-default\]::before \{ background: var\(--fog\); box-shadow: none; \}/'];
        yield 'the muted part' => ['/\.dd-m \{[^}]*font-size: 12px; font-weight: 400; color: var\(--dim\);/s'];
        yield 'the search' => ['/\.dd-q \{[^}]*height: 44px; padding: 0 16px;[^}]*border: 1\.5px solid var\(--ln\); border-radius: 999px; background: var\(--p1\);/s'];
        yield 'the nothing-matches line' => ['/\.dd-none \{ margin: 0; padding: 8px 16px; \}/'];
    }

    #[DataProvider('theSheetsRules')]
    public function testTheSheetCarriesTheDesignsValues(string $pattern): void
    {
        self::assertMatchesRegularExpression($pattern, self::sheet());
    }

    public function testTheSectionNamesNoFontOfItsOwnAndNoDesignName(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css');
        $start = strpos($raw, '/* ==================== THE DROPDOWN');
        self::assertIsInt($start, 'shell.css carries the dropdown\'s section');
        $next = strpos($raw, '/* ====================', $start + 1);
        $section = false === $next ? substr($raw, $start) : substr($raw, $start, $next - $start);
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $section);

        self::assertStringNotContainsString('JetBrains Mono', $rules, 'mono text is var(--font-mono)');
        self::assertStringNotContainsString('pp-', $rules, 'the design\'s names are gone');
    }

    public function testTheControllerIsDeclaredInBothManifests(): void
    {
        $bundle = self::manifest(\dirname(__DIR__, 3).'/assets/package.json');
        self::assertSame('controllers/dropdown_controller.js', $bundle['dropdown']['main'] ?? null);
        self::assertSame(self::NAME, $bundle['dropdown']['name'] ?? null);
        self::assertSame('eager', $bundle['dropdown']['fetch'] ?? null);
        self::assertTrue($bundle['dropdown']['enabled'] ?? null);

        $root = self::manifest(\dirname(__DIR__, 7).'/assets/package.json');
        self::assertSame('../src/Uhifadhi/Bundle/ShellBundle/assets/controllers/dropdown_controller.js', $root['dropdown']['main'] ?? null);
        self::assertSame(self::NAME, $root['dropdown']['name'] ?? null);
        self::assertSame('eager', $root['dropdown']['fetch'] ?? null);
        self::assertTrue($root['dropdown']['enabled'] ?? null);
    }

    private static function controller(): string
    {
        $js = @file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/dropdown_controller.js');
        self::assertIsString($js, 'the shell ships assets/controllers/dropdown_controller.js');

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
