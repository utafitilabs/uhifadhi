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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * THE SHELL HELD TO THE RULE IT PUBLISHES.
 *
 * It is the start of every chain, so it links nothing beside its own two
 * sheets and there is no chain above it to restate. What it does owe is the
 * other direction: it owns the design system, so a class its own templates
 * write and no rule of its own defines is a class nobody in the platform has.
 *
 * ONE RULE HERE IS THE SHELL'S ALONE. The widget library's sheet loads beside
 * the design system's on the same page, so it may reach for a shared class —
 * the toolbar puts a `.tgl` at the end of its row — only qualified by a class
 * of its own, or the rule escapes the library and re-styles a `.tgl` on
 * somebody else's page.
 */
final class VocabularyConformanceTest extends VocabularyConformanceTestCase
{
    /** The prefix that marks a widget-library class as the library's own. */
    private const string LIBRARY_PREFIX = 'w-';

    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function alias(): string
    {
        return 'shell';
    }

    protected static function ownStylesheets(): array
    {
        return ['shell.css', 'widget.css'];
    }

    /** Nothing: this bundle IS what every other one links. */
    protected static function linkedStylesheets(): array
    {
        return [];
    }

    public function testTheLibrarySheetRestatesNoSharedClass(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::bundlePath().'/public/widget.css'));

        $offenders = [];
        foreach (self::libraryRules($css) as $selector) {
            $offenders[] = $selector;
        }

        self::assertSame([], $offenders, \sprintf(
            'widget.css defines [%s] without scoping it to a class of its own; a shared class defined twice '
            .'renders differently depending on which sheet loaded last.',
            implode(', ', $offenders),
        ));
    }

    /**
     * THE PAGE-ACTION ROW IS THE FRAME'S AND HAS NO SECOND NAME. The frame
     * renders the wrapper and lays it out (`.pgact` in shell.css), so a row
     * under a name of the library's own is a second answer to the same
     * question — one a template can only reach by putting a class on a div the
     * frame writes, which no template can do.
     */
    public function testTheLibrarySheetLaysOutNoPageActionRowOfItsOwn(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\.w-pgact(?![\w-])/',
            (string) file_get_contents(self::bundlePath().'/public/widget.css'),
            'The frame lays out the page-action row. A second row in the library\'s sheet is a name '
            .'no template can reach and a set of values that will drift from the frame\'s.',
        );
    }

    /**
     * THE DROPDOWN'S CLASSES ARE THE SHEET'S. The chain's own check counts a
     * class a script names as shipped, and the controller's header names
     * every class of the markup it reads, so that check passes on the
     * controller alone. This one asks the sheet: each class the partial
     * writes, literally or through `ux_icon`, and each the controller
     * toggles, is defined by a rule in shell.css.
     */
    public function testEveryClassOfTheDropdownIsDefinedByTheSheet(): void
    {
        $twig = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(self::bundlePath().'/templates/_dropdown.html.twig'));
        preg_match_all('/class="([^"]*)"/', $twig, $literal);
        preg_match_all("/class: '([^']+)'/", $twig, $icons);

        $classes = ['dd-set', 'dd-end', 'dd-sm', 'dd-lift'];
        foreach ([...$literal[1], ...$icons[1]] as $attribute) {
            $attribute = (string) preg_replace('/\{[{%].*?[}%]\}/s', ' ', $attribute);
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[] = $class;
                }
            }
        }

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::bundlePath().'/public/shell.css'));
        $missing = array_values(array_filter(
            array_unique($classes),
            static fn (string $class): bool => 1 !== preg_match('/\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/', $css),
        ));

        self::assertContains('dd-i', $classes, 'the leading icon is read');
        self::assertSame([], $missing, \sprintf('The dropdown writes [%s] and shell.css defines no rule for it.', implode(', ', $missing)));
    }

    /**
     * THE BANNER'S AND THE FOOT'S CLASSES ARE THE SHEET'S, asked the same way
     * as the dropdown's: each class the partial writes literally, through
     * `ux_icon` or by a tone or a state it composes, is defined by a rule in
     * shell.css.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function partials(): iterable
    {
        yield 'the banner' => ['_banner.html.twig', ['banner-warn', 'banner-fail', 'banner-info', 'banner-ok', 'banner-lines']];
        yield 'the foot' => ['_foot.html.twig', ['on']];
    }

    /**
     * @param list<string> $composed
     */
    #[DataProvider('partials')]
    public function testEveryClassOfAPartialIsDefinedByTheSheet(string $partial, array $composed): void
    {
        $written = self::classesOfThePartial($partial);
        $classes = [...$written, ...$composed];

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::bundlePath().'/public/shell.css'));
        $missing = array_values(array_filter(
            array_unique($classes),
            static fn (string $class): bool => 1 !== preg_match('/\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/', $css),
        ));

        self::assertNotSame([], $written, \sprintf('templates/%s writes classes', $partial));
        self::assertSame([], $missing, \sprintf('%s writes [%s] and shell.css defines no rule for it.', $partial, implode(', ', $missing)));
    }

    /**
     * THE HOUSE TABLE'S SUB-LINE AND DOOR CELL are the shell's: a cell's
     * second fact in dim mono under its first, and the last column's door,
     * as narrow as it can be and never wrapped. Scoped to the house table,
     * because `.sub` is already the KPI card's line.
     */
    public function testTheHouseTablesSubLineAndDoorAreTheSheets(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::bundlePath().'/public/shell.css'));

        self::assertMatchesRegularExpression('/(^|\})\s*table\.tbl \.sub\s*\{[^}]*font-family:\s*var\(--font-mono\)[^}]*font-size:\s*11px[^}]*font-weight:\s*400[^}]*color:\s*var\(--dim\)/', $css);
        self::assertMatchesRegularExpression('/(^|\})\s*table\.tbl td\.door\s*\{[^}]*width:\s*1%[^}]*white-space:\s*nowrap/', $css);
        self::assertDoesNotMatchRegularExpression('/(^|[,}])\s*\.sub\s*[,{]/m', $css, 'a bare .sub would re-style every KPI card\'s line');
    }

    /**
     * AN OVERVIEW'S PARTS IN THE HOUSE TYPE PAIRS are the shell's, so every
     * overview page draws them alike whichever sheets it links: the summary
     * strip (`.factband.ov-band`: labels 9.5px .16em, values 13.5/600, their
     * words 12/400), the four figures (`.ov-kpis`: 26/700 mono, the line
     * 11/400) and the plots (`.ov-plots`: footer 11px, its door 11/700, the
     * key 11px).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function overviewPairs(): iterable
    {
        yield 'strip label' => ['.factband.ov-band .f .k', 'font-size:\\s*9\\.5px;\\s*letter-spacing:\\s*\\.16em'];
        yield 'strip value' => ['.factband.ov-band .f .v', 'font-size:\\s*13\\.5px;\\s*font-weight:\\s*600'];
        yield 'strip words' => ['.factband.ov-band .f .v em', 'font-size:\\s*12px;\\s*font-weight:\\s*400;\\s*margin-left:\\s*4px'];
        yield 'figure' => ['.ov-kpis .kpi b', 'font-family:\\s*var\\(--font-mono\\);\\s*font-size:\\s*26px;\\s*font-weight:\\s*700'];
        yield 'figure line' => ['.ov-kpis .kpi .sub', 'font-size:\\s*11px;\\s*font-weight:\\s*400'];
        yield 'plot footer' => ['.ov-plots .sxmore', 'font-size:\\s*11px'];
        yield 'plot door' => ['.ov-plots .sxmore a', 'font-size:\\s*11px;\\s*font-weight:\\s*700'];
        yield 'plot key' => ['.ov-plots .sxmxkey', 'font-size:\\s*11px;\\s*margin:\\s*12px 0 0'];
    }

    #[DataProvider('overviewPairs')]
    public function testAnOverviewsPartsAreTheSheets(string $selector, string $values): void
    {
        $css = (string) preg_replace('#/\\*.*?\\*/#s', '', (string) file_get_contents(self::bundlePath().'/public/shell.css'));

        self::assertMatchesRegularExpression('/(^|\\})\\s*'.preg_quote($selector, '/').'\\s*\\{[^}]*'.$values.'/', $css, $selector);
    }

    /** @return list<string> */
    private static function classesOfThePartial(string $partial): array
    {
        $path = self::bundlePath().'/templates/'.$partial;
        $twig = (string) preg_replace('/\{#.*?#\}/s', '', is_file($path) ? (string) file_get_contents($path) : '');
        preg_match_all('/class="([^"]*)"/', $twig, $literal);
        preg_match_all("/class: '([^']+)'/", $twig, $icons);

        $classes = [];
        foreach ([...$literal[1], ...$icons[1]] as $attribute) {
            $attribute = (string) preg_replace('/\{[{%].*?[}%]\}/s', ' ', $attribute);
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                if ('' !== $class && !str_ends_with($class, '-')) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Every selector in the library's sheet that carries a class and none of
     * them is the library's own.
     *
     * @return list<string>
     */
    private static function libraryRules(string $css): array
    {
        preg_match_all('/(^|\})([^{}@]+)\{/m', $css, $matches);

        $unscoped = [];
        foreach ($matches[2] as $group) {
            $selector = trim($group);
            preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $selector, $classes);
            if ([] === $classes[1]) {
                continue;
            }
            foreach ($classes[1] as $class) {
                if (str_starts_with($class, self::LIBRARY_PREFIX)) {
                    continue 2;
                }
            }
            $unscoped[] = $selector;
        }

        return $unscoped;
    }

    /**
     * THIS IS THE SHEET THE PALETTE LIVES IN. Every value in the product
     * is declared here once; the component section below it is held to
     * the same no-colour rule by the theme contract's own test.
     */
    protected static function declaresItsOwnPalette(): bool
    {
        return true;
    }
}
