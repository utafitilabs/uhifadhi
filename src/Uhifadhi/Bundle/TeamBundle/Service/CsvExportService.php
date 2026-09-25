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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A REGISTER'S CURRENT ROWS AS A CSV FILE — the export door every register
 * carries, under its concern's `export` verb.
 *
 * STREAMED, AS AN ATTACHMENT. The file is written as it is read, and the
 * disposition header is built by HttpFoundation's own helper, the one
 * BinaryFileResponse::setContentDisposition() calls:
 * "HeaderUtils::makeDisposition() … generates the value of the
 * Content-Disposition header" —
 * https://symfony.com/doc/current/components/http_foundation.html#serving-files
 * and vendor/symfony/http-foundation/BinaryFileResponse.php (setContentDisposition).
 *
 * A CELL NEVER STARTS A FORMULA. A value opening with = + - @ or a tab is
 * read by a spreadsheet as a formula; such a cell is prefixed with an
 * apostrophe, which is the neutralisation the OWASP "CSV Injection" page
 * prescribes (https://owasp.org/www-community/attacks/CSV_Injection).
 */
final readonly class CsvExportService
{
    private const array FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<string>                               $header
     * @param iterable<list<string|int|float|bool|null>> $rows
     */
    public function response(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($header, $rows): void {
            $handle = fopen('php://output', 'w');
            if (false === $handle) {
                return;
            }
            fputcsv($handle, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::cell(...), $row), escape: '');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    public static function cell(string|int|float|bool|null $value): string
    {
        $value = match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };

        if ('' !== $value && \in_array($value[0], self::FORMULA_STARTS, true) && !is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
