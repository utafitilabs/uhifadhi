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
 * THE ZONES TABLE AS IT IS DRAWN — one page of rows, what they were chosen
 * from, and the answers the filter row offers.
 *
 * THE SHAPE IS THE STATION REGISTER'S, because the two tables are twins: the
 * same card, the same house dropdowns, the same foot.
 */
final readonly class ZoneRegister
{
    /** The house foot's resting page size. */
    public const int PER_PAGE = 20;

    /**
     * @param list<ZoneListRow>     $rows         the page's rows
     * @param int                   $total        how many the filters left
     * @param int                   $scope        how many there are in the area
     * @param list<FilterOption>    $stations     the "has stations" answers, counted
     * @param array<string, string> $columns      the module columns, slug to heading
     * @param int                   $stationTotal every post in the area, for the card's caption
     * @param int                   $peopleTotal  every person posted, for the same caption
     * @param string                $period       what the module columns are counted over, as their heading says it
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $scope,
        public array $stations,
        public array $columns,
        public int $page,
        public int $pages,
        public int $stationTotal = 0,
        public int $peopleTotal = 0,
        public string $period = '',
        public int $perPage = self::PER_PAGE,
    ) {
    }

    /** No zone in the area at all — which is not the same as none matching. */
    public function isEmpty(): bool
    {
        return 0 === $this->scope;
    }

    public function from(): int
    {
        return 0 === $this->total ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return min($this->page * $this->perPage, $this->total);
    }
}
