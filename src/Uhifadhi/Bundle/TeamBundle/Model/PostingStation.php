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

/**
 * ONE STATION ON THE POSTINGS BOARD, and the people standing at it.
 *
 * A STATION WITH NOBODY KEEPS ITS ROW and says so in it. Dropping it
 * would hide the very thing the board's own figure counts, and "nobody is
 * posted here" is a normal state rather than an error.
 */
final readonly class PostingStation
{
    /** @param list<PostingRow> $rows */
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $code,
        public string $areaUuid,
        public string $areaName,
        public ?string $zoneName,
        public array $rows = [],
        /*
         * THE WAY INTO THE STATION ITSELF, or NULL where the ground's
         * addresses are not mounted. Those pages belong to another bundle and
         * are mounted by the APPLICATION, so generating one can fail — and a
         * board that took the page down because somebody unmounted a route
         * would be the worst possible way to learn it. No route, no link.
         */
        public ?string $url = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }

    /** The station's own line: the code, the zone and how many stand here. */
    public function note(): string
    {
        return implode(' · ', array_filter([
            $this->code,
            $this->zoneName ?? 'no zone',
            [] === $this->rows ? 'nobody stationed' : \sprintf('%d stationed', \count($this->rows)),
        ]));
    }
}
