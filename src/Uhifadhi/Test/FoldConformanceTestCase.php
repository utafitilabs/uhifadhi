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

namespace Uhifadhi\Test;

use PHPUnit\Framework\TestCase;

/**
 * EVERY FOLD EASES THE SAME WAY, FROM ONE PLACE: the shell sheet's "HOUSE —
 * THE FOLD" block. A sheet that gives one of those folds a transition of its
 * own makes that fold move differently from every other, so no sheet a package
 * ships may: a <details>'s content, the sidebar's width (the drawer's slide
 * below 901px is a transform, and is not a fold), the page index's columns,
 * the sidebar tree's groups, a block fold's body and a station type's
 * settings. A package extends this and names its sheets.
 */
abstract class FoldConformanceTestCase extends TestCase
{
    /**
     * The selector's last compound, as a pattern, and the transitioned
     * properties that make it a fold (null: any transition at all).
     */
    private const array FOLDS = [
        '<details> content' => ['/::details-content$/', null],
        'the sidebar tree' => ['/\.(?:ntree|nta-group|ntgroup)(?![-\w])[^\s>+~]*$/', null],
        'a block fold' => ['/\.fold(?![-\w])[^\s>+~]*\s*>\s*\.bd(?![-\w])[^\s>+~]*$/', null],
        'a station type' => ['/\.stun(?![-\w])[^\s>+~]*$/', null],
        'the page index' => ['/\.onpage(?![-\w])[^\s>+~]*$/', ['grid-template-columns', 'all']],
        'the sidebar' => ['/\.side(?![-\w])[^\s>+~]*$/', ['width', 'all']],
    ];

    /**
     * Every sheet the package ships, path => contents.
     *
     * @return iterable<string, string>
     */
    abstract protected static function sheets(): iterable;

    public function testTheSheetsAreRead(): void
    {
        self::assertNotEmpty([...static::sheets()], 'a conformance check over no sheet proves nothing');
    }

    public function testNoSheetGivesAHouseFoldATransitionOfItsOwn(): void
    {
        $found = [];
        foreach (static::sheets() as $path => $css) {
            foreach (self::foldTransitions($css) as [$fold, $selector]) {
                $found[] = \sprintf('%s: %s (%s)', $path, $selector, $fold);
            }
        }

        self::assertSame([], $found, "These rules give a house fold a transition of their own; the shell's fold block is the one place a fold eases:\n".implode("\n", $found));
    }

    /**
     * The rules of a sheet that transition a house fold, as [fold, selector].
     *
     * @return list<array{string, string}>
     */
    public static function foldTransitions(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, \PREG_SET_ORDER);

        $out = [];
        foreach ($rules as [, $selectors, $body]) {
            if (0 === preg_match_all('/(?:^|;)\s*transition(?:-property)?\s*:\s*([^;]+)/', $body, $declared)) {
                continue;
            }
            $transitioned = strtolower(implode(',', $declared[1]));
            if (1 === preg_match('/^\s*none\s*$/', $transitioned)) {
                continue;
            }
            foreach (self::selectorsOf($selectors) as $selector) {
                foreach (self::FOLDS as $fold => [$pattern, $properties]) {
                    if (1 !== preg_match($pattern, $selector)) {
                        continue;
                    }
                    if (null === $properties || [] !== array_filter($properties, static fn (string $p): bool => 1 === preg_match('/(?:^|[\s,])'.preg_quote($p, '/').'(?:[\s,]|$)/', $transitioned))) {
                        $out[] = [$fold, $selector];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A selector list split at its top-level commas, each one on one line with
     * no space inside a parenthesis, so `:is(.a, .b)` stays one compound.
     *
     * @return list<string>
     */
    private static function selectorsOf(string $list): array
    {
        $list = trim((string) preg_replace('/\s+/', ' ', $list));
        $out = [];
        $depth = 0;
        $current = '';
        foreach (mb_str_split($list) as $char) {
            $depth += '(' === $char ? 1 : (')' === $char ? -1 : 0);
            if (',' === $char && 0 === $depth) {
                $out[] = trim($current);
                $current = '';
                continue;
            }
            $current .= ($depth > 0 && ' ' === $char) ? '' : $char;
        }
        $out[] = trim($current);

        return array_values(array_filter($out, static fn (string $s): bool => '' !== $s));
    }
}
