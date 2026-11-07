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

namespace Uhifadhi\Bundle\TeamBundle\Model;

use Symfony\Component\HttpFoundation\Request;

/**
 * WHAT THE READER ASKED THE POSTINGS BOARD FOR, read off the query string.
 *
 * FOUR GROUPED DROPDOWNS AND THE SEARCH — area, zone, rank, whether the
 * station has anybody — which is the house filter bar and not this page's own
 * idea of one.
 *
 * SERVER-SIDE, AND THE ADDRESS IS THE STATE. A filtered board is a link
 * somebody can send and a page a browser can go back to, and it works with no
 * script — which a list filtered in the browser is not.
 */
final readonly class PostingQuery
{
    public const string AREA = 'area';
    public const string ZONE = 'zone';
    public const string RANK = 'rank';
    public const string POSTED = 'posted';
    public const string SEARCH = 'q';
    public const string SORT = 'sort';
    public const string DIRECTION = 'dir';

    public const string ASC = 'asc';
    public const string DESC = 'desc';

    /** The sortable columns, in the table's order; the first is the default. */
    public const array SORTS = ['person', 'rank', 'department'];

    /**
     * GROUND THAT BELONGS TO NO ZONE IS A PLACE, so it is an ANSWER and not an
     * empty one: zones are presence-driven and a station may stand outside
     * every one of them. An empty value would read as "all zones", which is
     * the opposite of what the reader picked.
     */
    public const string NO_ZONE = 'no zone';

    /** The station filter's two answers: it has people, or it has nobody. */
    public const string STAFFED = 'yes';
    public const string EMPTY = 'no';

    public function __construct(
        public ?string $area = null,
        public ?string $zone = null,
        public ?string $rank = null,
        public ?string $posted = null,
        public string $search = '',
        public string $sort = 'person',
        public string $direction = self::ASC,
    ) {
    }

    public static function from(Request $request): self
    {
        $posted = trim($request->query->getString(self::POSTED));

        return new self(
            area: self::clean($request->query->getString(self::AREA)),
            zone: self::clean($request->query->getString(self::ZONE)),
            rank: self::clean($request->query->getString(self::RANK)),
            // AN ANSWER THIS BOARD DOES NOT HAVE FILTERS NOTHING, rather than
            // emptying it: a stale link should show the board.
            posted: \in_array($posted, [self::STAFFED, self::EMPTY], true) ? $posted : null,
            search: trim($request->query->getString(self::SEARCH)),
            sort: \in_array($sort = trim($request->query->getString(self::SORT, 'person')), self::SORTS, true) ? $sort : 'person',
            direction: self::DESC === trim($request->query->getString(self::DIRECTION, self::ASC)) ? self::DESC : self::ASC,
        );
    }

    /**
     * A STATION'S ROWS IN THE ORDER THE SORT ASKS FOR — one column, one
     * direction, the name as the tie-breaker; and in the DEFAULT order the
     * leader stands first, because a station's rows are read from its lead —
     * an order the reader asked for is the order they get, leader included.
     *
     * @param list<PostingRow> $rows
     *
     * @return list<PostingRow>
     */
    public function order(array $rows): array
    {
        $sign = self::DESC === $this->direction ? -1 : 1;
        usort($rows, function (PostingRow $a, PostingRow $b) use ($sign): int {
            if ('person' === $this->sort && self::ASC === $this->direction && $a->leader !== $b->leader) {
                return $a->leader ? -1 : 1;
            }
            $cmp = match ($this->sort) {
                'rank' => strcasecmp($a->rank ?? '', $b->rank ?? ''),
                'department' => strcasecmp($a->department ?? '', $b->department ?? ''),
                default => strcasecmp($a->name, $b->name),
            };

            return $sign * $cmp ?: strcasecmp($a->name, $b->name);
        });

        return $rows;
    }

    /** Clicking the sorted column turns it over; any other sorts ascending. */
    public function directionFor(string $column): string
    {
        return $column === $this->sort && self::ASC === $this->direction ? self::DESC : self::ASC;
    }

    /**
     * The address of the board sorted by a column.
     *
     * @return array<string, string>
     */
    public function sortedBy(string $column): array
    {
        $params = $this->with(self::SORT, 'person' === $column ? null : $column);
        unset($params[self::DIRECTION]);
        if (self::DESC === $this->directionFor($column)) {
            $params[self::DIRECTION] = self::DESC;
        }

        return $params;
    }

    public function isFiltered(): bool
    {
        return null !== $this->area || null !== $this->zone || null !== $this->rank
            || null !== $this->posted || '' !== $this->search;
    }

    /**
     * The same question with one answer changed — how every option is a link
     * rather than a script.
     *
     * @return array<string, string>
     */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([
            self::AREA => $this->area,
            self::ZONE => $this->zone,
            self::RANK => $this->rank,
            self::POSTED => $this->posted,
            self::SEARCH => '' === $this->search ? null : $this->search,
            self::SORT => 'person' === $this->sort ? null : $this->sort,
            self::DIRECTION => self::ASC === $this->direction ? null : $this->direction,
        ], static fn (?string $v): bool => null !== $v);

        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }

    private static function clean(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
