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
 * WHAT THE ZONES OVERVIEW STATES — how an area is divided, counted.
 *
 * The banner names the zones no station stands in; the strip counts the
 * zones, the ground they cover and the ground they leave, the stations and
 * the last import; the figures are the zones holding a station and what the
 * modules publish by zone; the two plots rank the zones by extent and by the
 * share the modules count as covered.
 */
final readonly class ZoneOverview
{
    /**
     * @param list<string>         $unstationed the names of the zones no station stands in, by name
     * @param list<ZoneFigureCard> $modules     what the modules publish, one card a figure, at most three
     */
    public function __construct(
        public int $zones,
        public int $zonedKm2,
        public int $areaKm2,
        public int $unzonedKm2,
        public int $stations,
        public int $stationsUnzoned,
        public ?\DateTimeImmutable $importedAt,
        public ?int $importedFeatures,
        public int $zonesWithAStation,
        public array $unstationed,
        public array $modules,
        public RankedBars $byExtent,
        public RankedBars $byCovered,
        public string $month,
    ) {
    }
}
