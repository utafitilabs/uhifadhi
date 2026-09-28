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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Exception\PostingException;
use Uhifadhi\Bundle\AreaBundle\Model\Actor;
use Uhifadhi\Bundle\AreaBundle\Model\StationPoint;
use Uhifadhi\Bundle\AreaBundle\Service\PersonDirectoryService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationNoticeStore;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Contracts\Access\PersonAccess;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * EVERY WRITE THE STATIONS SECTION MAKES.
 *
 * ONE FORM PER WRITE AND NO FORM AROUND THE PAGE. A name, a point, the
 * description, the activity and each posting commit from their own control,
 * so nothing is saved that nobody touched and a refusal is about one thing.
 *
 * EVERY WRITE ANSWERS WITH A REDIRECT back to the section, so a refresh
 * cannot repeat it, and the row that was open stays open — a write that
 * closed the card would make the next edit a second hunt through the list.
 *
 * THE VERBS OWN THE RULES. A code is issued, a zone is re-derived, a leader
 * steps another down and a closure ends the postings standing at a post —
 * all of that is the services', so a console command or an importer gets the
 * same outcome as this page and writes the same history.
 */
final readonly class StationEditController
{
    public const string ADD_TOKEN = 'area_station_add';
    public const string EDIT_TOKEN = 'area_station_edit';
    public const string POSTING_TOKEN = 'area_station_posting';

    public function __construct(
        private StationService $stations,
        private PostingService $postings,
        private PersonDirectoryService $directory,
        private StationNoticeStore $notices,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private ?TokenStorageInterface $tokens = null,
        /**
         * WHO MAY BE POSTED BY WHOM — a posting changes a person, so it asks
         * the person-configure permission as the person's own page does
         * (ruled 28 Sep 2026: only a Super Admin configures a Super Admin).
         */
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * A STATION IS A POINT IN THE AREA, NOT IN A ZONE. Nothing here chooses a
     * zone: the point decides, and the service re-derives it in the same
     * write.
     */
    #[Route('/areas/{uuid}/stations/add', name: 'area_station_add', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function add(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $this->denyUnlessTokenValid($request, self::ADD_TOKEN);

        $name = trim($request->request->getString('name'));
        $point = self::pointFrom($request);

        if ('' === $name) {
            return $this->refuse($area, 'the station', 'it needs a name; a point with nothing to call it is not a post');
        }

        if (null === $point) {
            return $this->refuse($area, $name, 'it needs a point: a latitude and a longitude, in degrees');
        }

        $elevation = trim($request->request->getString('elevation'));

        $station = $this->stations->add(
            $area,
            $name,
            $point->lon,
            $point->lat,
            actor: $this->actor(),
            elevationM: '' === $elevation ? null : (int) $elevation,
            locality: $request->request->getString('locality'),
            openedAt: self::dayOf($request->request->getString('opened')),
        );
        $zone = $station->getZone();
        $this->notices->holdOutcome($area, \sprintf(
            '%s recorded as %s · %s',
            (string) $station->getName(),
            (string) $station->getCode(),
            null === $zone ? 'unzoned, which is legal' : 'in '.(string) $zone->getName(),
        ));

        return $this->backToTheSection($area, $station);
    }

    #[Route('/areas/{uuid}/stations/{station}/rename', name: 'area_station_rename', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function rename(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::EDIT_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            return $this->refuse($area, (string) $station->getName(), 'a post cannot be left with no name', $station);
        }

        $this->stations->rename($station, $name, $this->actor());

        return $this->backToTheSection($area, $station);
    }

    /**
     * THE POINT MOVED, so the zone is re-asked in the same write and the log
     * says how far it went and which way.
     */
    #[Route('/areas/{uuid}/stations/{station}/point', name: 'area_station_move', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function move(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::EDIT_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $point = self::pointFrom($request);
        if (null === $point) {
            return $this->refuse($area, (string) $station->getName(), 'a point is a latitude and a longitude, in degrees', $station);
        }

        $this->stations->moveTo($station, $point->lon, $point->lat, $this->actor());

        return $this->backToTheSection($area, $station);
    }

    /**
     * WHAT A RADIO CALL WOULD SAY — the elevation and the locality, which are
     * descriptions of the place rather than facts the system derives.
     */
    #[Route('/areas/{uuid}/stations/{station}/describe', name: 'area_station_describe', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function describe(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::EDIT_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $elevation = trim($request->request->getString('elevation'));

        /*
         * THE DAY IT OPENED IS NOT ON THIS FORM, so it is carried over rather
         * than cleared: a form that silently dropped a fact nobody was shown
         * is a form that deletes by omission.
         */
        $this->stations->describe(
            $station,
            $station->getCode(),
            '' === $elevation ? null : (int) $elevation,
            $request->request->getString('locality'),
            $station->getOpenedAt(),
        );

        return $this->backToTheSection($area, $station);
    }

    /**
     * WHAT "INSIDE THIS POST" MEANS — the ring a check-in claiming this post
     * is judged against, in metres.
     *
     * ITS OWN FORM, because it is its own question: the row beside it says
     * what a radio call would say, and this one decides whether somebody
     * standing there counts as being at their post. Empty clears it, and a
     * post with no ring has no inside — the handset says so rather than
     * picking a radius of its own.
     */
    #[Route('/areas/{uuid}/stations/{station}/catchment', name: 'area_station_catchment', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function catchment(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::EDIT_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $metres = trim($request->request->getString('catchment'));
        if ('' !== $metres && 1 !== preg_match('/^\d{1,6}$/', $metres)) {
            return $this->refuse($area, (string) $station->getName(), 'a catchment is a radius in whole metres', $station);
        }

        $this->stations->setCatchment($station, '' === $metres ? null : (int) $metres);

        return $this->backToTheSection($area, $station);
    }

    /**
     * CLOSED, NOT DELETED. A deactivated post keeps its point and its code,
     * stays in the register behind the Active filter and can be reopened;
     * every record already made against it still points at it.
     */
    #[Route('/areas/{uuid}/stations/{station}/activity', name: 'area_station_activity', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('stations.configure', subject: 'area')]
    public function activity(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::EDIT_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        if ($request->request->getBoolean('active')) {
            $this->stations->reactivate($station, $this->actor());
            $this->notices->holdOutcome($area, \sprintf('%s is open again · nobody is posted to it yet', (string) $station->getName()));

            return $this->backToTheSection($area, $station);
        }

        $standing = \count($this->postings->standingAt($station));
        $this->stations->deactivate($station, self::dayOf($request->request->getString('on')), $this->actor());
        $this->notices->holdOutcome($area, \sprintf(
            '%s closed · %s',
            (string) $station->getName(),
            0 === $standing ? 'nobody was posted to it' : \sprintf('%d posting%s ended', $standing, 1 === $standing ? '' : 's'),
        ));

        return $this->backToTheSection($area, $station);
    }

    /**
     * SOMEBODY POSTED HERE. A posting is STANDING — one fact with two doors,
     * written here and on the person's own page — and which door it came in
     * by is part of the row.
     */
    #[Route('/areas/{uuid}/stations/{station}/postings', name: 'area_station_post', requirements: ['uuid' => Requirement::UUID, 'station' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('assignments.manage', subject: 'area')]
    public function post(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['station' => 'uuid'])] Station $station,
    ): Response {
        $this->denyUnlessTokenValid($request, self::POSTING_TOKEN);
        $this->denyUnlessTheStationIsThisAreas($area, $station);

        $person = $this->directory->person($request->request->getString('person'));
        if (null === $person) {
            return $this->refuse($area, (string) $station->getName(), 'pick somebody the directory offers', $station);
        }
        $this->denyUnlessMayConfigure($person);

        try {
            $this->postings->post($station, $person, PostingSource::WrittenHere, actor: $this->actor());
        } catch (PostingException $e) {
            return $this->refuse($area, (string) $station->getName(), $e->getMessage(), $station);
        }

        return $this->backToTheSection($area, $station);
    }

    /** A posting ENDS; it is never deleted. Last season's patrol still has its crew. */
    #[Route('/areas/{uuid}/postings/{posting}/end', name: 'area_posting_end', requirements: ['uuid' => Requirement::UUID, 'posting' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('assignments.manage', subject: 'area')]
    public function end(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['posting' => 'uuid'])] Posting $posting,
    ): Response {
        $this->denyUnlessTokenValid($request, self::POSTING_TOKEN);
        $station = $this->stationOf($area, $posting);
        $this->denyUnlessMayConfigure($posting->getPerson());

        $this->postings->end($posting, null, $this->actor());

        return $this->backToTheSection($area, $station);
    }

    /**
     * ONE LEADER PER POST, and appointing one steps the current one down in
     * the same write. This is the only page that appoints: the station page
     * states who leads and refuses to change it.
     */
    #[Route('/areas/{uuid}/postings/{posting}/lead', name: 'area_posting_lead', requirements: ['uuid' => Requirement::UUID, 'posting' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('assignments.manage', subject: 'area')]
    public function lead(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        #[MapEntity(mapping: ['posting' => 'uuid'])] Posting $posting,
    ): Response {
        $this->denyUnlessTokenValid($request, self::POSTING_TOKEN);
        $station = $this->stationOf($area, $posting);
        $this->denyUnlessMayConfigure($posting->getPerson());

        try {
            $this->postings->appointLeader($posting, $this->actor());
        } catch (PostingException $e) {
            return $this->refuse($area, (string) $station->getName(), $e->getMessage(), $station);
        }

        return $this->backToTheSection($area, $station);
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * The controller's `denyAccessUnlessGranted(PersonAccess::CONFIGURE, $person)`
     * — it extends nothing. Without an authorization checker there is no one
     * to answer, and nobody is refused on a question nobody can ask.
     */
    private function denyUnlessMayConfigure(?UserInterface $person): void
    {
        if (null !== $person && null !== $this->authorization && !$this->authorization->isGranted(PersonAccess::CONFIGURE, $person)) {
            throw new AccessDeniedException('Only a Super Admin changes a Super Admin, and only an Admin or a Super Admin an Admin.');
        }
    }

    /**
     * LATITUDE AND LONGITUDE, AS A PERSON WRITES THEM. The form asks for them
     * in that order because that is the order they are read out in; the
     * model stores them the other way round, and this is the one place the
     * order flips.
     */
    private static function pointFrom(Request $request): ?StationPoint
    {
        $lat = trim($request->request->getString('lat'));
        $lon = trim($request->request->getString('lon'));

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        return abs($lat) > 90.0 || abs($lon) > 180.0 ? null : new StationPoint($lon, $lat);
    }

    /** A day somebody typed, or today — never a date this refuses to read. */
    private static function dayOf(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function stationOf(AreaOfInterest $area, Posting $posting): Station
    {
        $station = $posting->getStation();
        if (null === $station) {
            throw new AccessDeniedException('That posting stands at no station.');
        }

        $this->denyUnlessTheStationIsThisAreas($area, $station);

        return $station;
    }

    private function refuse(AreaOfInterest $area, string $subject, string $why, ?Station $station = null): RedirectResponse
    {
        $this->notices->holdRefusal($area, $subject, $why);

        return $this->backToTheSection($area, $station);
    }

    /**
     * BACK TO THE SECTION, WITH THE ROW STILL OPEN. A write that closed the
     * card would make the next edit a second hunt through the list.
     */
    private function backToTheSection(AreaOfInterest $area, ?Station $station = null): RedirectResponse
    {
        $parameters = ['uuid' => (string) $area->getUuidString()];
        if (null !== $station) {
            $parameters[StationConfigureController::OPEN_QUERY] = (string) $station->getUuidString();
        }

        return new RedirectResponse($this->urls->generate(StationConfigureController::ROUTE, $parameters));
    }

    private function denyUnlessTheStationIsThisAreas(AreaOfInterest $area, Station $station): void
    {
        if ($station->getArea()?->getId() !== $area->getId()) {
            throw new AccessDeniedException('That station does not belong to this area.');
        }
    }

    private function denyUnlessTokenValid(Request $request, string $id): void
    {
        $submitted = $request->request->get('_token');
        if (!\is_string($submitted) || !$this->csrf->isTokenValid(new CsrfToken($id, $submitted))) {
            throw new AccessDeniedException('The form was submitted without a valid CSRF token.');
        }
    }

    private function actor(): ?string
    {
        return Actor::of($this->tokens?->getToken()?->getUser());
    }
}
