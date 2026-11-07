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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Model\StationOverview;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE STATIONS OVERVIEW, counted from the stations, their postings and what
 * the modules publish by station.
 *
 * NO MODULE IS NAMED HERE. A module that reports by station tags a figure
 * provider; this reads the tag through the seam and adds up whatever comes
 * back, one figure per module, in module order, at most the two the row has
 * room for beside the area's own two.
 */
final readonly class StationOverviewService
{
    /** How many figures of the four the modules may fill. */
    public const int MODULE_CARDS = 2;

    /** How many stations the ranked plot draws before it points at the register. */
    public const int STATIONS_SHOWN = 12;

    public function __construct(
        private StationRepository $stations,
        private PostingRepository $postings,
        private PostingBoardService $board,
        private ZoneSetService $set,
        private StationFigureService $figures,
        private AreaModuleService $areaModules,
        private ModuleEntryRouteResolver $entryRoutes,
        private Periods $periods,
    ) {
    }

    public function overview(AreaOfInterest $area): StationOverview
    {
        $counts = $this->postings->countStandingPerStation($area);
        $led = $this->postings->ledStationUuids($area);

        $zones = [];
        foreach ($this->set->view($area)->rows as $zone) {
            $zones[$zone->uuid] = ['name' => $zone->name, 'stations' => 0];
        }

        $posted = [];
        $unposted = [];
        $unzoned = 0;
        $refs = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $uuid = (string) $station->getUuidString();
            $name = (string) $station->getName();
            $n = $counts[$uuid] ?? 0;
            $posted[$name] = $n;
            if (0 === $n) {
                $unposted[] = $name;
            }

            $zone = $station->getZone()?->getUuidString();
            if (null !== $zone && isset($zones[(string) $zone])) {
                ++$zones[(string) $zone]['stations'];
            } else {
                ++$unzoned;
            }

            $refs[] = new StationRef($uuid, (string) $area->getUuidString(), $name);
        }
        sort($unposted);

        $people = $this->board->board($this->postings->findStandingByArea($area), new PostingQuery())['rows'];
        $departments = array_values(array_unique(array_filter(array_map(static fn ($row): ?string => $row->department, $people))));
        sort($departments);

        $period = $this->periods->month();

        return new StationOverview(
            stations: \count($posted),
            staffed: \count($posted) - \count($unposted),
            zones: \count($zones),
            zonesWithAStation: \count(array_filter($zones, static fn (array $z): bool => $z['stations'] > 0)),
            unzoned: $unzoned,
            departments: $departments,
            month: $period->from->format('F'),
            year: $period->from->format('Y'),
            people: \count($people),
            leaders: \count(array_intersect_key($led, $counts)),
            unposted: $unposted,
            modules: $this->moduleCards($area, $refs),
            byStation: self::byStation($posted),
            stationsShown: min(\count($posted), self::STATIONS_SHOWN),
            byZone: self::byZone($zones, $unzoned),
        );
    }

    /**
     * Most posted first, then by name; a station nobody is posted to is quiet.
     *
     * @param array<string, int> $posted
     */
    private static function byStation(array $posted): RankedBars
    {
        uksort($posted, static fn (string $a, string $b): int => $posted[$b] <=> $posted[$a] ?: strnatcasecmp($a, $b));

        $bars = [];
        foreach (\array_slice($posted, 0, self::STATIONS_SHOWN, true) as $name => $n) {
            $bars[] = new Bar((string) $name, (float) $n, figure: (string) $n);
        }

        return new RankedBars($bars, empty: 'No station in this area yet.');
    }

    /**
     * Most stations first, then by name; the ground no zone covers last.
     *
     * @param array<string, array{name: string, stations: int}> $zones
     */
    private static function byZone(array $zones, int $unzoned): RankedBars
    {
        $rows = array_values($zones);
        usort($rows, static fn (array $a, array $b): int => $b['stations'] <=> $a['stations'] ?: strnatcasecmp($a['name'], $b['name']));

        $bars = array_map(static fn (array $z): Bar => new Bar($z['name'], (float) $z['stations'], figure: (string) $z['stations']), $rows);
        if ($unzoned > 0) {
            $bars[] = new Bar('No zone', (float) $unzoned, figure: (string) $unzoned, quiet: true);
        }

        return new RankedBars($bars, empty: 'No zone and no station yet.');
    }

    /**
     * @param list<StationRef> $refs
     *
     * @return list<array{label: string, value: float, module: string, caption: string, route: string|null}>
     */
    private function moduleCards(AreaOfInterest $area, array $refs): array
    {
        if ([] === $refs) {
            return [];
        }

        $figures = $this->figures->collect(
            $refs,
            $this->periods->month(),
            fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
        );

        /** @var array<string, array{label: string, value: float, module: string, caption: string, route: string|null}> $cards */
        $cards = [];
        foreach ($refs as $ref) {
            foreach ($figures->dockFor($ref->stationUuid) as $figure) {
                if (null === $figure->value) {
                    continue;
                }

                $cards[$figure->moduleSlug] ??= [
                    'label' => $figure->label,
                    'value' => 0.0,
                    'module' => $figure->moduleName,
                    'caption' => $figures->period->label,
                    'route' => $this->entryRoutes->entryRouteFor($figure->moduleSlug),
                ];
                $cards[$figure->moduleSlug]['value'] += $figure->value;
            }
        }

        return \array_slice(array_values($cards), 0, self::MODULE_CARDS);
    }
}
