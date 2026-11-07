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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE PAGE COLUMN IS FLUID, WITH A 24PX SIDE PADDING AND A 1600PX CAP — ruled
 * 7 Nov 2026, for every page in the fleet.
 *
 * Below 1600px the column fills the main area less its two 24px gutters;
 * beyond it the column stops and centres. The top bar's content cluster and
 * the shell foot are measured the same way, so the account cluster's right
 * edge and the footer's left edge land on the content's own edges at every
 * width. Three rules carry the one measure, and a value moved in one of them
 * is a seam a reader sees before anything else on a wide screen — and a
 * diff of the page does not show it, because each rule still renders.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/team-module/vendor/uhifadhi.css `.page`
 * @see /Users/eemjema/Programming/DesignsProjects/team-module/vendor/nav.css `.topbar>.tb-right`
 * @see /Users/eemjema/Programming/DesignsProjects/shell-module/vendor/shell.css `.shell-foot`
 */
#[CoversNothing]
final class PageColumnTest extends TestCase
{
    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function theMeasure(): \Generator
    {
        yield '.page cap' => ['.page', 'max-width', '1600px'];
        yield '.page centred' => ['.page', 'margin', '0 auto'];
        yield '.page gutters' => ['.page', 'padding', '26px 24px 90px'];
        yield '.page fluid' => ['.page', 'width', '100%'];

        yield 'top bar cap' => ['.topbar > .tb-right', 'max-width', '1600px'];
        yield 'top bar centred' => ['.topbar > .tb-right', 'margin', '0 auto'];
        yield 'top bar gutters' => ['.topbar > .tb-right', 'padding', '0 24px'];
        yield 'top bar fluid' => ['.topbar > .tb-right', 'width', '100%'];

        yield 'foot cap' => ['.shell-foot', 'max-width', '1600px'];
        yield 'foot centred' => ['.shell-foot', 'margin', 'auto auto 0'];
        yield 'foot gutters' => ['.shell-foot', 'padding', '0 24px 26px'];
        yield 'foot fluid' => ['.shell-foot', 'width', '100%'];
    }

    #[DataProvider('theMeasure')]
    public function testTheThreeRulesShareOneMeasure(string $selector, string $property, string $value): void
    {
        self::assertSame(
            $value,
            self::declarations($selector)[$property] ?? null,
            \sprintf('`%s` must state `%s: %s`.', $selector, $property, $value),
        );
    }

    /** The old 1280px column is gone from the sheet, prose included. */
    public function testNoRuleOrCommentStillDescribesThe1280Column(): void
    {
        self::assertStringNotContainsString('1280', (string) file_get_contents(self::path()));
    }

    /**
     * The declarations of the one top-level rule written for exactly this
     * selector, as property => value.
     *
     * @return array<string, string>
     */
    private static function declarations(string $selector): array
    {
        preg_match_all('/(?:^|(?<=\}))([^{}@]+)\{([^{}]*)\}/', self::css(), $matches, \PREG_SET_ORDER);

        $found = [];
        foreach ($matches as $match) {
            if (trim((string) preg_replace('/\s+/', ' ', $match[1])) !== $selector) {
                continue;
            }
            foreach (explode(';', $match[2]) as $declaration) {
                if (str_contains($declaration, ':')) {
                    [$property, $value] = explode(':', $declaration, 2);
                    $found[trim($property)] = trim($value);
                }
            }
        }

        self::assertNotSame([], $found, \sprintf('No `%s` rule in shell.css at all.', $selector));

        return $found;
    }

    /** The sheet with its comments out, so prose never satisfies a rule. */
    private static function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::path()));
    }

    private static function path(): string
    {
        return \dirname(__DIR__, 3).'/public/shell.css';
    }
}
