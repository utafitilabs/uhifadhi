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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;

/**
 * THE HOUSE REGISTER, read off a rendered page: the `.lfilt` bar (a
 * `details.i-dd` chip an axis, its options with their counts, the
 * `.lsearch` field), the `table.tbl` with no band rows, and the shell's
 * register foot with its size chooser.
 */
trait HouseRegisterAssertions
{
    /**
     * Every chip of the card's bar: what the closed chip reads and its
     * options, each with its label, count, address and whether it is lit.
     *
     * @return list<array{chip: string, options: list<array{label: string, count: ?string, href: ?string, on: bool}>}>
     */
    protected static function chips(string $html, string $card): array
    {
        $bar = self::card($html, $card)->filter('.lfilt');
        Assert::assertCount(1, $bar, $card.' carries one house bar.');

        return $bar->filter('details.i-dd')->each(static fn (Crawler $chip): array => [
            'chip' => self::text($chip->filter('summary.mchip.i-ddt .i-ddval')),
            'options' => $chip->filter('.i-ddmenu .i-ddopt')->each(static fn (Crawler $option): array => [
                'label' => self::text($option->filter('.i-ddopt-l')),
                'count' => $option->filter('.i-ddopt-n')->count() ? self::text($option->filter('.i-ddopt-n')) : null,
                'href' => $option->attr('href'),
                'on' => str_contains(' '.$option->attr('class').' ', ' on '),
            ]),
        ]);
    }

    /** The bar is the house one: `n` chips and the search, no Shell dropdown, no sibling bar. */
    protected static function assertHouseBar(string $html, string $card, int $chips): void
    {
        $bar = self::card($html, $card)->filter('.lfilt');
        Assert::assertCount(1, $bar, $card.' carries one house bar.');
        Assert::assertCount($chips, $bar->filter('details.i-dd > summary.mchip.i-ddt'), $card.': one chip an axis.');
        Assert::assertCount(1, $bar->filter('.lsearch .fld'), $card.': the search is the house field.');
        Assert::assertCount(0, $bar->filter('.dd, [data-dd]'), $card.': the Shell dropdown is for the page head, never the bar.');
        Assert::assertCount(0, self::card($html, $card)->filter('.reg-bar, .cmfilt, .cmsearch, .pgr'), $card.': no sibling bar or pager.');
    }

    /** The register foot: the count, the size chooser 20 · 50 · 100 · All, the page of pages. */
    protected static function assertHouseFoot(string $html, string $card, string $count, string $pages = '1 of 1'): void
    {
        $foot = self::card($html, $card)->filter('.rdf-foot');
        Assert::assertCount(1, $foot, $card.' ends in the register foot.');
        Assert::assertStringStartsWith($count.' ·', self::text($foot->filter('span')->first()));
        Assert::assertSame(['20', '50', '100', 'All'], $foot->filter('details.rdf-size .i-ddopt .i-ddopt-l')->each(static fn (Crawler $o): string => self::text($o)));
        Assert::assertStringContainsString('page '.$pages, self::text($foot->filter('.rdf-page')));
    }

    /** The rows are the house table: no band rows, no folded rows, one body. */
    protected static function assertHouseTable(string $html, string $card): void
    {
        $table = self::card($html, $card)->filter('table.tbl');
        Assert::assertCount(1, $table, $card.' draws one house table.');
        Assert::assertCount(1, $table->filter('tbody'));
        Assert::assertCount(0, $table->filter('tr.sxgrp, tr.foldrow, tr.tm-bandrow, tr.dk-grp, tbody th'), $card.': no band rows.');
    }

    private static function card(string $html, string $card): Crawler
    {
        $found = new Crawler($html)->filter($card);
        Assert::assertGreaterThan(0, $found->count(), $card.' is on the page.');

        return $found->first();
    }

    private static function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->text('')));
    }
}
