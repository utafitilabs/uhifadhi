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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Controller\StationEditController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Enum\StationPositionSource;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE STATIONS SECTION OF THE CONFIGURE PAGE, OVER REAL HTTP.
 *
 * IT ONLY CONFIGURES, and every write commits from its own control and
 * answers with a redirect — so a refresh cannot repeat it and the row that
 * was open stays open.
 *
 * THE ADDRESS IS THE STATE. Every filter, the order and the page are links,
 * so a filtered register is something somebody can send and a browser can go
 * back to; this suite exercises them the way a reader does.
 */
#[CoversClass(StationConfigureController::class)]
#[CoversClass(StationEditController::class)]
final class StationConfigureTest extends WebTestCase
{
    public function testTheSectionListsThePostsWithTheirCodeDerivedZoneAndCount(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aStaffedPost();

        $body = $this->body($this->section($area));

        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringContainsString('ST-01', $body);
        self::assertStringContainsString('Western Sector', $body);
        self::assertStringContainsString('2 stationed', $body);
        // The plate is the atlas's, wearing the house contract.
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('map-legend', $body);
    }

    public function testAnAreaWithNoPostSaysSoAndOffersToAddTheFirst(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->section($this->anArea()));

        self::assertStringContainsString('No station in this area yet', $body);
        self::assertStringContainsString('Add the first station', $body);
        // The code the next post will take is stated before it is issued.
        self::assertStringContainsString('ST-01', $body);
    }

    public function testAddingAPostIssuesItsCodeDerivesItsZoneAndOpensItsRow(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $this->submit($area, '/stations/add', [
            'name' => 'Eastgate Post', 'lat' => '-3.2', 'lon' => '-29.75',
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        $station = $this->stationsRepository()->findOneBy(['name' => 'Eastgate Post']);
        self::assertInstanceOf(Station::class, $station);
        self::assertSame('ST-01', $station->getCode());
        self::assertSame('Western Sector', $station->getZone()?->getName());
        // The write comes back with the row it wrote still open.
        self::assertStringContainsString('open='.$station->getUuidString(), (string) $this->browser()->getResponse()->headers->get('Location'));
    }

    /**
     * THE ADD CARD STATES EVERY FACT THE RECORD WILL HOLD, and takes the
     * ones a person can know at the time: the name, the point, how high it
     * is, what a radio call would call the place, and the day it opened. The
     * code is issued and the zone is derived, so both are stated and
     * neither is asked for.
     */
    public function testAPostIsRecordedWithEverythingTheFormOffers(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->submit($area, '/stations/add', [
            'name' => 'Eastgate Post',
            'lat' => '-3.2',
            'lon' => '-29.75',
            'elevation' => '2286',
            'locality' => 'crater rim road',
            'opened' => '2024-01-14',
        ]);

        $station = $this->stationsRepository()->findOneBy(['name' => 'Eastgate Post']);
        self::assertInstanceOf(Station::class, $station);
        self::assertSame(2286, $station->getElevationM());
        self::assertSame('crater rim road', $station->getLocality());
        self::assertSame('2024-01-14', $station->getOpenedAt()?->format('Y-m-d'));
    }

    /** A name with no point is not a post, and the refusal says which. */
    public function testAPostWithNoPointIsRefusedAndNothingIsWritten(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->submit($area, '/stations/add', ['name' => 'Eastgate Post']);

        self::assertNull($this->stationsRepository()->findOneBy(['name' => 'Eastgate Post']));
        self::assertStringContainsString('it needs a point', $this->body($this->section($area)));
    }

    public function testTheNameThePointAndTheDescriptionEachCommitOnTheirOwn(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        $this->submit($area, '/stations/'.$uuid.'/rename', ['name' => 'Eastgate Main Gate'], $uuid);
        $this->submit($area, '/stations/'.$uuid.'/point', ['lat' => '-3.1', 'lon' => '-29.4'], $uuid);
        $this->submit($area, '/stations/'.$uuid.'/describe', [
            'elevation' => '2286', 'locality' => 'crater rim road',
        ], $uuid);

        $this->em->clear();
        $station = $this->stationsRepository()->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Station::class, $station);
        self::assertSame('Eastgate Main Gate', $station->getName());
        self::assertSame(2286, $station->getElevationM());
        self::assertSame('crater rim road', $station->getLocality());
        // The point moved east, out of the western zone, and the zone followed it.
        self::assertNull($station->getZone());
    }

    /**
     * WHAT THE PICKER WRITES IS WHAT THE FORMS TAKE.
     *
     * The plate writes a point as five decimal places into the very inputs a
     * person could have typed, so these are the same two writes as above with
     * the picker's own spelling — if the parsing ever narrowed, clicking the
     * ground would silently stop adding stations while typing went on
     * working.
     */
    public function testAPointPickedOnThePlateIsWhatTheAddAndMoveFormsAccept(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        $this->submit($area, '/stations/add', [
            'name' => 'Lakeshore Post',
            'lat' => '-3.26140',
            'lon' => '-29.41883',
        ]);
        $this->submit($area, '/stations/'.$uuid.'/point', ['lat' => '-3.19684', 'lon' => '-29.47122'], $uuid);

        $this->em->clear();
        $added = $this->stationsRepository()->findOneBy(['name' => 'Lakeshore Post']);
        self::assertInstanceOf(Station::class, $added);
        self::assertStringContainsString('-3.2614', (string) $added->getPoint());
        self::assertStringContainsString('-29.41883', (string) $added->getPoint());

        $moved = $this->stationsRepository()->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Station::class, $moved);
        self::assertStringContainsString('-3.19684', (string) $moved->getPoint());
    }

    /**
     * A POINT PLACED ON THE CONFIGURE PAGE IS SURVEYED — a post added there,
     * and an estimated post moved there.
     */
    public function testAPointPlacedOnTheConfigurePageIsSurveyed(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();
        $station->setPositionSource(StationPositionSource::Estimated);
        $this->em->flush();

        $this->submit($area, '/stations/add', ['name' => 'Lakeshore Post', 'lat' => '-3.26140', 'lon' => '-29.41883']);
        $this->submit($area, '/stations/'.$uuid.'/point', ['lat' => '-3.19684', 'lon' => '-29.47122'], $uuid);

        $this->em->clear();
        $added = $this->stationsRepository()->findOneBy(['name' => 'Lakeshore Post']);
        self::assertInstanceOf(Station::class, $added);
        self::assertSame(StationPositionSource::Surveyed, $added->getPositionSource());
        $moved = $this->stationsRepository()->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Station::class, $moved);
        self::assertSame(StationPositionSource::Surveyed, $moved->getPositionSource());
    }

    /**
     * THE PLATE IS ARMED BY A CONTROL THAT NAMES ITS FORM, and both forms the
     * picker writes into carry the id it names. A control that arms a form
     * that is not on the page is a click that does nothing at all.
     */
    public function testEachFormThePickerWritesIntoIsOnThePageUnderTheIdTheControlNames(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        $body = $this->body($this->section($area).'?open='.$uuid);

        self::assertStringContainsString('id="station-add"', $body);
        self::assertStringContainsString('data-atlas-pick="station-add"', $body);
        self::assertStringContainsString('id="move-'.$uuid.'"', $body);
        self::assertStringContainsString('data-atlas-pick="move-'.$uuid.'"', $body);
        // The typed pair is the fallback, so it stays in both forms.
        self::assertStringContainsString('name="lat"', $body);
        self::assertStringContainsString('name="lon"', $body);
    }

    /**
     * THE PLATE PICKS, AND THE PAGE EXTENDS NOTHING. The served plate carries
     * the pick under the atlas key — the add form, what it is called, the
     * pair of inputs and the precision — and its own caption; no element on
     * the page names the area's retired picker controller.
     */
    public function testThePlatePicksThePointAndThePageNamesNoPickerOfItsOwn(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aStaffedPost();

        $this->browser()->request('GET', $this->section($area));
        $page = $this->browser()->getCrawler();

        $extra = json_decode((string) $page->filter('[data-symfony--ux-leaflet-map--map-extra-value]')->attr('data-symfony--ux-leaflet-map--map-extra-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($extra);
        self::assertIsArray($extra['atlas'] ?? null);
        self::assertSame(
            ['form' => 'station-add', 'name' => 'the new station', 'latitude' => 'lat', 'longitude' => 'lon', 'precision' => 5],
            $extra['atlas']['pick'] ?? null,
        );

        self::assertCount(1, $page->filter('.map-plate > .pickcap'));
        $pin = $page->filter('.map-legend .lay')->last();
        self::assertStringStartsWith('The pin', trim($pin->text()));
        self::assertSame('being placed', $pin->filter('em')->text());
        self::assertStringNotContainsString('station-point', (string) $this->browser()->getResponse()->getContent());
    }

    /** THE ADDRESS IS THE STATE: each filter is a link, and it filters. */
    public function testTheRegisterIsFilteredByTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aStaffedPost();
        $this->stations()->add($area, 'Eastern Outpost', -29.25, -3.2);

        self::assertSame(['Eastern Outpost'], $this->listed($this->section($area).'?zone=unzoned'));
        self::assertSame(['Eastgate Post'], $this->listed($this->section($area).'?q=eastgate'));
        self::assertSame(['Eastern Outpost'], $this->listed($this->section($area).'?posted=no'));
    }

    public function testAFilterThatMatchesNothingSaysSoAndOffersTheWayBack(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aStaffedPost();

        $body = $this->body($this->section($area).'?q=nothing-by-this-name');

        self::assertStringContainsString('No station matches', $body);
        self::assertStringContainsString('Clear the filters', $body);
    }

    /** THE CARD IS BOUNDED BY A PAGE, not by a scrollbar. */
    public function testTheRegisterIsPagedEightAtATime(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        for ($i = 1; $i <= 9; ++$i) {
            $this->stations()->add($area, \sprintf('Station %02d', $i), -29.75, -3.2);
        }

        self::assertStringContainsString('1&ndash;8 of 9 stations', $this->body($this->section($area)));
        self::assertCount(8, $this->listed($this->section($area)));
        self::assertSame(['Station 09'], $this->listed($this->section($area).'?page=2'));
    }

    /**
     * AN OPEN ROW CARRIES THE RECORD FIELDS AND THE BOARD, and the board is
     * the only place a leader is appointed.
     */
    public function testAnOpenRowDrawsTheRecordFieldsAndThePostingsBoard(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->section($area).'?open='.$station->getUuidString());

        self::assertStringContainsString('Stationed here', $body);
        self::assertStringContainsString('J. Mollel', $body);
        self::assertStringContainsString('Leads', $body);
        self::assertStringContainsString('Appoint leader', $body);
        self::assertStringContainsString('Station somebody here', $body);
        self::assertStringContainsString('derived', $body);
    }

    public function testSomebodyIsPostedAppointedAndStoodDownFromTheOpenRow(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();
        $newcomer = $this->aPerson('B.', 'Mwita');

        $this->submit($area, '/stations/'.$uuid.'/postings', [
            'person' => (string) $newcomer->getUuidString(),
        ], $uuid);

        $body = $this->body($this->section($area).'?open='.$uuid);
        self::assertStringContainsString('B. Mwita', $body);
        self::assertSame(3, \count($this->postings()->standingAt($station)));

        // The newcomer leads, which stands the old lead down in the same write.
        $posting = $this->postings()->standingAt($station)[0];
        foreach ($this->postings()->standingAt($station) as $standing) {
            if ('B. Mwita' === $standing->getPerson()?->getFullName()) {
                $posting = $standing;
            }
        }

        $this->submit($area, '/postings/'.$posting->getUuidString().'/lead', [], $uuid);
        self::assertSame('B. Mwita', $this->postings()->leaderAt($station)?->getPerson()?->getFullName());

        $this->submit($area, '/postings/'.$posting->getUuidString().'/end', [], $uuid);
        self::assertSame(2, \count($this->postings()->standingAt($station)));
    }

    /**
     * CLOSED, NOT DELETED: it keeps its point, leaves the active register,
     * stays reachable behind the filter and can be reopened.
     */
    public function testClosingAPostStandsEverybodyDownAndLeavesItBehindTheFilter(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        // The question is asked through the platform's shared confirm modal.
        self::assertStringContainsString('confirm-modal', $this->body($this->section($area).'?open='.$uuid));

        $this->submit($area, '/stations/'.$uuid.'/activity', ['active' => '0'], $uuid);

        self::assertSame([], $this->postings()->standingAt($station));
        self::assertSame([], $this->listed($this->section($area)));
        self::assertSame(['Eastgate Post'], $this->listed($this->section($area).'?active=no'));

        $this->submit($area, '/stations/'.$uuid.'/activity', ['active' => '1'], $uuid.'&active=no');
        self::assertSame(['Eastgate Post'], $this->listed($this->section($area)));
    }

    /** A post of another area is not this section's to change. */
    public function testAPostOfAnotherAreaIsRefused(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $other = $this->anArea('Second Reserve');

        // The token is this area's page's; the address is the other area's.
        $this->submit($area, '/stations/'.$station->getUuidString().'/rename', ['name' => 'Elsewhere'], (string) $station->getUuidString(), $other);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    /** Reading how an area is set up is a lens; changing it is an edit. */
    public function testAViewerWhoMayNotEditIsRefusedEveryWriteAndStillSeesTheSection(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('GET', $this->section($area));
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());

        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/stations/add', ['_token' => 'whatever']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    /**
     * WHAT A MODULE ASKS ABOUT A POST, inside the post's own card: the
     * watch a station is expected to keep is the roster's and not the
     * area's, so those rows are contributed after the postings board.
     *
     * THE HEADING IS THE PAGE'S, wearing the contributing module's tag —
     * the same rule as the record's band, so the two read as one product.
     */
    public function testAModuleContributesABlockInsideEachStationsCard(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aLiveArea();
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');

        // ONE CARD IS OPEN AT A TIME on this page, and a card's fields —
        // the area's and the modules' alike — exist only while it is.
        $body = $this->body($this->section($area).'?open='.$station->getUuidString());

        self::assertStringContainsString('Watch and presence', $body);
        self::assertStringContainsString('ao-by patrols', $body);
        self::assertStringContainsString('day 06-18 and night 18-06', $body);
        // Inside the card, not as a card of its own.
        self::assertStringNotContainsString('rband', $body);
    }

    /**
     * WITH NO SUCH MODULE THE CARD SIMPLY ENDS SOONER — no heading, no rows
     * and no placeholder for a block nobody offered.
     */
    public function testAnAreaRunningNoSuchModuleGetsNoBlock(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');

        $body = $this->body($this->section($area).'?open='.$station->getUuidString());

        self::assertStringNotContainsString('Watch and presence', $body);
    }

    // ---------------------------------------------------------------- fixtures

    private function section(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/stations';
    }

    /**
     * THE REGISTER'S ROWS ALONE.
     *
     * THE PLATE NAMES EVERY POST IN THE AREA, because a picker that hid the
     * posts a filter excluded would be a picker you could put a new point on
     * top of one with. So "this row is not in the register" is asked of the
     * register and not of the page.
     *
     * @return list<string>
     */
    private function listed(string $url): array
    {
        preg_match_all('#<a class="zc-nm"[^>]*>([^<]+)</a>#', $this->body($url), $found);

        return array_map(trim(...), $found[1]);
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /**
     * A WRITE, THE WAY A READER MAKES IT: the token comes off the page that
     * carries the form, because a token minted outside a request belongs to
     * no session and proves nothing about the form.
     *
     * @param array<string, string> $fields
     */
    private function submit(AreaOfInterest $area, string $path, array $fields, ?string $open = null, ?AreaOfInterest $to = null): void
    {
        $page = $this->body($this->section($area).(null === $open ? '' : '?open='.$open));

        $this->browser()->request('POST', '/areas/'.($to ?? $area)->getUuidString().$path, [
            ...$fields,
            '_token' => $this->tokenOn($page, $path),
        ]);
    }

    private function tokenOn(string $body, string $action): string
    {
        preg_match(
            '#<form[^>]*action="[^"]*'.preg_quote($action, '#').'"[^>]*>.*?name="_token" value="([^"]+)"#s',
            $body,
            $matches,
        );

        $token = $matches[1] ?? '';
        self::assertNotSame('', $token, \sprintf('The page carries no form posting to "%s".', $action));

        return $token;
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aStaffedPost(): array
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T.', 'Ndosi'), PostingSource::FromTheirPage);

        return [$area, $station];
    }

    private function aPerson(string $first, string $last): HostUser
    {
        $person = new HostUser()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function stationsRepository(): StationRepository
    {
        /** @var StationRepository $repository */
        $repository = $this->em->getRepository(Station::class);

        return $repository;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }

    /**
     * THE RING IS EDITED WHERE THE POST IS CONFIGURED, and it is its own
     * form: the row beside it says what a radio call would say, this one
     * decides whether somebody standing there counts as being at their post.
     *
     * Until this existed nothing set a catchment at all, so every day
     * claimed at a post derived UNVERIFIED and the Live tab read "verified
     * at a post 0 of 13" with people standing at theirs. A read with no
     * write is a feature that looks broken.
     */
    public function testTheCatchmentIsSetFromTheStationsOwnCard(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        $page = $this->body($this->section($area).'?open='.$uuid);
        self::assertStringContainsString('name="catchment"', $page);
        self::assertStringContainsString('default 1,500 m', $page, 'the design states the default beside the field');

        $this->submit($area, '/stations/'.$uuid.'/catchment', ['catchment' => '900'], $uuid);

        self::assertSame(900, $this->reread($uuid)->getCatchmentM());
    }

    /** EMPTY IS NO RING AT ALL, which is a state and not a blank. */
    public function testAnEmptyCatchmentClearsTheRing(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();

        $this->submit($area, '/stations/'.$uuid.'/catchment', ['catchment' => ''], $uuid);

        self::assertNull($this->reread($uuid)->getCatchmentM());
    }

    /** And anything that is not a radius in whole metres is refused, not stored. */
    public function testACatchmentThatIsNotARadiusIsRefused(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();
        $uuid = (string) $station->getUuidString();
        $was = $this->reread($uuid)->getCatchmentM();

        $this->submit($area, '/stations/'.$uuid.'/catchment', ['catchment' => '1.5 km'], $uuid);

        self::assertSame($was, $this->reread($uuid)->getCatchmentM());
        self::assertStringContainsString('a catchment is a radius in whole metres', $this->body($this->section($area)));
    }

    /** One post, read back from the database rather than from a stale object. */
    private function reread(string $uuid): Station
    {
        $this->em->clear();
        $station = $this->stationsRepository()->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Station::class, $station);

        return $station;
    }
}
