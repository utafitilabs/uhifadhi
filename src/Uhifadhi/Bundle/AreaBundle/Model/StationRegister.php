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
 * THE STATION REGISTER AS ONE PAGE OF IT IS DRAWN: the rows in the window,
 * what the filters offer, and how the window sits in the whole.
 *
 * THE CARD IS BOUNDED BY A PAGE, NOT BY A SCROLLBAR. Eight rows, the count on
 * the left and the pages on the right — the list idiom this product uses
 * everywhere — so a card in a column keeps its height whether an area has
 * three posts or three hundred.
 *
 * THE SCOPE A COUNT IS "OF" IS THE ACTIVE SCOPE, never the whole table. "3 of
 * 12 stations" while the register is showing the active ones means three of
 * the twelve active ones, and the inactive tail is stated separately rather
 * than folded into a number that would then mean neither thing.
 */
final readonly class StationRegister
{
    /**
     * HOW MANY ROWS A PAGE OF THE REGISTER DRAWS, where the surface does not
     * say otherwise. A card in a narrow column shows fewer than a card that
     * owns the page, and the number is the design's per surface — so it
     * travels with the answer rather than being a constant three screens
     * quietly disagree about.
     */
    public const int PER_PAGE = 20;

    /**
     * @param list<StationRow>   $rows     the rows in this page's window
     * @param list<FilterOption> $zones    every zone of the area, and the unzoned ground
     * @param list<FilterOption> $activity active, inactive, and both
     * @param list<FilterOption> $posted   staffed and unstaffed
     * @param list<FilterOption> $lead     a lead appointed, or none
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $scope,
        public int $inactive,
        public array $zones,
        public array $activity,
        public array $posted,
        public array $lead,
        public int $page,
        public int $pages,
        public int $perPage = self::PER_PAGE,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }

    /** The first row of this window, counting from one, for "9–12 of 12". */
    public function from(): int
    {
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->page * $this->perPage, $this->total);
    }

    /**
     * WHAT THE ZONE CHIP SAYS WHEN ONE IS PICKED. The filter carries an
     * identifier and the chip has to read as a name, which is a lookup rather
     * than a second question of the database.
     */
    public function zoneLabel(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        foreach ($this->zones as $option) {
            if ($option->value === $value) {
                return $option->label;
            }
        }

        return null;
    }

    /** @return list<int> the page numbers, in order */
    public function pageNumbers(): array
    {
        return range(1, max(1, $this->pages));
    }
}
