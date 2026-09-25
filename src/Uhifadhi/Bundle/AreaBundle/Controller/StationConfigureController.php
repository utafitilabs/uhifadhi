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
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Model\StationQuery;
use Uhifadhi\Bundle\AreaBundle\Model\StationRegister;
use Uhifadhi\Bundle\AreaBundle\Model\StationRow;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationEventRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\PersonDirectoryService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingBoardService;
use Uhifadhi\Bundle\AreaBundle\Service\StationNoticeStore;
use Uhifadhi\Bundle\AreaBundle\Service\StationRegisterService;
use Uhifadhi\Bundle\AreaBundle\Service\StationSectionService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;

/**
 * THE STATIONS SECTION OF AN AREA'S CONFIGURE PAGE — where a post is added,
 * moved, renamed and staffed, and the only place any of that happens.
 *
 * IT ONLY CONFIGURES. Reading a post is the station page's job and reading
 * the ground is the Zones tab's; nothing here draws duty, patrols, incidents
 * or any figure a module publishes, and there is no row of KPI cards —
 * a configuration section has none worth carrying, and the group headings
 * state the counts.
 *
 * A SCREEN, NOT A RENDERED SECTION, for the same reason Zones is one: it
 * takes writes and answers them with redirects, so it has an address of its
 * own. The frame is unchanged — the shell puts the section strip where a data
 * page's tabs go and lights the Configure action.
 *
 * ONE CARD, ONE FLAT LIST, AND THE ZONE IS A FILTER. Grouping the register by
 * zone would make the zone the only way in; a name or a code is how an
 * operator actually arrives.
 *
 * READING IS GATED ON `stations.read` AND SETTING A STATION UP ON
 * `stations.configure`, while putting somebody at one costs
 * `assignments.manage` — the split every area surface makes, and one more,
 * because who is stationed where is a different trust from what the stations
 * are.
 */
final readonly class StationConfigureController
{
    public const string ROUTE = 'area_stations_configure';

    /** The pair the section enforces, and the one its strip entry asks. */
    public const string READ = 'stations.read';

    /** Which row is open is a place, so it is a query a link can carry. */
    public const string OPEN_QUERY = 'open';

    public function __construct(
        private Environment $twig,
        private StationRegisterService $register,
        private StationRepository $stations,
        private PostingRepository $postings,
        private StationEventRepository $events,
        private PostingBoardService $board,
        private PersonDirectoryService $directory,
        private StationService $stationService,
        private ZoneSetService $set,
        private AreaPlateService $plates,
        private StationNoticeStore $notices,
        private StationSectionService $sections,
        private AreaModuleService $areaModules,
        private CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/areas/{uuid}/configure/stations', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'], priority: 1)]
    #[IsGranted(self::READ, subject: 'area')]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $query = self::queryFrom($request);
        $view = $this->set->view($area);

        /*
         * THE OPEN ROW IS RESOLVED BEFORE THE REGISTER IS, because it decides
         * which PAGE of the register answers. A deep link names a station —
         * from its record's "Edit the station", from the empty state's "Post
         * somebody", from the redirect after a form posts — and the register
         * paginates: every station from the ninth on used to land on page one
         * with nothing open, which reads as a dead link.
         */
        $open = self::openRowOf($request, $area, $this->stations);

        /*
         * A LINK THAT NAMES A ROW MUST SHOW IT (ruled 2026-09-21).
         *
         * The register rests on the ACTIVE stations, so a link naming a
         * CLOSED one — the header's "Edit the station" on a closed post's
         * record — resolved the row, found it on no page, and drew the
         * register without it. The card was simply absent, which reads as a
         * dead door rather than as a filter doing its job.
         *
         * SO THE DEFAULT WIDENS, AND ONLY THE DEFAULT. A filter the reader
         * chose is part of the address and is never overruled: somebody who
         * asked for `?active=yes` and then followed a link to a closed post
         * gets the answer they asked for. What widens is the resting state
         * nobody typed.
         *
         * AND THE CHIP SAYS SO. Widening to "all" puts `active=all` in the
         * filter row, so the register never shows a set the controls
         * disagree with — a page quietly showing rows its own filter
         * excludes is worse than the missing card.
         */
        if (null !== $open && !$open->isActive() && !$request->query->has(StationQuery::ACTIVE)) {
            $query = self::showingEveryActivity($query);
        }

        $register = $this->register->register(
            $area,
            $query,
            holding: null === $open ? null : (string) $open->getUuidString(),
        );
        $board = null === $open ? [] : $this->board->at($open, new PostingQuery())['rows'];

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

        /*
         * WHAT THE MODULES ADD TO THE OPEN CARD. Only one card's body is
         * rendered at a time on this page, so only that post is asked
         * about — a contributor is never made to answer for eleven cards
         * nobody is looking at. The answer is keyed by post all the same,
         * because the page reads it per row and a list would tie the two
         * to each other's order.
         *
         * A module parked in this area is not asked at all.
         */
        $blocks = null === $open ? [] : [
            (string) $open->getUuidString() => $this->sections->forOne(
                new StationRef((string) $open->getUuidString(), (string) $area->getUuidString(), (string) $open->getName()),
                StationSurface::Configure,
                fn (string $slug): bool => $this->areaModules->isActive($area, $slug),
            ),
        ];

        return new Response($this->twig->render('@Area/station/configure.html.twig', [
            'area' => $area,
            'register' => $register,
            'query' => $query,
            'open' => $open,
            'openRow' => null === $open ? null : self::rowOf($register, (string) $open->getUuidString()),
            'board' => $board,
            'blocks' => $blocks,
            'people' => $this->directory->people(),
            'nextCode' => $this->stationService->nextCode($area),
            // THE DEFAULT THE FORM STATES BESIDE THE FIELD, from the one place
            // it is decided — a number typed into the template would be a
            // second default that drifts from the one a new post gets.
            'defaultCatchmentM' => StationService::DEFAULT_CATCHMENT_M,
            'events' => $this->events->findByArea($area),
            'refusal' => $this->notices->takeRefusal($area),
            'outcome' => $this->notices->takeOutcome($area),
            'map' => $this->plates->picker($area, $view->rows, $posts),
            'addToken' => $this->csrf->getToken(StationEditController::ADD_TOKEN)->getValue(),
            'editToken' => $this->csrf->getToken(StationEditController::EDIT_TOKEN)->getValue(),
            'postingToken' => $this->csrf->getToken(StationEditController::POSTING_TOKEN)->getValue(),
        ]));
    }

    /**
     * THE OPEN ROW, IF IT IS THIS AREA'S. A uuid from another area opens
     * nothing rather than opening somebody else's post — the same answer a
     * mangled link gets.
     */
    private static function openRowOf(Request $request, AreaOfInterest $area, StationRepository $stations): ?Station
    {
        $uuid = $request->query->get(self::OPEN_QUERY);
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        $station = $stations->findOneBy(['uuid' => $uuid]);

        return $station instanceof Station && $station->getArea()?->getId() === $area->getId() ? $station : null;
    }

    /**
     * THE SAME QUESTION WITH THE ACTIVITY FILTER OPENED OUT — every other
     * word of the address kept, because widening one filter is not a reason
     * to forget a zone, a search or an order the reader also stated.
     */
    private static function showingEveryActivity(StationQuery $query): StationQuery
    {
        return new StationQuery(
            zone: $query->zone,
            active: null,
            posted: $query->posted,
            lead: $query->lead,
            search: $query->search,
            sort: $query->sort,
            page: $query->page,
        );
    }

    /** The open row as the register reads it, when this page of it holds one. */
    private static function rowOf(StationRegister $register, string $uuid): ?StationRow
    {
        foreach ($register->rows as $row) {
            if ($row->uuid === $uuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * AN UNKNOWN ANSWER FILTERS NOTHING and an unknown sort is the default: a
     * stale link should show the register, not an error about how it was
     * ordered.
     *
     * PUBLIC BECAUSE THE TAB READS THE SAME ADDRESS. The Stations tab filters
     * the same register with the same words, and two readers of one query
     * string would be two chances to disagree about what "active" means.
     */
    public static function queryFrom(Request $request): StationQuery
    {
        $active = trim($request->query->getString(StationQuery::ACTIVE, StationQuery::YES));
        $sort = $request->query->getString(StationQuery::SORT, StationQuery::BY_NAME);
        $posted = trim($request->query->getString(StationQuery::POSTED));
        $lead = trim($request->query->getString(StationQuery::LEAD));
        $zone = trim($request->query->getString(StationQuery::ZONE));

        return new StationQuery(
            zone: '' === $zone ? null : $zone,
            // "all" is how the address says both; anything else it does not
            // know is the resting state rather than an empty register.
            active: \in_array($active, StationQuery::ANSWERS, true) ? $active : ('all' === $active ? null : StationQuery::YES),
            posted: \in_array($posted, StationQuery::ANSWERS, true) ? $posted : null,
            lead: \in_array($lead, StationQuery::ANSWERS, true) ? $lead : null,
            search: trim($request->query->getString(StationQuery::SEARCH)),
            sort: \in_array($sort, StationQuery::SORTS, true) ? $sort : StationQuery::BY_NAME,
            page: max(1, $request->query->getInt(StationQuery::PAGE, 1)),
        );
    }
}
