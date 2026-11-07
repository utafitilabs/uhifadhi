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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;

/**
 * ONE PAGE OF A TABLE, as the house foot states it: how many rows, how many
 * a page holds, which page of how many, and where this page starts.
 */
final class TablePageTest extends TestCase
{
    public function testTheSizesAreTheFootsFourInOrder(): void
    {
        self::assertSame(['20', '50', '100', 'all'], TablePage::SIZES);
        self::assertSame('20', TablePage::DEFAULT_SIZE);
    }

    public function testAPageKnowsWhereItStartsAndHowManyItHolds(): void
    {
        $table = new TablePage(total: 45, size: '20', page: 3, pages: 3);

        self::assertSame(45, $table->total);
        self::assertSame(20, $table->perPage);
        self::assertSame(3, $table->page);
        self::assertSame(3, $table->pages);
        self::assertSame(40, $table->offset);
        self::assertFalse($table->isAll());
    }

    public function testTheSliceIsThisPagesRows(): void
    {
        $rows = range(1, 45);

        self::assertSame(range(1, 20), (new TablePage(45, '20', 1, 3))->slice($rows));
        self::assertSame([41, 42, 43, 44, 45], (new TablePage(45, '20', 3, 3))->slice($rows));
        self::assertSame(range(51, 100), (new TablePage(120, '50', 2, 3))->slice(range(1, 120)));
    }

    public function testAllIsEveryRowOnOnePage(): void
    {
        $table = new TablePage(total: 130, size: 'all', page: 1, pages: 1);

        self::assertNull($table->perPage, 'all holds no fixed number');
        self::assertSame(0, $table->offset);
        self::assertTrue($table->isAll());
        self::assertSame(range(1, 130), $table->slice(range(1, 130)));
    }

    public function testThereIsAPreviousAndANextOnlyWhereThereIsAPage(): void
    {
        self::assertFalse((new TablePage(45, '20', 1, 3))->hasPrevious());
        self::assertTrue((new TablePage(45, '20', 1, 3))->hasNext());
        self::assertTrue((new TablePage(45, '20', 2, 3))->hasPrevious());
        self::assertTrue((new TablePage(45, '20', 2, 3))->hasNext());
        self::assertFalse((new TablePage(45, '20', 3, 3))->hasNext());
        self::assertFalse((new TablePage(0, '20', 1, 1))->hasNext());
    }

    public function testSliceKeepsTheRowsAList(): void
    {
        $page = (new TablePage(3, '20', 1, 1))->slice(['a' => 1, 'b' => 2, 'c' => 3]);

        self::assertSame([1, 2, 3], $page);
    }
}
