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

use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;

/**
 * WHAT THE STATIONS OVERVIEW STATES — the set of an area's stations, counted.
 *
 * The banner names the stations nobody is posted to; the strip counts the
 * stations, the zones that hold one, the unzoned, the departments posting
 * people and the period; the figures are the people posted, the leaders and
 * whatever the modules publish by station; the two plots rank the stations by
 * people posted and the zones by stations.
 */
final readonly class StationOverview
{
    /**
     * @param list<string>                                                                                  $unposted    the names of the stations nobody is posted to, by name
     * @param list<string>                                                                                  $departments the departments posting people here, by name
     * @param list<array{label: string, value: float, module: string, caption: string, route: string|null}> $modules     what the modules publish, at most two
     */
    public function __construct(
        public int $stations,
        public int $staffed,
        public int $zones,
        public int $zonesWithAStation,
        public int $unzoned,
        public array $departments,
        public string $month,
        public string $year,
        public int $people,
        public int $leaders,
        public array $unposted,
        public array $modules,
        public RankedBars $byStation,
        public int $stationsShown,
        public RankedBars $byZone,
    ) {
    }
}
