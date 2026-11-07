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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Onpage;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * ON THIS PAGE — the index a page carries left of its sections, drawn by the
 * shell from a list of rows so a page writes none of its markup.
 *
 * What is checked here is the markup the design's behaviour reads (the grid,
 * the nav, the head with its toggle, one row per section with its dot, label
 * and optional count, then the page's own sections), the pre-paint snippet
 * immediately before the grid, and that every class the partial writes is
 * one the shell's sheet ships. The scroll-spy, the flash and the rail are a
 * browser's, and are render-verified.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/team-module/vendor/house-index.js "THE MARKUP"
 */
final class OnpageIndexTest extends ContractTestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--onpage';

    /** @var list<array<string, mixed>> */
    private const array ROWS = [
        ['id' => 'first', 'label' => 'The first', 'count' => 3],
        ['id' => 'second', 'label' => 'The second', 'count' => null],
        ['id' => 'third', 'label' => 'The third', 'count' => 0],
    ];

    public function testTheGridHoldsTheNavThenThePagesSections(): void
    {
        $page = $this->page();

        $grid = $page->filter('.pgbody > div.onpage');
        self::assertCount(1, $grid);
        self::assertSame(['nav', 'div'], $grid->children()->each(static fn (Crawler $c): string => $c->nodeName()));

        $nav = $grid->filter('nav.onpage-nav');
        self::assertSame('On this page', $nav->attr('aria-label'));
        self::assertSame(self::NAME, $nav->attr('data-controller'));
        self::assertSame(['first', 'second', 'third'], $grid->filter('.onpage-main > .c')->each(static fn (Crawler $c): ?string => $c->attr('id')));
    }

    public function testTheHeadCarriesTheCollapseToggle(): void
    {
        $top = $this->page()->filter('nav.onpage-nav > .onpage-top');

        self::assertCount(1, $top);
        self::assertSame('On this page', trim($top->filter('p.onpage-h')->text()));

        $toggle = $top->filter('button.onpage-tg');
        self::assertSame('button', $toggle->attr('type'));
        self::assertSame('true', $toggle->attr('aria-expanded'));
        self::assertSame('Collapse index', $toggle->attr('aria-label'));
        self::assertSame('Collapse index', $toggle->attr('title'));
        self::assertSame('toggle', $toggle->attr('data-'.self::NAME.'-target'));
        self::assertSame('click->'.self::NAME.'#toggle', $toggle->attr('data-action'));
        self::assertCount(1, $toggle->filter('svg'), 'the double chevron');
    }

    public function testOneRowPerSectionWithItsDotLabelAndCount(): void
    {
        $rows = $this->page()->filter('nav.onpage-nav > a.onpage-row');

        self::assertSame(['#first', '#second', '#third'], $rows->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame(['The first', 'The second', 'The third'], $rows->each(static fn (Crawler $a): string => trim($a->filter('span.onpage-l')->text())));
        self::assertSame([1, 1, 1], $rows->each(static fn (Crawler $a): int => $a->filter('i.onpage-dot[aria-hidden="true"]')->count()));
        self::assertSame(['The first', 'The second', 'The third'], $rows->each(static fn (Crawler $a): ?string => $a->filter('i.onpage-dot')->attr('title')));

        self::assertSame(['3', null, '0'], $rows->each(
            static fn (Crawler $a): ?string => $a->filter('b.onpage-n')->count() > 0 ? $a->filter('b.onpage-n')->text() : null,
        ), 'a count is optional, and zero is a count');

        // The order inside a row is the design's: dot, label, count.
        self::assertSame(['i', 'span', 'b'], $rows->first()->children()->each(static fn (Crawler $c): string => $c->nodeName()));
    }

    /** A head between rows, where a page groups them. */
    public function testAHeadMayStandBetweenRows(): void
    {
        $nav = $this->page([...self::ROWS, ['head' => 'Lists'], ['id' => 'fourth', 'label' => 'The fourth']])->filter('nav.onpage-nav');

        self::assertSame(['div', 'a', 'a', 'a', 'p', 'a'], $nav->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        self::assertSame('Lists', trim($nav->children()->eq(4)->text()));
        self::assertStringContainsString('onpage-h', (string) $nav->children()->eq(4)->attr('class'));
    }

    /**
     * BEFORE THE FIRST PAINT, AND ONLY WHERE THERE IS AN INDEX. The snippet
     * stamps `html.onpage-closed` from the remembered choice or a narrow
     * viewport; it is written by the partial immediately before its grid,
     * so nothing of the index is painted before it runs and a page without
     * an index carries none of it.
     */
    public function testThePrePaintSnippetStandsImmediatelyBeforeTheGrid(): void
    {
        $html = $this->render('@fixtures/onpage_page.html.twig', ['rows' => self::ROWS]);

        self::assertMatchesRegularExpression('#<script>[^<]*uhifadhi-pp-nav[^<]*</script>\s*<div class="onpage">#', $html);
        self::assertStringContainsString("window.matchMedia('(max-width:900px)')", $html);
        self::assertStringContainsString("classList.add('onpage-closed')", $html);
        self::assertSame(1, substr_count($html, 'uhifadhi-pp-nav'));

        $plain = $this->render('@fixtures/module_page.html.twig');
        self::assertStringNotContainsString('uhifadhi-pp-nav', $plain);
        self::assertStringNotContainsString('onpage', $plain);
    }

    /** Every class the index writes is one the shell's sheet defines. */
    public function testEveryClassTheIndexWritesIsShipped(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());

        $classes = [];
        foreach ($this->page()->filter('.onpage, .onpage *') as $element) {
            \assert($element instanceof \DOMElement);
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[$class] = true;
                }
            }
        }

        foreach (['onpage', 'onpage-main', 'onpage-nav', 'onpage-top', 'onpage-h', 'onpage-tg', 'onpage-row', 'onpage-dot', 'onpage-l', 'onpage-n'] as $class) {
            self::assertArrayHasKey($class, $classes, \sprintf('the index writes .%s', $class));
        }
        foreach (array_keys($classes) as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $css, \sprintf('.%s is written and styled nowhere.', $class));
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function page(array $rows = self::ROWS): Crawler
    {
        return $this->crawl('@fixtures/onpage_page.html.twig', ['rows' => $rows]);
    }
}
