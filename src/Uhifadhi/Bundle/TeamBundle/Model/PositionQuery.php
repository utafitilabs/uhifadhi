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
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * WHAT THE READER ASKED THE POSITIONS REGISTER FOR, read off the query string
 * (ruled 2026-09-22, option B): three grouped dropdowns — where a position may
 * be placed, its seats, what it grants — a search on the name and one sort.
 * Every answer is a parameter: the address is the state; the page is the
 * shell pager's.
 */
final readonly class PositionQuery
{
    public const string SEARCH = 'q';
    public const string PLACEMENT = 'placement';
    public const string SEATS = 'seats';
    public const string GRANTS = 'grants';
    public const string SORT = 'sort';
    public const string DIRECTION = 'dir';

    /** A placement answer for the positions that allow no kind at all. */
    public const string PLACES_NOWHERE = 'none';

    public const string SEATS_VACANT = 'vacant';
    public const string SEATS_FULL = 'full';
    public const string SEATS_UNLIMITED = 'unlimited';

    public const string GRANTS_SOME = 'some';
    public const string GRANTS_NONE = 'none';
    public const string GRANTS_SENSITIVE = 'sensitive';

    public const string ASC = 'asc';
    public const string DESC = 'desc';

    /** The sortable columns, in the table's order; the first is the default. */
    public const array SORTS = ['name', 'seats', 'grants', 'sensitive', 'holders'];

    public function __construct(
        /** A ScopeKind value, `none`, or null for any. */
        public ?string $placement = null,
        public ?string $seats = null,
        public ?string $grants = null,
        public string $search = '',
        public string $sort = 'name',
        public string $direction = self::ASC,
    ) {
    }

    public static function from(Request $request): self
    {
        $placement = trim($request->query->getString(self::PLACEMENT));
        $seats = trim($request->query->getString(self::SEATS));
        $grants = trim($request->query->getString(self::GRANTS));
        $sort = trim($request->query->getString(self::SORT, 'name'));

        return new self(
            // AN ANSWER THIS PAGE DOES NOT HAVE FILTERS NOTHING, rather than
            // emptying the register: a stale link should show the list.
            placement: self::PLACES_NOWHERE === $placement || null !== ScopeKind::tryFrom($placement) ? $placement : null,
            seats: \in_array($seats, [self::SEATS_VACANT, self::SEATS_FULL, self::SEATS_UNLIMITED], true) ? $seats : null,
            grants: \in_array($grants, [self::GRANTS_SOME, self::GRANTS_NONE, self::GRANTS_SENSITIVE], true) ? $grants : null,
            search: trim($request->query->getString(self::SEARCH)),
            sort: \in_array($sort, self::SORTS, true) ? $sort : 'name',
            direction: self::DESC === trim($request->query->getString(self::DIRECTION, self::ASC)) ? self::DESC : self::ASC,
        );
    }

    public function isFiltered(): bool
    {
        return null !== $this->placement || null !== $this->seats || null !== $this->grants || '' !== $this->search;
    }

    public function matches(PositionCard $card): bool
    {
        if (null !== $this->placement) {
            $kinds = $card->allowedKinds();
            if (self::PLACES_NOWHERE === $this->placement ? [] !== $kinds : !\in_array(ScopeKind::from($this->placement), $kinds, true)) {
                return false;
            }
        }
        if (self::SEATS_VACANT === $this->seats && !($card->seatsFree() > 0)) {
            return false;
        }
        if (self::SEATS_FULL === $this->seats && !$card->isFull()) {
            return false;
        }
        if (self::SEATS_UNLIMITED === $this->seats && null !== $card->seats()) {
            return false;
        }
        if (self::GRANTS_SOME === $this->grants && $card->grantsNothing()) {
            return false;
        }
        if (self::GRANTS_NONE === $this->grants && !$card->grantsNothing()) {
            return false;
        }
        if (self::GRANTS_SENSITIVE === $this->grants && 0 === $card->sensitiveGranted) {
            return false;
        }
        if ('' !== $this->search && !str_contains(mb_strtolower($card->name()), mb_strtolower($this->search))) {
            return false;
        }

        return true;
    }

    /**
     * @param list<PositionCard> $cards
     *
     * @return list<PositionCard>
     */
    public function order(array $cards): array
    {
        $sign = self::DESC === $this->direction ? -1 : 1;
        usort($cards, function (PositionCard $a, PositionCard $b) use ($sign): int {
            $cmp = match ($this->sort) {
                // Unlimited seats sort after every counted set.
                'seats' => ($a->seats() ?? \PHP_INT_MAX) <=> ($b->seats() ?? \PHP_INT_MAX),
                'grants' => $a->concernsGranted <=> $b->concernsGranted,
                'sensitive' => $a->sensitiveGranted <=> $b->sensitiveGranted,
                'holders' => $a->seatsFilled() <=> $b->seatsFilled(),
                default => strcasecmp($a->name(), $b->name()),
            };

            return $sign * $cmp ?: strcasecmp($a->name(), $b->name());
        });

        return $cards;
    }

    public function directionFor(string $column): string
    {
        return $column === $this->sort && self::ASC === $this->direction ? self::DESC : self::ASC;
    }

    /** @return array<string, string> */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([
            self::PLACEMENT => $this->placement,
            self::SEATS => $this->seats,
            self::GRANTS => $this->grants,
            self::SEARCH => '' === $this->search ? null : $this->search,
            self::SORT => 'name' === $this->sort ? null : $this->sort,
            self::DIRECTION => self::ASC === $this->direction ? null : $this->direction,
        ], static fn (?string $v): bool => null !== $v);

        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }

    /** @return array<string, string> */
    public function sortedBy(string $column): array
    {
        $params = $this->with(self::SORT, 'name' === $column ? null : $column);
        unset($params[self::DIRECTION]);
        if (self::DESC === $this->directionFor($column)) {
            $params[self::DIRECTION] = self::DESC;
        }

        return $params;
    }
}
