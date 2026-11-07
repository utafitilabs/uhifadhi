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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Enum\ZoneEventKind;
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Model\PostingRow;
use Uhifadhi\Bundle\AreaBundle\Model\StationRow;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFigureSet;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneEventRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\EventLogService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingBoardService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneFigureService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleEntryRouteResolver;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneRef;

/**
 * ONE ZONE, READ — a record page (design: areas/<area>/zones/detail.html).
 *
 * A RECORD, NOT A TAB: its bare name as the h1, its context in the subline,
 * the crumb one step longer, its facts strip as its own overview, no tab
 * strip. The index lists its cards, one row a card, and the cards stand in
 * one column: the ground, its stations, who works out of it, what happened
 * here. Every table is the house table under its bar and over its foot, each
 * with its own keys, because three tables share one address.
 *
 * IT ONLY READS. The ring, the name and the removal are the configure
 * section's, and nothing here writes.
 */
final readonly class ZoneRecordController
{
    public const string ROUTE = 'area_zone_show';

    /** The stations table searches and pages on the house keys. */
    public const string STATION_QUERY = 'q';

    /** Who works out of it: its own search, filters and pages. */
    public const string PERSON_QUERY = 'person';
    public const string PERSON_STATION = 'station';
    public const string PERSON_POSITION = 'position';
    public const string PEOPLE_PAGE = 'ppage';
    public const string PEOPLE_PER = 'pper';

    /** What happened here: its own pages. */
    public const string LOG_PAGE = 'lpage';
    public const string LOG_PER = 'lper';

    public function __construct(
        private Environment $twig,
        private ZoneSetService $set,
        private StationRepository $stations,
        private PostingRepository $postings,
        private PostingBoardService $board,
        private ZoneEventRepository $events,
        private AreaPlateService $plates,
        private ZoneFigureService $figures,
        private AreaModuleService $areaModules,
        private ModuleEntryRouteResolver $entryRoutes,
        private Periods $periods,
        private TablePageService $pager,
        private EventLogService $log,
    ) {
    }

    #[Route(
        '/areas/{uuid}/zones/{zone}',
        name: self::ROUTE,
        requirements: ['uuid' => Requirement::UUID, 'zone' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('zones.read', subject: 'area')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['zone' => 'uuid'])] Zone $zone,
    ): Response {
        $this->denyUnlessTheZoneIsThisAreas($area, $zone);

        $view = $this->set->view($area);
        $row = null;
        foreach ($view->rows as $candidate) {
            if ($candidate->uuid === (string) $zone->getUuidString()) {
                $row = $candidate;
            }
        }

        $people = $this->board->board($this->postings->findStandingByZone($zone), new PostingQuery())['rows'];
        $leads = [];
        foreach ($people as $person) {
            if ($person->leader) {
                $leads[$person->stationUuid] = $person->name;
            }
        }

        $search = trim($request->query->getString(self::STATION_QUERY));
        $stations = [];
        $names = [];
        foreach ($this->stations->findByZone($zone) as $station) {
            $posted = $this->postings->countStandingByStation($station);
            $stations[] = StationRow::of($station, $posted, null !== $this->postings->findLeaderAt($station), $row?->cat);
            $names[(string) $station->getUuidString()] = (string) $station->getName();
        }
        $matching = array_values(array_filter($stations, static fn (StationRow $station): bool => $station->matches($search)));
        $stationTable = $this->pager->read($request, \count($matching));

        $ask = [
            self::PERSON_STATION => self::answer($request, self::PERSON_STATION),
            self::PERSON_POSITION => self::answer($request, self::PERSON_POSITION),
            self::PERSON_QUERY => trim($request->query->getString(self::PERSON_QUERY)),
        ];
        $found = self::peopleAnswering($people, $ask, $names);
        $peopleTable = $this->pager->read($request, \count($found), self::PEOPLE_PAGE, self::PEOPLE_PER);

        $lines = $this->events->findMentioning($area, (string) $zone->getName(), \PHP_INT_MAX);
        $narrowed = $this->log->narrow($lines, ZoneEventKind::cases(), self::answer($request, EventLogService::KIND), $request->query->getString(EventLogService::SEARCH));
        $logTable = $this->pager->read($request, \count($narrowed['rows']), self::LOG_PAGE, self::LOG_PER);

        $figures = $this->figures->collect(
            [new ZoneRef((string) $zone->getUuidString(), (string) $area->getUuidString(), null === $row ? (string) $zone->getName() : $row->name)],
            $this->periods->month(),
            fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
        );

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
            ];
        }

        return new Response($this->twig->render('@Area/zone/record.html.twig', [
            'area' => $area,
            'zone' => $zone,
            'row' => $row,
            'search' => $search,
            'stations' => $stationTable->slice($matching),
            'stationCount' => \count($stations),
            'stationTable' => $stationTable,
            'stationLinks' => $this->pager->links($request, $stationTable),
            'leads' => $leads,
            'people' => $peopleTable->slice($found),
            'peopleTotal' => \count($people),
            'peopleTable' => $peopleTable,
            'peopleLinks' => $this->pager->links($request, $peopleTable, self::PEOPLE_PAGE, self::PEOPLE_PER),
            'peopleStations' => \count(array_unique(array_map(static fn (PostingRow $person): string => $person->stationUuid, $found))),
            'ask' => $ask,
            'stationOptions' => self::stationOptions($people, $names),
            'positionOptions' => self::positionOptions($people),
            'stationNames' => $names,
            'log' => $logTable->slice($narrowed['rows']),
            'logScope' => \count($lines),
            'logKinds' => $narrowed['kinds'],
            'logKind' => $narrowed['kind'],
            'logSearch' => $narrowed['search'],
            'logTable' => $logTable,
            'logLinks' => $this->pager->links($request, $logTable, self::LOG_PAGE, self::LOG_PER),
            'covered' => $figures->covered((string) $zone->getUuidString()),
            'figures' => $this->moduleFacts($figures, (string) $zone->getUuidString()),
            'period' => $figures->period,
            'month' => $figures->period->from->format('F'),
            'moduleDoors' => $this->moduleDoors($figures, (string) $zone->getUuidString()),
            /*
             * THE PLATE IS ABOUT THIS ZONE. It draws the whole area, the
             * other zones and every station, because a zone is read against
             * its neighbours, fitted to the zone.
             */
            'map' => $this->plates->focusOn(
                $this->plates->picker($area, $view->rows, $posts),
                $zone->getGeom(),
            ),
        ]));
    }

    /**
     * THE PEOPLE A QUESTION LEAVES, ordered by station (the station's name,
     * then the lead first, then by name): the order and the station chip
     * carry the grouping, never a band row.
     *
     * @param list<PostingRow>                                                   $people
     * @param array{station: string|null, position: string|null, person: string} $ask
     * @param array<string, string>                                              $names
     *
     * @return list<PostingRow>
     */
    private static function peopleAnswering(array $people, array $ask, array $names): array
    {
        $needle = mb_strtolower($ask[self::PERSON_QUERY]);
        $found = array_values(array_filter($people, static fn (PostingRow $person): bool => (null === $ask[self::PERSON_STATION] || $person->stationUuid === $ask[self::PERSON_STATION])
            && (null === $ask[self::PERSON_POSITION] || $person->role === $ask[self::PERSON_POSITION])
            && ('' === $needle || str_contains(mb_strtolower($person->name.' '.($names[$person->stationUuid] ?? '')), $needle))));

        usort($found, static fn (PostingRow $a, PostingRow $b): int => strnatcasecmp($names[$a->stationUuid] ?? '', $names[$b->stationUuid] ?? '')
            ?: $b->leader <=> $a->leader
            ?: strnatcasecmp($a->name, $b->name));

        return $found;
    }

    /**
     * @param list<PostingRow>      $people
     * @param array<string, string> $names
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private static function stationOptions(array $people, array $names): array
    {
        $counts = [];
        foreach ($people as $person) {
            $counts[$person->stationUuid] = ($counts[$person->stationUuid] ?? 0) + 1;
        }

        $options = [];
        foreach ($names as $uuid => $name) {
            $options[] = ['value' => $uuid, 'label' => $name, 'count' => $counts[$uuid] ?? 0];
        }

        return $options;
    }

    /**
     * @param list<PostingRow> $people
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private static function positionOptions(array $people): array
    {
        $counts = [];
        foreach ($people as $person) {
            if (null !== $person->role) {
                $counts[$person->role] = ($counts[$person->role] ?? 0) + 1;
            }
        }
        ksort($counts, \SORT_NATURAL | \SORT_FLAG_CASE);

        $options = [];
        foreach ($counts as $position => $n) {
            $options[] = ['value' => (string) $position, 'label' => (string) $position, 'count' => $n];
        }

        return $options;
    }

    private static function answer(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' === $value ? null : $value;
    }

    /**
     * ONE FACT A MODULE — the band is a LINE, not a list.
     *
     * THE DEFECT THIS FIXES: every figure every module published about
     * the ground went into the band, so an area running two modules
     * drew twelve facts wrapping onto four rows, each captioned with a
     * sentence ("every track that entered the zone"), and the header
     * repeated "See incidents" once per figure. A band a reader has to
     * scan four rows of is not an identity band.
     *
     * THE HEADLINE IS THE FIRST FIGURE THE MODULE PUBLISHED, and that
     * is a decision worth stating. The alternative was a second
     * well-known key beside {@see ZoneFigureProviderInterface::COVERED}
     * — but a key every module must adopt fails SILENTLY for the ones
     * that have not, and the symptom is an empty band. Order needs no
     * contract change, no module edit, and it is the rule the
     * performance matrix already reads a topic's headline by: the
     * publisher decided which of its figures leads.
     *
     * THE REST ARE NOT LOST. They are the zone figure cards on the
     * all-zones page, which is where the design puts them.
     *
     * @return list<array{figure: DepartmentKpi, route: string|null}>
     */
    private function moduleFacts(ZoneFigureSet $figures, string $zoneUuid): array
    {
        $facts = [];
        foreach ($figures->forZone($zoneUuid) as $figure) {
            if (ZoneFigureProviderInterface::COVERED === $figure->key) {
                continue;
            }

            // FIRST WINS, and a module is in the band once.
            if (\array_key_exists($figure->moduleSlug, $facts)) {
                continue;
            }

            $facts[$figure->moduleSlug] = [
                'figure' => $figure,
                'route' => $this->entryRoutes->entryRouteFor($figure->moduleSlug),
            ];
        }

        return array_values($facts);
    }

    /**
     * ONE WAY IN A MODULE — by distinct module, not by figure.
     *
     * @return list<array{name: string, route: string}>
     */
    private function moduleDoors(ZoneFigureSet $figures, string $zoneUuid): array
    {
        $doors = [];
        foreach ($figures->forZone($zoneUuid) as $figure) {
            $route = $this->entryRoutes->entryRouteFor($figure->moduleSlug);
            if (null === $route || \array_key_exists($figure->moduleSlug, $doors)) {
                continue;
            }

            $doors[$figure->moduleSlug] = ['name' => $figure->moduleName, 'route' => $route];
        }

        return array_values($doors);
    }

    /**
     * A ZONE IS ADDRESSED INSIDE ITS AREA. A uuid from one area on another's
     * address is not a zone that happens to be elsewhere; it is a request for
     * something this page is not about.
     */
    private function denyUnlessTheZoneIsThisAreas(AreaOfInterest $area, Zone $zone): void
    {
        if ($zone->getArea()?->getId() !== $area->getId()) {
            throw new AccessDeniedException('That zone does not belong to this area.');
        }
    }
}
