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

namespace Uhifadhi\Bundle\ShellBundle\Model;

use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * ONE PAGE OF A TABLE, as the house foot states it: how many rows the
 * filters left, how many a page holds, which page this is of how many, and
 * the row it starts at. Built by {@see TablePageService}, which has already
 * clamped every value; drawn by `@Shell/_foot.html.twig`. The contract is
 * docs/register-foot.md.
 */
final readonly class TablePage
{
    /** The foot's sizes, in the order its chooser lists them. */
    public const array SIZES = ['20', '50', '100', 'all'];

    public const string DEFAULT_SIZE = '20';

    public const string ALL = 'all';

    /** The rows a page holds; null at all. */
    public ?int $perPage;

    /** The first row of this page, counted from 0. */
    public int $offset;

    /**
     * @param int    $total the rows the filters left, on every page
     * @param string $size  one of {@see self::SIZES}
     * @param int    $page  this page, from 1
     * @param int    $pages how many pages there are, at least 1
     */
    public function __construct(
        public int $total,
        public string $size,
        public int $page,
        public int $pages,
    ) {
        $this->perPage = self::ALL === $size ? null : (int) $size;
        $this->offset = null === $this->perPage ? 0 : ($page - 1) * $this->perPage;
    }

    public function isAll(): bool
    {
        return null === $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }

    /**
     * This page's rows of a table held in full.
     *
     * @template T
     *
     * @param array<T> $rows
     *
     * @return list<T>
     */
    public function slice(array $rows): array
    {
        return array_values(\array_slice($rows, $this->offset, $this->perPage));
    }
}
