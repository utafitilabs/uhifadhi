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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Foot;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Contract\LayoutContract;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * THE REGISTER FOOT — under every house table: "n items · 20 per page" at the
 * left with the size chooser (20 · 50 · 100 · All), "page k of n" at the
 * right with an arrow only where there is a page to go to. Drawn by the shell
 * from data. Paged by GET, every choice is a link; paged in the browser, the
 * choices are buttons carrying the attributes the page's own controller reads.
 */
final class FootIncludeTest extends ContractTestCase
{
    /** @var array<string, mixed> */
    private const array LINKS = [
        'sizes' => ['20' => '/files?per=20', '50' => '/files?per=50', '100' => '/files?per=100', 'all' => '/files?per=all'],
        'previous' => '/files?page=1',
        'next' => '/files?page=3',
    ];

    /** @var array<string, mixed> */
    private const array GET = ['count' => 45, 'noun' => 'files', 'one' => 'file', 'perPage' => '20', 'page' => 2, 'pages' => 3, 'links' => self::LINKS];

    public function testTheIncludeIsAPublishedPartial(): void
    {
        self::assertContains('@Shell/_foot.html.twig', LayoutContract::PARTIALS);
    }

    public function testTheFootIsTheCountAndSizeThenThePage(): void
    {
        $foot = $this->foot();

        self::assertSame('rdf-foot', $foot->attr('class'));
        self::assertNull($foot->attr('hidden'));
        self::assertSame(['span', 'span'], $foot->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        self::assertSame('45 files · 20 per page▾', self::lead($foot));
        self::assertSame('45 files', $foot->filter(':scope > span:first-child > span')->text());
        self::assertSame('rdf-page', $foot->children()->eq(1)->attr('class'));
        self::assertSame('page 2 of 3‹›', self::squash($foot->filter('.rdf-page')->text()));
        self::assertSame('2', $foot->filter('.rdf-page > .pg')->text());
    }

    public function testTheSizeChooserListsTheFourSizes(): void
    {
        $size = $this->foot()->filter('details.i-dd.rdf-size');

        self::assertSame('20', $size->filter('summary > .pg')->text());
        self::assertSame('true', $size->filter('summary > .i-ddcaret')->attr('aria-hidden'));
        $menu = $size->filter('.i-ddmenu');
        self::assertSame('Rows per page', $menu->attr('aria-label'));
        self::assertSame(['20', '50', '100', 'All'], $menu->filter('.i-ddopt > .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['i-ddopt on', 'i-ddopt', 'i-ddopt', 'i-ddopt'], $menu->filter('.i-ddopt')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
    }

    public function testPagedByGetEveryChoiceIsALink(): void
    {
        $foot = $this->foot();

        self::assertSame(['/files?per=20', '/files?per=50', '/files?per=100', '/files?per=all'], $foot->filter('.rdf-size .i-ddmenu a.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('href')));
        self::assertSame([['Previous page', '/files?page=1'], ['Next page', '/files?page=3']], $foot->filter('.rdf-page a')->each(
            static fn (Crawler $c): array => [(string) $c->attr('aria-label'), (string) $c->attr('href')],
        ));
        self::assertCount(0, $foot->filter('button'));
    }

    public function testAnArrowOnlyWhereThereIsAPage(): void
    {
        $foot = $this->foot([...self::GET, 'count' => 1, 'page' => 1, 'pages' => 1, 'links' => [...self::LINKS, 'previous' => null, 'next' => null]]);

        self::assertSame('1 file · 20 per page▾', self::lead($foot), 'one is the singular');
        self::assertSame('page 1 of 1', self::squash($foot->filter('.rdf-page')->text()));
        self::assertCount(0, $foot->filter('.rdf-page a, .rdf-page button'));
    }

    public function testAllIsEveryRowOnOnePage(): void
    {
        $foot = $this->foot([...self::GET, 'perPage' => 'all', 'page' => 1, 'pages' => 1, 'links' => [...self::LINKS, 'previous' => null, 'next' => null]]);

        self::assertSame('all on one page▾', self::squash($foot->filter('.rdf-size > summary')->text()));
        self::assertCount(0, $foot->filter('.rdf-size > summary .pg'));
        self::assertSame(['i-ddopt', 'i-ddopt', 'i-ddopt', 'i-ddopt on'], $foot->filter('.i-ddopt')->each(static fn (Crawler $c): string => (string) $c->attr('class')));
    }

    public function testANounWithNoSingularIsTheNounAndTheCountIsGrouped(): void
    {
        self::assertSame('1,204 sheep', $this->foot(['count' => 1204, 'noun' => 'sheep', 'perPage' => 50, 'page' => 1, 'pages' => 25, 'links' => self::LINKS])->filter(':scope > span:first-child > span')->text());
    }

    public function testPagedInTheBrowserTheChoicesAreButtons(): void
    {
        $foot = $this->foot(['count' => 45, 'noun' => 'positions', 'one' => 'position', 'perPage' => '20', 'client' => true]);

        self::assertSame(['20', '50', '100', 'all'], $foot->filter('.i-ddmenu button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-per')));
        self::assertSame(['button'], array_values(array_unique($foot->filter('.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('type')))));
        self::assertSame(['true', 'false', 'false', 'false'], $foot->filter('.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('aria-pressed')));
        self::assertSame('page 1 of 3›', self::squash($foot->filter('.rdf-page')->text()), 'the server draws page one; its pages follow from the count');
        self::assertSame([['button', '1', 'Next page']], $foot->filter('.rdf-page button')->each(
            static fn (Crawler $c): array => [(string) $c->attr('type'), (string) $c->attr('data-go'), (string) $c->attr('aria-label')],
        ));
        self::assertCount(0, $foot->filter('a'));
    }

    /** The positions page's controller reads Stimulus targets and the option's data-opt. */
    public function testThePagesControllerNamesItsOwnHooks(): void
    {
        $ctl = 'uhifadhi--team-bundle--permission-positions';
        $foot = $this->foot(['count' => 0, 'noun' => 'positions', 'one' => 'position', 'perPage' => 20, 'client' => true, 'hidden' => true, 'hooks' => [
            'foot' => ['data-'.$ctl.'-target' => 'foot'],
            'count' => ['data-'.$ctl.'-target' => 'count'],
            'size' => ['data-axis' => 'size', 'data-'.$ctl.'-target' => 'chip size', 'data-action' => 'toggle->'.$ctl.'#toggled'],
            'menu' => ['data-action' => 'click->'.$ctl.'#choose'],
            'pager' => ['data-'.$ctl.'-target' => 'pager', 'data-action' => 'click->'.$ctl.'#go'],
            'option' => 'data-opt',
        ]]);

        self::assertSame('foot', $foot->attr('data-'.$ctl.'-target'));
        self::assertNotNull($foot->attr('hidden'));
        self::assertSame('count', $foot->filter('[data-'.$ctl.'-target="count"]')->attr('data-'.$ctl.'-target'));
        self::assertSame('0 positions', $foot->filter('[data-'.$ctl.'-target="count"]')->text());
        $size = $foot->filter('details.rdf-size');
        self::assertSame(['size', 'chip size', 'toggle->'.$ctl.'#toggled'], [$size->attr('data-axis'), $size->attr('data-'.$ctl.'-target'), $size->attr('data-action')]);
        self::assertSame('click->'.$ctl.'#choose', $size->filter('.i-ddmenu')->attr('data-action'));
        self::assertSame(['20', '50', '100', 'all'], $size->filter('.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-opt')));
        self::assertSame('click->'.$ctl.'#go', $foot->filter('.rdf-page')->attr('data-action'));
    }

    /** The analysis page's controller reads data-pa-* and calls All 0. */
    public function testAllCanCarryTheValueThePagesControllerReads(): void
    {
        $foot = $this->foot(['count' => 30, 'noun' => 'actors', 'one' => 'actor', 'perPage' => 20, 'client' => true, 'hooks' => [
            'foot' => ['data-pa-foot' => '', 'data-pa-noun' => 'actors', 'data-pa-one' => 'actor'],
            'count' => ['data-pa-count' => ''],
            'pager' => ['data-pa-page' => ''],
            'option' => 'data-pa-size',
            'all' => '0',
            'go' => 'data-pa-go',
        ]]);

        self::assertSame(['', 'actors', 'actor'], [$foot->attr('data-pa-foot'), $foot->attr('data-pa-noun'), $foot->attr('data-pa-one')]);
        self::assertSame('30 actors', $foot->filter('[data-pa-count]')->text());
        self::assertSame([['20', '20'], ['50', '50'], ['100', '100'], ['All', '0']], $foot->filter('.i-ddopt')->each(static fn (Crawler $c): array => [$c->text(), (string) $c->attr('data-pa-size')]));
        self::assertSame('1', $foot->filter('[data-pa-page] button')->attr('data-pa-go'));
    }

    public function testThePagerIsAServiceAModuleCanWire(): void
    {
        self::assertInstanceOf(TablePageService::class, $this->service('shell.table_page'));
    }

    /** The service's page and links are what the foot takes, as they are. */
    public function testTheServiceFeedsTheFoot(): void
    {
        $service = $this->service('shell.table_page');
        \assert($service instanceof TablePageService);
        $request = Request::create('/files?q=river&per=50&page=2');
        $table = $service->read($request, 120);

        $foot = $this->foot(['count' => $table->total, 'noun' => 'files', 'perPage' => $table->size, 'page' => $table->page, 'pages' => $table->pages, 'links' => $service->links($request, $table)]);

        self::assertSame('120 files · 50 per page▾', self::lead($foot));
        self::assertSame('page 2 of 3‹›', self::squash($foot->filter('.rdf-page')->text()));
        self::assertSame('/files?q=river&per=100', $foot->filter('.i-ddopt')->eq(2)->attr('href'));
        self::assertSame('/files?q=river&per=50&page=3', $foot->filter('.rdf-page a')->last()->attr('href'));
    }

    /** Every class the foot writes, and the house table's sub-line and door, are the sheet's. */
    public function testEveryClassTheFootWritesIsShipped(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
        $page = $this->page([self::GET, ['count' => 3, 'noun' => 'x', 'perPage' => 'all', 'client' => true]]);

        $classes = [];
        foreach ($page->filter('.rdf-foot, .rdf-foot *') as $element) {
            \assert($element instanceof \DOMElement);
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[$class] = true;
                }
            }
        }

        foreach (['rdf-foot', 'rdf-size', 'rdf-page', 'pg', 'i-dd', 'i-ddcaret', 'i-ddmenu', 'i-ddopt', 'on', 'i-ddopt-l'] as $class) {
            self::assertArrayHasKey($class, $classes, \sprintf('the foot writes .%s', $class));
        }
        foreach (array_keys($classes) as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/', $css, \sprintf('.%s is written and styled nowhere.', $class));
        }
        self::assertMatchesRegularExpression('/table\.tbl \.sub(?![\w-])[^{}]*\{/', $css, 'the house table\'s sub-line');
        self::assertMatchesRegularExpression('/table\.tbl td\.door(?![\w-])[^{}]*\{/', $css, 'the house table\'s door cell');
    }

    /**
     * @param array<string, mixed> $foot
     */
    private function foot(array $foot = self::GET): Crawler
    {
        return $this->page([$foot])->filter('.pgbody .rdf-foot');
    }

    /**
     * @param list<array<string, mixed>> $feet
     */
    private function page(array $feet): Crawler
    {
        return $this->crawl('@fixtures/foot_page.html.twig', ['feet' => $feet]);
    }

    /** The foot's left half as it reads with the chooser closed. */
    private static function lead(Crawler $foot): string
    {
        $lead = $foot->children()->eq(0);

        return self::squash($lead->filter(':scope > span')->text().' · '.$lead->filter('details > summary')->text());
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(' ▾', '▾', $text)));
    }
}
