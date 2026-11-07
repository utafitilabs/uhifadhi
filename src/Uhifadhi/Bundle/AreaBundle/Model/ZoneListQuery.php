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

use Symfony\Component\HttpFoundation\Request;

/**
 * WHAT THE ZONES REGISTER IS ASKED FOR — the order, whether a zone has a
 * station on it, and a name, under the house keys (`sort`, `stations`, `q`,
 * `page`): the register is the only list on its page.
 *
 * THE ADDRESS IS THE STATE, as everywhere else: every option is a link, so a
 * filtered table can be sent to somebody and a browser can go back to it.
 */
final readonly class ZoneListQuery
{
    public const string SORT = 'sort';
    public const string STATIONS = 'stations';
    public const string SEARCH = 'q';
    public const string PAGE = 'page';

    /** The four orders the design offers. Name is the resting one. */
    public const string BY_NAME = 'name';
    public const string BY_EXTENT = 'extent';
    public const string BY_COVERED = 'covered';
    public const string BY_STATIONS = 'stations';
    public const array ORDERS = [self::BY_NAME, self::BY_EXTENT, self::BY_COVERED, self::BY_STATIONS];

    /** Whether a zone has any post standing on it. */
    public const string WITH = 'some';
    public const string WITHOUT = 'none';
    public const array ANSWERS = [self::WITH, self::WITHOUT];

    public function __construct(
        public string $sort = self::BY_NAME,
        public ?string $stations = null,
        public string $search = '',
        public int $page = 1,
    ) {
    }

    public static function from(Request $request): self
    {
        $sort = $request->query->getString(self::SORT);
        $stations = trim($request->query->getString(self::STATIONS));

        return new self(
            \in_array($sort, self::ORDERS, true) ? $sort : self::BY_NAME,
            \in_array($stations, self::ANSWERS, true) ? $stations : null,
            trim($request->query->getString(self::SEARCH)),
            max(1, $request->query->getInt(self::PAGE, 1)),
        );
    }

    /** Whether anything but the resting state is asked for. */
    public function isFiltered(): bool
    {
        return self::BY_NAME !== $this->sort
            || null !== $this->stations
            || '' !== $this->search;
    }

    /**
     * The same question with one answer changed, as query parameters — how
     * every option in every dropdown is a link rather than a script.
     *
     * @return array<string, string>
     */
    public function with(string $key, ?string $value): array
    {
        $params = array_filter([
            // THE RESTING ORDER IS NOT IN THE ADDRESS, so an untouched table
            // has a clean link.
            self::SORT => self::BY_NAME === $this->sort ? null : $this->sort,
            self::STATIONS => $this->stations,
            self::SEARCH => '' === $this->search ? null : $this->search,
            // CHANGING A FILTER GOES BACK TO PAGE ONE; a link to a page sets
            // the page itself.
            self::PAGE => null,
        ], static fn (?string $v): bool => null !== $v);

        if (null === $value) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }

        return $params;
    }
}
