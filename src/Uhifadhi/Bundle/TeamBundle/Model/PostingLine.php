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
 * ONE ROW OF THE ASSIGNMENTS REGISTER — a person at a station, or a station
 * nobody stands at. The station is a cell of the row, never a band over it.
 */
final readonly class PostingLine
{
    public function __construct(
        public PostingStation $station,
        /** Null for the station's one row when nobody is stationed there. */
        public ?PostingRow $row = null,
    ) {
    }

    /** The station's own line under its name: the code and the zone. */
    public function where(): string
    {
        return implode(' · ', array_filter([$this->station->code, $this->station->zoneName ?? PostingQuery::NO_ZONE]));
    }
}
