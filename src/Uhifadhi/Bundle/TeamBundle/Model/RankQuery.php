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
 * WHAT THE RANKS REGISTER IS SHOWING — a search, once there are several
 * scales one scale, and ONE SORT: by rank (seniority, the default) or by
 * holders, either way. The address is the state: the chips, the column
 * carets and the export door are links built from it, on the same shape
 * the Positions register wears.
 */
final readonly class RankQuery
{
    public const string SEARCH = 'q';
    public const string SCALE = 'scale';
    public const string SORT = 'sort';
    public const string DIRECTION = 'dir';

    public const string ASC = 'asc';
    public const string DESC = 'desc';

    /** The sortable columns, in the table's order; the first is the default. */
    public const array SORTS = ['rank', 'holders'];

    public function __construct(
        public ?string $q = null,
        public ?string $scale = null,
        public string $sort = 'rank',
        public string $direction = self::ASC,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $string = static function (string $key) use ($request): ?string {
            $value = $request->query->get($key);
            $value = \is_string($value) ? trim($value) : '';

            return '' !== $value ? $value : null;
        };

        // A SORT THIS PAGE DOES NOT HAVE IS THE DEFAULT, never a 400: a stale
        // link shows the ladder in seniority order.
        $sort = $string(self::SORT) ?? 'rank';

        return new self(
            $string(self::SEARCH),
            $string(self::SCALE),
            \in_array($sort, self::SORTS, true) ? $sort : 'rank',
            self::DESC === $string(self::DIRECTION) ? self::DESC : self::ASC,
        );
    }

    /**
     * THE ROWS OF ONE BAND IN THE CHOSEN ORDER. Seniority is the rank's
     * place on its scale; holders ties break by seniority, so two ranks held
     * by the same number of people still read in ladder order.
     *
     * @param list<RankRow> $rows
     *
     * @return list<RankRow>
     */
    public function order(array $rows): array
    {
        $sign = self::DESC === $this->direction ? -1 : 1;
        usort($rows, function (RankRow $a, RankRow $b) use ($sign): int {
            $cmp = match ($this->sort) {
                'holders' => $a->holders <=> $b->holders,
                default => $a->order <=> $b->order,
            };

            return $sign * $cmp ?: $a->order <=> $b->order;
        });

        return $rows;
    }

    /** The direction a click on this column's header turns to. */
    public function directionFor(string $column): string
    {
        return $column === $this->sort && self::ASC === $this->direction ? self::DESC : self::ASC;
    }

    /** @return array<string, string> the address with one key set, or cleared by null */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([
            self::SEARCH => $this->q,
            self::SCALE => $this->scale,
            self::SORT => 'rank' === $this->sort ? null : $this->sort,
            self::DIRECTION => self::ASC === $this->direction ? null : $this->direction,
        ], static fn (?string $v): bool => null !== $v);
        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }

    /** @return array<string, string> the address sorted by this column, turned over when it already is */
    public function sortedBy(string $column): array
    {
        $params = $this->with(self::SORT, 'rank' === $column ? null : $column);
        unset($params[self::DIRECTION]);
        if (self::DESC === $this->directionFor($column)) {
            $params[self::DIRECTION] = self::DESC;
        }

        return $params;
    }
}
