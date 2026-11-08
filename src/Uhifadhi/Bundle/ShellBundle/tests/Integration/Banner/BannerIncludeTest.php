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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Banner;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Error\RuntimeError;
use Uhifadhi\Bundle\ShellBundle\Contract\LayoutContract;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * THE ATTENTION BANNER — one row above the content when there is something
 * to act on: an icon, the sentence (its head heavier, the cause in fog), its
 * doors at the right. Drawn by the shell from data, so a page writes none of
 * its markup; attention sits here and never in a card, a dot or an index.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-banner.css
 */
final class BannerIncludeTest extends ContractTestCase
{
    /** @var array<string, mixed> */
    private const array DRIFT = [
        'id' => 'drift',
        'title' => '4 answers changed with no recorded change',
        'text' => 'nothing in the history explains it',
        'actions' => [
            'Open' => '/team/permissions/analysis#drift',
            'Mark reviewed' => ['action' => '/team/permissions/drift/reviewed', 'fields' => ['_token' => 't0k3n', 'back' => 'team_permissions_overview']],
        ],
    ];

    public function testTheIncludeIsAPublishedPartial(): void
    {
        self::assertContains('@Shell/_banner.html.twig', LayoutContract::PARTIALS);
    }

    public function testTheBannerIsTheIconTheSentenceAndTheDoors(): void
    {
        $banner = $this->page()->filter('.pgbody div.banner');

        self::assertCount(1, $banner);
        self::assertSame(['banner', 'banner-warn'], self::classes($banner), 'warn unless told otherwise');
        self::assertSame('drift', $banner->attr('id'));
        self::assertSame(['svg', 'span', 'span'], $banner->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        self::assertSame(['banner-i', 'banner-t', 'banner-acts'], $banner->children()->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame('true', $banner->filter('svg.banner-i')->attr('aria-hidden'));

        self::assertSame('4 answers changed with no recorded change', $banner->filter('.banner-t > b')->text());
        self::assertSame('nothing in the history explains it', $banner->filter('.banner-t > span.banner-c')->text());
        self::assertSame(1, $this->page()->filter('.pgbody div.banner + div.c')->count(), 'the banner leads, the content follows');
    }

    public function testAnActionIsALinkOrAForm(): void
    {
        $acts = $this->page()->filter('div.banner .banner-acts');

        self::assertSame(['a', 'form'], $acts->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        $open = $acts->filter('a.btn.sm');
        self::assertSame('Open', $open->text());
        self::assertSame('/team/permissions/analysis#drift', $open->attr('href'));

        $form = $acts->filter('form');
        self::assertSame('post', $form->attr('method'));
        self::assertSame('/team/permissions/drift/reviewed', $form->attr('action'));
        self::assertSame([['_token', 't0k3n'], ['back', 'team_permissions_overview']], $form->filter('input[type="hidden"]')->each(
            static fn (Crawler $c): array => [(string) $c->attr('name'), (string) $c->attr('value')],
        ));
        $button = $form->filter('button.btn.sm');
        self::assertSame('submit', $button->attr('type'));
        self::assertSame('Mark reviewed', $button->text());
    }

    /**
     * A DOOR THAT FILES THE PAGE'S OWN FORM. A banner inside a form cannot
     * hold a form of its own; `{button: {name, value}}` is a submit button of
     * the form around it, posting its name and value with every other field.
     */
    public function testAnActionMaySubmitTheFormAroundTheBanner(): void
    {
        $acts = $this->page([[
            'title' => 'This point is outside Kilimani',
            'actions' => ['Move the point' => '#where', 'File it outside the area' => ['button' => ['name' => 'placement', 'value' => 'outside']]],
        ]])->filter('div.banner .banner-acts');

        self::assertSame(['a', 'button'], $acts->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        self::assertCount(0, $acts->filter('form'), 'a banner inside a form holds no form of its own');
        $button = $acts->filter('button.btn.sm');
        self::assertSame('submit', $button->attr('type'));
        self::assertSame('placement', $button->attr('name'));
        self::assertSame('outside', $button->attr('value'));
        self::assertSame('File it outside the area', $button->text());
    }

    public function testATitleAloneDrawsNoCauseAndNoDoors(): void
    {
        $banner = $this->page([['title' => '2 stations have no patrol']])->filter('div.banner');

        self::assertSame('2 stations have no patrol', $banner->filter('.banner-t > b')->text());
        self::assertCount(0, $banner->filter('.banner-c'));
        self::assertCount(0, $banner->filter('.banner-acts'));
        self::assertNull($banner->attr('id'));
        self::assertNull($banner->attr('role'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tones(): iterable
    {
        yield 'warn' => ['warn', 'triangle-alert'];
        yield 'fail' => ['fail', 'triangle-alert'];
        yield 'info' => ['info', 'info'];
        yield 'ok' => ['ok', 'triangle-alert'];
    }

    #[DataProvider('tones')]
    public function testEachToneIsAClassAndAnIcon(string $tone, string $icon): void
    {
        $banner = $this->page([['title' => 'a sentence', 'tone' => $tone]])->filter('div.banner');
        $drawn = $this->page([['title' => 'a sentence', 'icon' => 'shell:'.$icon]])->filter('div.banner svg')->html();

        self::assertSame(['banner', 'banner-'.$tone], self::classes($banner));
        self::assertSame($drawn, $banner->filter('svg')->html(), \sprintf('%s draws shell:%s', $tone, $icon));
    }

    public function testAnUnknownToneIsRefused(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/"loud"/');

        $this->page([['title' => 'a sentence', 'tone' => 'loud']]);
    }

    /** The banner with several lines: one icon, then a line a thing to act on, each its head, its cause and its door. */
    public function testSeveralLinesShareOneIcon(): void
    {
        $banner = $this->page([[
            'label' => 'Needs a decision',
            'lines' => [
                ['title' => 'Analyst has stood empty 96 days', 'text' => 'Protection Service', 'actions' => ['Open' => '/departments/staffing']],
                ['title' => '2 departments read no module', 'text' => 'Planning, Engineering'],
            ],
        ]])->filter('div.banner');

        self::assertSame(['banner', 'banner-lines', 'banner-warn'], self::classes($banner));
        self::assertSame('region', $banner->attr('role'));
        self::assertSame('Needs a decision', $banner->attr('aria-label'));
        self::assertSame(['svg', 'ul'], $banner->children()->each(static fn (Crawler $c): string => $c->nodeName()));

        $lines = $banner->filter('ul.banner-l > li');
        self::assertCount(2, $lines);
        self::assertSame(['Analyst has stood empty 96 days', '2 departments read no module'], $lines->each(static fn (Crawler $c): string => $c->filter('.banner-t > b')->text()));
        self::assertSame('/departments/staffing', $lines->eq(0)->filter('.banner-acts a.btn.sm')->attr('href'));
        self::assertCount(0, $lines->eq(1)->filter('.banner-acts'), 'a line with no door draws none');
    }

    public function testNothingToActOnDrawsNoBanner(): void
    {
        $page = $this->page([['label' => 'Needs a decision', 'lines' => []], ['title' => null], ['title' => '']]);

        self::assertCount(0, $page->filter('.banner'));
    }

    /** Every class the banner writes is one the shell's sheet defines. */
    public function testEveryClassTheBannerWritesIsShipped(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
        $page = $this->page([self::DRIFT, ['title' => 'x', 'tone' => 'fail'], ['title' => 'x', 'tone' => 'info'], ['title' => 'x', 'tone' => 'ok'], ['lines' => [['title' => 'x']]]]);

        $classes = [];
        foreach ($page->filter('.banner, .banner *') as $element) {
            \assert($element instanceof \DOMElement);
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[$class] = true;
                }
            }
        }

        foreach (['banner', 'banner-warn', 'banner-fail', 'banner-info', 'banner-ok', 'banner-lines', 'banner-i', 'banner-t', 'banner-c', 'banner-acts', 'banner-l'] as $class) {
            self::assertArrayHasKey($class, $classes, \sprintf('the banner writes .%s', $class));
        }
        foreach (array_keys($classes) as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/', $css, \sprintf('.%s is written and styled nowhere.', $class));
        }
    }

    /**
     * @param list<array<string, mixed>> $banners
     */
    private function page(array $banners = [self::DRIFT]): Crawler
    {
        return $this->crawl('@fixtures/banner_page.html.twig', ['banners' => $banners]);
    }

    /** @return list<string> */
    private static function classes(Crawler $element): array
    {
        $classes = preg_split('/\s+/', trim((string) $element->attr('class'))) ?: [];
        sort($classes);

        return $classes;
    }
}
