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

namespace Uhifadhi\Bundle\ShellBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;

/**
 * CUTS A TABLE INTO THE HOUSE FOOT'S PAGES — 20 a page unless the reader
 * chose 50, 100 or all — and writes the foot's links. Both values arrive
 * from the query string as typed, so anything not offered falls back rather
 * than failing. Every link is the page's own address with its whole query
 * kept: a new size starts again at page 1, a page arrow changes only the
 * page. Two tables on one page each pass their own pair of keys. The
 * contract is docs/register-foot.md.
 */
final readonly class TablePageService
{
    public const string PAGE = 'page';

    public const string PER = 'per';

    public function page(int $total, ?string $size, ?string $page): TablePage
    {
        $size = \in_array($size, TablePage::SIZES, true) ? $size : TablePage::DEFAULT_SIZE;
        $total = max(0, $total);

        if (TablePage::ALL === $size) {
            return new TablePage($total, $size, 1, 1);
        }

        $pages = max(1, (int) ceil($total / (int) $size));
        $current = ctype_digit((string) $page) ? (int) $page : 1;

        return new TablePage($total, $size, min($pages, max(1, $current)), $pages);
    }

    public function read(Request $request, int $total, string $pageKey = self::PAGE, string $perKey = self::PER): TablePage
    {
        return $this->page($total, self::scalar($request, $perKey), self::scalar($request, $pageKey));
    }

    /**
     * The foot's links: one per size, then the previous and the next page,
     * null where there is no page to go to.
     *
     * @return array{sizes: array{20: string, 50: string, 100: string, all: string}, previous: ?string, next: ?string}
     */
    public function links(Request $request, TablePage $table, string $pageKey = self::PAGE, string $perKey = self::PER): array
    {
        $query = $request->query->all();
        $sized = $query;
        unset($sized[$pageKey]);

        $size = static fn (string $size): string => self::href($request, [...$sized, $perKey => $size]);

        return [
            'sizes' => ['20' => $size('20'), '50' => $size('50'), '100' => $size('100'), 'all' => $size(TablePage::ALL)],
            'previous' => $table->hasPrevious() ? self::href($request, [...$query, $pageKey => (string) ($table->page - 1)]) : null,
            'next' => $table->hasNext() ? self::href($request, [...$query, $pageKey => (string) ($table->page + 1)]) : null,
        ];
    }

    private static function scalar(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function href(Request $request, array $query): string
    {
        return $request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }
}
