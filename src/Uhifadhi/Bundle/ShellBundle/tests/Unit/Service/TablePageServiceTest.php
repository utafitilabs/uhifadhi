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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * CUTS A TABLE INTO THE HOUSE FOOT'S PAGES from the query string, and writes
 * the foot's links: one per size, the previous and the next page. Both
 * values arrive as typed, so anything not offered falls back rather than
 * failing.
 */
final class TablePageServiceTest extends TestCase
{
    public function testTwentyAPageAndPageOneUnlessChosen(): void
    {
        $table = new TablePageService()->page(45, null, null);

        self::assertSame('20', $table->size);
        self::assertSame(20, $table->perPage);
        self::assertSame(1, $table->page);
        self::assertSame(3, $table->pages);
    }

    public function testAnythingNotOfferedFallsBack(): void
    {
        $service = new TablePageService();

        self::assertSame('20', $service->page(45, '25', '2')->size, 'only 20, 50, 100 or all');
        self::assertSame(1, $service->page(45, '20', 'two')->page);
        self::assertSame(1, $service->page(45, '20', '-1')->page);
        self::assertSame(1, $service->page(45, '20', '0')->page);
        self::assertSame(3, $service->page(45, '20', '9')->page, 'past the end is the last page');
    }

    public function testAnEmptyTableIsOnePageOfNothing(): void
    {
        $table = new TablePageService()->page(0, '50', '4');

        self::assertSame(1, $table->page);
        self::assertSame(1, $table->pages);
        self::assertSame(0, $table->offset);
    }

    public function testAllIsOnePage(): void
    {
        $table = new TablePageService()->page(130, 'all', '3');

        self::assertSame('all', $table->size);
        self::assertSame(1, $table->page);
        self::assertSame(1, $table->pages);
    }

    public function testTheRequestsPageAndPerAreRead(): void
    {
        $table = new TablePageService()->read(Request::create('/files?per=50&page=2'), 120);

        self::assertSame('50', $table->size);
        self::assertSame(2, $table->page);
        self::assertSame(3, $table->pages);
        self::assertSame(50, $table->offset);
    }

    /** Two tables on one page each read their own pair. */
    public function testTheKeysCanBeATablesOwn(): void
    {
        $table = new TablePageService()->read(Request::create('/x?per=50&mper=100&mpage=2'), 250, 'mpage', 'mper');

        self::assertSame('100', $table->size);
        self::assertSame(2, $table->page);
    }

    public function testEverySizeLinkKeepsTheQueryAndStartsAgainAtPageOne(): void
    {
        $request = Request::create('/files/sources?state=open&q=river&page=2');
        $service = new TablePageService();
        $links = $service->links($request, $service->read($request, 45));

        self::assertSame([
            '20' => '/files/sources?state=open&q=river&per=20',
            '50' => '/files/sources?state=open&q=river&per=50',
            '100' => '/files/sources?state=open&q=river&per=100',
            'all' => '/files/sources?state=open&q=river&per=all',
        ], $links['sizes']);
    }

    public function testThePreviousAndNextAreLinksOnlyWhereThereIsAPage(): void
    {
        $service = new TablePageService();

        $middle = Request::create('/files?per=20&page=2');
        $links = $service->links($middle, $service->read($middle, 45));
        self::assertSame('/files?per=20&page=1', $links['previous']);
        self::assertSame('/files?per=20&page=3', $links['next']);

        $first = Request::create('/files');
        $links = $service->links($first, $service->read($first, 45));
        self::assertNull($links['previous']);
        self::assertSame('/files?page=2', $links['next']);

        $only = Request::create('/files?per=all');
        $links = $service->links($only, $service->read($only, 45));
        self::assertNull($links['previous']);
        self::assertNull($links['next']);
    }

    public function testTheLinksUseTheTablesOwnKeysAndLeaveTheOtherTablesAlone(): void
    {
        $request = Request::create('/x?per=50&page=2&mpage=1');
        $service = new TablePageService();
        $links = $service->links($request, $service->read($request, 250, 'mpage', 'mper'), 'mpage', 'mper');

        self::assertSame('/x?per=50&page=2&mper=100', $links['sizes']['100']);
        self::assertSame('/x?per=50&page=2&mpage=2', $links['next']);
    }

    public function testTheLinksKeepTheApplicationsBasePath(): void
    {
        $request = Request::create('/index.php/files?page=1', server: ['SCRIPT_FILENAME' => '/srv/public/index.php', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php/files']);
        $service = new TablePageService();

        self::assertSame('/index.php/files?page=2', $service->links($request, $service->read($request, 45))['next']);
    }
}
