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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Service\CsvExportService;

#[CoversClass(CsvExportService::class)]
final class CsvExportServiceTest extends TestCase
{
    /** @return iterable<string, array{string|int|float|bool|null, string}> */
    public static function cells(): iterable
    {
        yield 'text' => ['Conservation Ranger I', 'Conservation Ranger I'];
        yield 'nothing' => [null, ''];
        yield 'a number' => [14, '14'];
        yield 'a negative number stays a number' => ['-3', '-3'];
        yield 'a formula' => ['=HYPERLINK("x")', "'=HYPERLINK(\"x\")"];
        yield 'a plus' => ['+1+1', "'+1+1"];
        yield 'an at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'a yes' => [true, 'yes'];
    }

    #[DataProvider('cells')]
    public function testACellNeverStartsAFormula(string|int|float|bool|null $value, string $written): void
    {
        self::assertSame($written, CsvExportService::cell($value));
    }

    public function testTheFileIsAnAttachmentWithTheHeaderFirst(): void
    {
        $response = (new CsvExportService())->response('ranks.csv', ['Rank', 'Code'], [['Ranger', 'R'], ['=x', null]]);

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=ranks.csv', $response->headers->get('Content-Disposition'));
        self::assertSame("Rank,Code\nRanger,R\n'=x,\n", $body);
    }
}
