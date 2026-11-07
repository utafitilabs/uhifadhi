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

namespace Uhifadhi\Bundle\AreaBundle\Model;

/**
 * WHAT THE READER ASKED THE BOARD FOR, read off the query string.
 *
 * SERVER-SIDE, AND THE ADDRESS IS THE STATE. A filtered board is a link
 * somebody can send, a page a browser can go back to, and a view that works
 * with no script; a board filtered in the browser is none of those and is
 * also a board that lies about its counts the moment it is longer than a page.
 *
 * AN UNKNOWN VALUE FILTERS NOTHING rather than emptying the board. A stale
 * link naming a department that has been renamed should show the post, not an
 * empty card with no way to tell why.
 */
final readonly class PostingQuery
{
    public const string ROLE = 'role';
    public const string DEPARTMENT = 'dept';
    public const string SOURCE = 'src';
    public const string SEARCH = 'q';
    public const string SORT = 'sort';

    /** The orders the bar offers, and the only ones it accepts. */
    public const string BY_NAME = 'az';
    public const string BY_SINCE = 'since';
    public const string BY_NEWEST = 'newest';
    public const string BY_ROLE = 'role';
    public const array SORTS = [self::BY_NAME, self::BY_NEWEST, self::BY_SINCE, self::BY_ROLE];

    public function __construct(
        public ?string $role = null,
        public ?string $department = null,
        public ?string $source = null,
        public string $search = '',
        public string $sort = self::BY_NAME,
    ) {
    }

    public function isFiltered(): bool
    {
        return null !== $this->role || null !== $this->department || null !== $this->source || '' !== $this->search;
    }

    /**
     * The same question with one answer changed, as a query string — how every
     * option in every dropdown is a link rather than a script.
     *
     * @return array<string, string>
     */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([
            self::ROLE => $this->role,
            self::DEPARTMENT => $this->department,
            self::SOURCE => $this->source,
            self::SEARCH => '' === $this->search ? null : $this->search,
            self::SORT => self::BY_NAME === $this->sort ? null : $this->sort,
        ], static fn (?string $v): bool => null !== $v);

        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }
}
