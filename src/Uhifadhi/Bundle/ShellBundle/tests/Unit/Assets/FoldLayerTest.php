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
 * THE FOLD — the shell's one layer for everything that opens and shuts, read
 * as text: the design's house-fold.css under the shell's names in the shell
 * sheet, and its house-fold.js as the `fold` controller. What a browser does
 * with them is render-verified.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-fold.css
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-fold.js
 */
final class FoldLayerTest extends TestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--fold';

    /**
     * @return \Generator<string, array{string}>
     */
    public static function theSheetsRules(): \Generator
    {
        yield 'a details content eases its height' => ['/details \{ interpolate-size: allow-keywords; \}/'];
        yield 'a shut details' => ['/details::details-content \{\s*block-size: 0; opacity: 0; overflow: clip;\s*transition: block-size \.2s ease-in, opacity \.2s ease-in, content-visibility \.2s ease-in allow-discrete, overflow 0s allow-discrete;\s*\}/'];
        yield 'an open details' => ['/details\[open\]::details-content \{\s*block-size: auto; opacity: 1; overflow: visible;\s*transition: block-size \.2s ease-out, opacity \.2s ease-out, content-visibility \.2s ease-out allow-discrete, overflow 0s \.2s allow-discrete;\s*\}/'];
        yield 'the sidebar eases to its rail, on a desktop' => ['/@media \(min-width: 901px\) \{\s*html\.fold-ready \.side \{ transition: width \.2s ease-out; \}\s*html\.fold-ready \.side\.rail \{ transition: width \.2s ease-in; \}\s*@media \(prefers-reduced-motion: reduce\) \{\s*html\.fold-ready \.side, html\.fold-ready \.side\.rail \{ transition: none; \}\s*\}\s*\}/'];
        yield 'the page index eases to its rail' => ['/html\.fold-ready \.onpage \{ transition: grid-template-columns \.2s ease-out; \}\s*html\.fold-ready\.onpage-closed \.onpage \{ transition: grid-template-columns \.2s ease-in; \}/'];
        yield 'an open class fold' => ['/html\.fold-ready \.side :is\(\.ntree, \.nta-group, \.ntgroup\),\s*html\.fold-ready \.fold > \.bd,\s*html\.fold-ready \.stype > \.stun \{\s*interpolate-size: allow-keywords; block-size: auto; opacity: 1; overflow: clip;/'];
        yield 'a shut class fold' => ['/html\.fold-ready \.side :is\(\.ntree, \.nta-group, \.ntgroup\)\.closed,\s*html\.fold-ready \.fold\.shut > \.bd,\s*html\.fold-ready \.stype\.shut > \.stun \{\s*block-size: 0; opacity: 0; padding-block: 0; border-block-width: 0;/'];
        yield 'a class fold opens from nothing' => ['/@starting-style \{\s*html\.fold-ready \.side :is\(\.ntree, \.nta-group, \.ntgroup\):not\(\.closed\),\s*html\.fold-ready \.fold:not\(\.shut\) > \.bd,\s*html\.fold-ready \.stype:not\(\.shut\) > \.stun \{ block-size: 0; opacity: 0; padding-block: 0; border-block-width: 0; \}/'];
        yield 'instant under reduced motion' => ['/@media \(prefers-reduced-motion: reduce\) \{\s*details::details-content, details\[open\]::details-content,[^{]*html\.fold-ready \.stype > \.stun \{ transition: none; \}/'];
    }

    #[DataProvider('theSheetsRules')]
    public function testTheShellSheetCarriesTheDesignsFold(string $pattern): void
    {
        self::assertMatchesRegularExpression($pattern, FoldConformanceTest::foldBlock(self::sheet()));
    }

    public function testNothingEasesBeforeTheFirstPaint(): void
    {
        $js = self::controller();

        self::assertStringContainsString("const READY = 'fold-ready'", $js);
        self::assertMatchesRegularExpression('/requestAnimationFrame\(\(\) => \{\s*this\.frame = requestAnimationFrame\(\(\) => this\.root\.classList\.add\(READY\)\);/', $js, 'two frames: after the first paint');
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $js);
        self::assertStringContainsString('return this.still.matches || !this.root.classList.contains(READY);', $js);
    }

    public function testTheWidthFoldsFadeTheirContentIn(): void
    {
        $js = self::controller();

        self::assertStringContainsString("this.watchClass(side, 'rail', () => side);", $js);
        self::assertStringContainsString("this.watchClass(this.root, 'onpage-closed', () => document.querySelector('.onpage-nav'));", $js);
        self::assertStringContainsString("element.animate([{ opacity: 0 }, { opacity: 1 }], { duration: D, easing: opening ? 'ease-out' : 'ease-in' });", $js);
    }

    public function testATableRowWhoseHiddenChangesFolds(): void
    {
        $js = self::controller();

        self::assertStringContainsString("observer.observe(document.body, { attributes: true, attributeFilter: ['hidden'], attributeOldValue: true, subtree: true });", $js);
        self::assertStringContainsString('below.forEach((row) => row.animate(slide, options));', $js, 'the rows under it slide into place');
        self::assertStringContainsString('table.animate(follow, options)', $js, "the table's bottom margin follows");
        self::assertStringContainsString('const D = 200;', $js);
    }

    public function testADetailsWithoutItsContentPseudoElementFadesIn(): void
    {
        $js = self::controller();

        self::assertStringContainsString("CSS.supports('selector(::details-content)')", $js);
        self::assertStringContainsString("document.addEventListener('toggle', this.onToggle, true);", $js);
    }

    public function testTheControllerIsDeclaredInBothManifests(): void
    {
        $bundle = self::manifest(\dirname(__DIR__, 3).'/assets/package.json');
        self::assertSame('controllers/fold_controller.js', $bundle['fold']['main'] ?? null);
        self::assertSame(self::NAME, $bundle['fold']['name'] ?? null);
        self::assertTrue($bundle['fold']['enabled'] ?? null);

        $root = self::manifest(\dirname(__DIR__, 7).'/assets/package.json');
        self::assertSame('../src/Uhifadhi/Bundle/ShellBundle/assets/controllers/fold_controller.js', $root['fold']['main'] ?? null);
        self::assertSame(self::NAME, $root['fold']['name'] ?? null);
        self::assertTrue($root['fold']['enabled'] ?? null);
    }

    private static function controller(): string
    {
        $js = file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/fold_controller.js');
        self::assertIsString($js, 'the shell ships assets/controllers/fold_controller.js');

        return $js;
    }

    private static function sheet(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css');
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
