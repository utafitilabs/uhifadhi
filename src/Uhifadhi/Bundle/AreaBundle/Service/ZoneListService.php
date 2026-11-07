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
use Uhifadhi\Bundle\AreaBundle\Model\FilterOption;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneListQuery;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneListRow;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneRegister;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneRef;

/**
 * THE ZONES OF AN AREA, AS A REGISTER — the table the zones tab lists them
 * in, filtered, ordered and paged on the server.
 *
 * THE STATIONS TABLE'S TWIN. A zone is read the way a station is: a card,
 * the house dropdowns, a search, the house table and the house foot.
 *
 * WHAT STANDS ON THE GROUND IS THE AREA'S OWN FACT; what has happened on it
 * is a module's. The counts of posts and people are counted here from the
 * area's own tables; every figure comes through
 * {@see ZoneFigureProviderInterface}, and where no installed module answers,
 * the row says there is no figure rather than drawing a nought.
 */
final readonly class ZoneListService
{
    public function __construct(
        private ZoneSetService $set,
        private StationRepository $stations,
        private PostingRepository $postings,
        private ZoneFigureService $figures,
        private AreaModuleService $areaModules,
        private ModuleCatalogue $catalogue,
        /**
         * WHAT PERIOD IT IS NOW, from the one source that decides it. This
         * was `FigurePeriod::month(new \DateTimeImmutable())` written here
         * and in ten other places, each asking the wall clock: correct on
         * the 14th, wrong on the 1st, and unpinnable by a test.
         */
        private Periods $periods,
    ) {
    }

    public function register(AreaOfInterest $area, ZoneListQuery $query, ?int $perPage = null): ZoneRegister
    {
        $perPage ??= ZoneRegister::PER_PAGE;
        $zones = $this->set->view($area)->rows;

        $stations = $this->stations->countPerZone($area);
        $people = $this->postings->countStandingPerZone($area);
        $columns = $this->columnsFor($area);

        $figures = $this->figures->collect(
            array_map(
                static fn ($row): ZoneRef => new ZoneRef($row->uuid, (string) $area->getUuidString(), $row->name),
                $zones,
            ),
            $this->periods->month(),
            fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
        );

        $rows = [];
        foreach ($zones as $zone) {
            $published = [];
            foreach ($figures->forZone($zone->uuid) as $figure) {
                // ONE FIGURE PER MODULE, AND NOT THE COVERED SHARE: that one
                // has a column of its own, and a module publishing both would
                // otherwise fill its column with the number beside it.
                if (ZoneFigureProviderInterface::COVERED !== $figure->key) {
                    $published[$figure->moduleSlug] ??= $figure;
                }
            }

            $byModule = [];
            foreach ($columns as $slug => $heading) {
                $byModule[$slug] = $published[$slug] ?? null;
            }

            $rows[] = new ZoneListRow(
                uuid: $zone->uuid,
                name: $zone->name,
                cat: $zone->cat,
                km2: $zone->km2,
                covered: $figures->covered($zone->uuid),
                stations: $stations[$zone->uuid] ?? 0,
                people: $people[$zone->uuid] ?? 0,
                figures: $byModule,
            );
        }

        $scope = \count($rows);
        $searched = self::searched($rows, $query->search);
        $matching = self::withStations($searched, $query->stations);
        $ordered = self::ordered($matching, $query->sort);

        $total = \count($ordered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($query->page, $pages);

        return new ZoneRegister(
            rows: \array_slice($ordered, ($page - 1) * $perPage, $perPage),
            total: $total,
            scope: $scope,
            // THE COUNTS ARE OF WHAT THE SEARCH LEFT, not of everything: the
            // two answers are what picking one would do to the list in front
            // of the reader.
            stations: [
                new FilterOption(ZoneListQuery::WITH, 'With a station', \count(self::withStations($searched, ZoneListQuery::WITH))),
                new FilterOption(ZoneListQuery::WITHOUT, 'Without a station', \count(self::withStations($searched, ZoneListQuery::WITHOUT))),
            ],
            columns: $columns,
            page: $page,
            pages: $pages,
            stationTotal: array_sum($stations),
            peopleTotal: array_sum($people),
            // THE CARD'S QUALIFIER SAYS WHAT THE MODULE COLUMNS COUNT OVER.
            period: $figures->period->from->format('F'),
            perPage: $perPage,
        );
    }

    /**
     * ONE COLUMN PER MODULE THE AREA RUNS, IN THE AREA'S OWN MODULE ORDER —
     * the order its Modules tab lists them in, which is the order somebody
     * arranged. The catalogue's order is the order the installation happens
     * to hold them in, and reading it here put Incidents left of Patrols on
     * a page whose every other module list reads the other way.
     *
     * A MODULE THAT PUBLISHES NOTHING KEEPS ITS COLUMN and every cell in it
     * says so: the column is named after a module that IS installed here, so
     * its silence is a fact about the module rather than a missing column
     * nobody can ask about. An area running no modules has no module
     * columns, because there is nothing to be silent.
     *
     * @return array<string, string> slug to the column's heading
     */
    private function columnsFor(AreaOfInterest $area): array
    {
        $named = [];
        foreach ($this->catalogue->all() as $module) {
            $named[(string) $module->getSlug()] = (string) $module->getName();
        }

        $columns = [];
        foreach ($this->areaModules->activeFor($area) as $assignment) {
            $slug = $assignment->getModule()?->getSlug();
            // A MODULE THE MACHINE NO LONGER HAS is a row in the ledger and
            // not a capability: the catalogue is the intersection, so a slug
            // it does not name has no column.
            if (\is_string($slug) && isset($named[$slug])) {
                $columns[$slug] = $named[$slug];
            }
        }

        return $columns;
    }

    /**
     * @param list<ZoneListRow> $rows
     *
     * @return list<ZoneListRow>
     */
    private static function searched(array $rows, string $search): array
    {
        if ('' === $search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return array_values(array_filter(
            $rows,
            static fn (ZoneListRow $row): bool => str_contains(mb_strtolower($row->name), $needle),
        ));
    }

    /**
     * @param list<ZoneListRow> $rows
     *
     * @return list<ZoneListRow>
     */
    private static function withStations(array $rows, ?string $answer): array
    {
        if (null === $answer) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (ZoneListRow $row): bool => ZoneListQuery::WITH === $answer ? $row->stations > 0 : 0 === $row->stations,
        ));
    }

    /**
     * @param list<ZoneListRow> $rows
     *
     * @return list<ZoneListRow>
     */
    private static function ordered(array $rows, string $order): array
    {
        // A FIGURE NOBODY PUBLISHED SORTS LAST, whichever way round: it is
        // not a nought, and ordering by a column the modules are silent in
        // must not promote the silence to the top of the table.
        $covered = static fn (ZoneListRow $row): float => null === $row->covered ? -1.0 : ($row->covered->value ?? -1.0);

        usort($rows, static fn (ZoneListRow $a, ZoneListRow $b): int => match ($order) {
            ZoneListQuery::BY_EXTENT => $b->km2 <=> $a->km2 ?: strcasecmp($a->name, $b->name),
            ZoneListQuery::BY_COVERED => $covered($b) <=> $covered($a) ?: strcasecmp($a->name, $b->name),
            ZoneListQuery::BY_STATIONS => $b->stations <=> $a->stations ?: strcasecmp($a->name, $b->name),
            default => strcasecmp($a->name, $b->name),
        });

        return $rows;
    }
}
