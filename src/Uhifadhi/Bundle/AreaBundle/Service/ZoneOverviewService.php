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
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFigureCard;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFigureSet;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneOverview;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneRow;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneSetView;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;
use Uhifadhi\Bundle\AtlasBundle\Model\Bar;
use Uhifadhi\Bundle\AtlasBundle\Model\RankedBars;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\ZoneRef;

/**
 * THE ZONES OVERVIEW, COUNTED — the set of an area's zones as the hub's
 * Overview states it ({@see ZoneOverview}).
 *
 * WHAT STANDS ON THE GROUND IS THE AREA'S OWN FACT; what was done on it is a
 * module's. The extents, the stations and the import are read here; every
 * figure comes through the zone seam, one card a figure a module published,
 * and a module that publishes nothing leaves its card saying so.
 */
final readonly class ZoneOverviewService
{
    /** How many of the four figures the modules may fill. */
    public const int MODULE_CARDS = 3;

    public function __construct(
        private StationRepository $stations,
        private AreaRegister $areas,
        private ZoneFigureService $figures,
        private AreaModuleService $areaModules,
        private ModuleEntryRouteResolver $entryRoutes,
        private Periods $periods,
    ) {
    }

    public function overview(AreaOfInterest $area, ZoneSetView $view): ZoneOverview
    {
        $perZone = $this->stations->countPerZone($area);
        $stations = $this->stations->countByArea($area);
        $areaKm2 = $this->groundKm2($area, $view);

        $unstationed = [];
        foreach ($view->rows as $zone) {
            if (0 === ($perZone[$zone->uuid] ?? 0)) {
                $unstationed[] = $zone->name;
            }
        }
        sort($unstationed, \SORT_NATURAL | \SORT_FLAG_CASE);

        $figures = $this->figures->collect(
            array_map(
                static fn (ZoneRow $row): ZoneRef => new ZoneRef($row->uuid, (string) $area->getUuidString(), $row->name),
                $view->rows,
            ),
            $this->periods->month(),
            fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
        );

        return new ZoneOverview(
            zones: $view->count(),
            zonedKm2: $view->zonedKm2,
            areaKm2: $areaKm2,
            unzonedKm2: $this->unzonedKm2($area, $view),
            stations: $stations,
            stationsUnzoned: max(0, $stations - array_sum($perZone)),
            importedAt: $view->lastImport?->getImportedAt(),
            importedFeatures: $view->lastImport?->getZoneCount(),
            zonesWithAStation: $view->count() - \count($unstationed),
            unstationed: $unstationed,
            modules: $this->moduleCards($view->rows, $figures),
            byExtent: self::byExtent($view->rows, $areaKm2, $this->unzonedKm2($area, $view)),
            byCovered: self::byCovered($view->rows, $figures),
            month: $figures->period->from->format('F'),
        );
    }

    /** The ground in no zone — legal: a stretch nobody works may belong to none. */
    public function unzonedKm2(AreaOfInterest $area, ZoneSetView $view): int
    {
        return max(0, $this->groundKm2($area, $view) - $view->zonedKm2);
    }

    private function groundKm2(AreaOfInterest $area, ZoneSetView $view): int
    {
        return $view->groundKm2 ?? $this->areas->areaKm2($area);
    }

    /**
     * ONE CARD A FIGURE A MODULE PUBLISHED, in the order they were published
     * and capped at the three the strip has room for: the covered share and a
     * count from one module are two cards, not one.
     *
     * @param list<ZoneRow> $rows
     *
     * @return list<ZoneFigureCard>
     */
    private function moduleCards(array $rows, ZoneFigureSet $figures): array
    {
        /** @var array<string, list<DepartmentKpi>> $byFigure */
        $byFigure = [];
        foreach ($rows as $row) {
            foreach ($figures->forZone($row->uuid) as $figure) {
                $byFigure[$figure->moduleSlug.'|'.$figure->key][] = $figure;
            }
        }

        $cards = [];
        foreach ($byFigure as $readings) {
            $card = ZoneFigureCard::of($readings, $figures->period->label, $this->entryRoutes->entryRouteFor($readings[0]->moduleSlug));
            if (null !== $card) {
                $cards[] = $card;
            }
        }

        return \array_slice($cards, 0, self::MODULE_CARDS);
    }

    /**
     * Widest first, its share of the area after it; the ground in no zone
     * last, quiet.
     *
     * @param list<ZoneRow> $rows
     */
    private static function byExtent(array $rows, int $areaKm2, int $unzonedKm2): RankedBars
    {
        usort($rows, static fn (ZoneRow $a, ZoneRow $b): int => $b->km2 <=> $a->km2 ?: strnatcasecmp($a->name, $b->name));

        $share = static fn (int $km2): string => $areaKm2 > 0 ? ' km² · '.round(100 * $km2 / $areaKm2).' %' : ' km²';
        $bars = array_map(
            static fn (ZoneRow $row): Bar => new Bar($row->name, (float) $row->km2, figure: number_format($row->km2), note: $share($row->km2)),
            $rows,
        );
        if ([] !== $rows && $unzonedKm2 > 0) {
            $bars[] = new Bar('No zone', (float) $unzonedKm2, figure: number_format($unzonedKm2), note: $share($unzonedKm2), quiet: true);
        }

        return new RankedBars($bars, empty: 'No zone in this area yet.');
    }

    /**
     * Most covered first, read against the whole; a zone no module counted is
     * left out rather than drawn as nought.
     *
     * @param list<ZoneRow> $rows
     */
    private static function byCovered(array $rows, ZoneFigureSet $figures): RankedBars
    {
        $bars = [];
        foreach ($rows as $row) {
            $covered = $figures->covered($row->uuid);
            if (null !== $covered && null !== $covered->value) {
                $bars[] = new Bar($row->name, $covered->value, figure: $covered->display(), note: ' %', of: 100.0);
            }
        }
        usort($bars, static fn (Bar $a, Bar $b): int => $b->value <=> $a->value ?: strnatcasecmp($a->label, $b->label));

        return new RankedBars($bars, empty: 'No module publishes the covered share of these zones yet.');
    }
}
