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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationEventRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingBoardService;
use Uhifadhi\Bundle\AreaBundle\Service\StationFigureService;
use Uhifadhi\Bundle\AreaBundle\Service\StationSectionService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AtlasBundle\Calendar\Periods;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * ONE POST, READ.
 *
 * A RECORD PAGE, NOT AN AREA TAB. It carries the crumb and the header and no
 * tab strip — the same shape the incident detail page wears — because a
 * station is a thing inside the area rather than one of the ways of looking
 * at the area.
 *
 * IT ONLY READS. The record is edited in the area's configure page, in the
 * Stations section beside Zones; every control here that changes something
 * points there, and this controller has no write route at all.
 *
 * THE BOARD IS FILTERED SERVER-SIDE, off the query string. A filtered board
 * is then a link somebody can send and a page a browser can go back to, and
 * it works with no script — which a board filtered in the browser is not, and
 * which also stops the counts lying the moment the list is longer than a page.
 */
final readonly class StationRecordController
{
    public const string ROUTE = 'area_station_show';

    /** The pair the record enforces, and the one every door to it asks. */
    public const string READ = 'stations.read';

    public function __construct(
        private Environment $twig,
        private StationRepository $stations,
        private PostingRepository $postings,
        private StationEventRepository $events,
        private PostingBoardService $board,
        private ZoneSetService $set,
        private AreaPlateService $plates,
        private StationFigureService $figures,
        private StationSectionService $sections,
        private AreaModuleService $areaModules,
        /**
         * WHAT PERIOD IT IS NOW, from the one source that decides it. This
         * was `FigurePeriod::month(new \DateTimeImmutable())` written here
         * and in ten other places, each asking the wall clock: correct on
         * the 14th, wrong on the 1st, and unpinnable by a test.
         */
        private Periods $periods,
    ) {
    }

    #[Route(
        '/areas/{uuid}/stations/{station}',
        name: self::ROUTE,
        requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted(self::READ, subject: 'area')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $query = self::queryFrom($request);
        $board = $this->board->at($station, $query);
        $view = $this->set->view($area);

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => $post->getId() === $station->getId(),
            ];
        }

        /*
         * THE DOCK IS THE MODULES', and the area's ledger decides who is
         * asked. Until a module publishes, the card says so in the product's
         * own words rather than drawing four empty rows.
         */
        $dock = $this->figures->collect(
            [new StationRef((string) $station->getUuidString(), (string) $area->getUuidString(), (string) $station->getName())],
            $this->periods->month(),
            fn (string $slug): bool => $this->runs($area, $slug),
        );

        /*
         * AND WHAT THE MODULES PUT ON THE POST ITSELF. The same ledger
         * decides, for the same reason: a module parked in this area says
         * nothing here, and its band leaves the page rather than emptying.
         */
        $bands = $this->sections->forOne(
            new StationRef((string) $station->getUuidString(), (string) $area->getUuidString(), (string) $station->getName()),
            StationSurface::Record,
            fn (string $slug): bool => $this->runs($area, $slug),
        );

        return new Response($this->twig->render('@Area/station/record.html.twig', [
            'area' => $area,
            'station' => $station,
            'zone' => $station->getZone(),
            'rows' => $board['rows'],
            'facets' => $board['facets'],
            'posted' => $board['total'],
            'leader' => $board['leader'],
            'query' => $query,
            'events' => $this->events->findByStation($station),
            'eventCount' => $this->events->countByStation($station),
            'bands' => $bands,
            'dock' => $dock->dockFor((string) $station->getUuidString()),
            'dockPeriod' => $dock->period,
            'coordinates' => self::coordinatesOf($station),
            /*
             * AND THIS ONE IS ABOUT THE POST — the ground around it at the
             * distance the design draws, not the whole park with a dot in
             * it. A point has no extent, so the zoom is the statement.
             */
            'map' => $this->plates->focusOn(
                $this->plates->aroundStation($area, $view->rows, $posts),
                $station->getPoint(),
                AreaPlateService::POST_ZOOM,
            ),
        ]));
    }

    /**
     * THE POINT, AS PEOPLE WRITE IT DOWN: latitude first, five decimals.
     *
     * THE STORED ORDER IS THE OTHER WAY. GeoJSON and PostGIS both put
     * longitude first and this product never reverses that anywhere it
     * matters; what a person reads off a handheld and copies into a radio
     * call is lat-then-lon, so the one place the order flips is here, on its
     * way to being read aloud.
     */
    private static function coordinatesOf(Station $station): ?string
    {
        $point = json_decode((string) $station->getPoint(), true);
        $pair = \is_array($point) ? ($point['coordinates'] ?? null) : null;

        if (!\is_array($pair) || !is_numeric($pair[0] ?? null) || !is_numeric($pair[1] ?? null)) {
            return null;
        }

        return \sprintf('%.5f, %.5f', (float) $pair[1], (float) $pair[0]);
    }

    /**
     * A STATION IS ADDRESSED INSIDE ITS AREA. A uuid from one area on
     * another's address is not a post that happens to be elsewhere; it is a
     * request for something this page is not about.
     */
    private function denyUnlessTheStationIsThisAreas(AreaOfInterest $area, Station $station): void
    {
        if ($station->getArea()?->getId() !== $area->getId()) {
            throw new AccessDeniedException('That station does not belong to this area.');
        }
    }

    /**
     * AN UNKNOWN SORT IS THE DEFAULT, not an error: a stale link should show
     * the board, and the only thing it can be stale about is how it was
     * ordered.
     */
    private static function queryFrom(Request $request): PostingQuery
    {
        $sort = $request->query->getString(PostingQuery::SORT, PostingQuery::BY_NAME);

        return new PostingQuery(
            role: self::orNull($request, PostingQuery::ROLE),
            department: self::orNull($request, PostingQuery::DEPARTMENT),
            source: self::orNull($request, PostingQuery::SOURCE),
            search: trim($request->query->getString(PostingQuery::SEARCH)),
            sort: \in_array($sort, PostingQuery::SORTS, true) ? $sort : PostingQuery::BY_NAME,
        );
    }

    private static function orNull(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' === $value ? null : $value;
    }

    /**
     * WHETHER THE AREA RUNS THAT MODULE — the registry's ledger, which is the
     * same answer the module grid and the sidebar read. A module parked here
     * contributes no dock row, without this page ever naming one.
     */
    private function runs(AreaOfInterest $area, string $slug): bool
    {
        return $this->areaModules->isActive($area, $slug);
    }
}
