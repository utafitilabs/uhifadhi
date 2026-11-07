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

        $classes = ['dd-set', 'dd-end', 'dd-lift'];
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
