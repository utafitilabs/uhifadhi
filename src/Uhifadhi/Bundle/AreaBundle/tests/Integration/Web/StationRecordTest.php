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
use Uhifadhi\Bundle\AreaBundle\Controller\StationRecordController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * ONE POST, READ, OVER REAL HTTP.
 *
 * A RECORD PAGE AND NOT A TAB: the crumb and the header, no tab strip.
 *
 * THE BOARD IS FILTERED BY THE ADDRESS. Every filter is a link, so this suite
 * exercises them the way a reader does — by following one — and what it
 * proves is that the address is the state: the same page asked twice with
 * different queries answers differently, and a filtered board is a link
 * somebody could have sent.
 */
#[CoversClass(StationRecordController::class)]
final class StationRecordTest extends WebTestCase
{
    public function testThePostIsDrawnWithItsBandItsPlateAndItsBoard(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringContainsString('ST-01', $body);
        self::assertStringContainsString('Who is stationed here', $body);
        self::assertStringContainsString('What happened here', $body);
        // The plate is the atlas's, wearing the house contract.
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('map-legend', $body);
        // And no tab strip: a record is not one of the ways of looking at an area.
        self::assertStringNotContainsString('class="atabs"', $body);
    }

    public function testTheBandNamesTheDerivedZoneAndTheLead(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('>West<', $body);
        self::assertStringContainsString('derived', $body);
        self::assertStringContainsString('J. Mollel', $body);
        self::assertStringContainsString('Leads', $body);
    }

    /**
     * THE PLATE IS FIXED AT THE HEIGHT THE DESIGN DRAWS IT, stated by the
     * page: the atlas's default is a share of the viewport, and a record's
     * plate that changed height with the window would be a different page on
     * every screen.
     */
    public function testThePlateIsDrawnAtTheHeightTheDesignFixes(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        self::assertStringNotContainsString('--map-plate-height:', $this->body($this->record($area, $station)));
    }

    /**
     * A RECORD PAGE CARRIES NO CONFIGURE ACTION. Configure belongs to the
     * area's TABS — it is how you leave the data for the settings — and a
     * record inside an area is not one of the ways of looking at the area.
     * The way to edit this post is the one action the page does carry.
     */
    public function testARecordCarriesNoConfigureActionOnlyTheWayToEditThePost(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Edit the station</a>', $body);
        self::assertStringNotContainsString('Configure</a>', $body);
        // The tab it belongs to still carries it, which is what makes the
        // record's omission a rule rather than a missing action.
        $this->browser()->request('GET', '/areas/'.$area->getUuidString().'/stations');
        self::assertStringContainsString('Configure</a>', (string) $this->browser()->getResponse()->getContent());
    }

    /**
     * THE BAND STATES WHETHER THE POST IS OPEN, always — a post nobody wrote
     * an opening date for is still open or closed, and a cell that vanished
     * with the date would take that fact with it.
     */
    public function testTheBandStatesWhetherThePostIsOpenEvenWithNoDate(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Opened', $body);
        self::assertStringContainsString('active', $body);
    }

    /** A post on unzoned ground says so; unzoned is legal, not an error. */
    public function testAPostOnUnzonedGroundSaysSo(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Eastern Station', -29.25, -3.2);

        self::assertStringContainsString('unzoned', $this->body($this->record($area, $station)));
    }

    public function testTheBoardIsFilteredByTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $all = $this->board($this->record($area, $station));
        self::assertStringContainsString('J. Mollel', $all);
        self::assertStringContainsString('T. Ndosi', $all);

        // T. Ndosi was posted from their own page; J. Mollel here. The BAND
        // still names the lead either way — who leads the post is not a fact
        // about the filter — so the assertion is about the board.
        $fromTheirPage = $this->board($this->record($area, $station).'?src=person');
        self::assertStringContainsString('T. Ndosi', $fromTheirPage);
        self::assertStringNotContainsString('J. Mollel', $fromTheirPage);
    }

    public function testTheSearchReadsTheName(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $board = $this->board($this->record($area, $station).'?q=ndosi');

        self::assertStringContainsString('T. Ndosi', $board);
        self::assertStringNotContainsString('J. Mollel', $board);
    }

    /** A filter that leaves nothing says which, and offers the way back. */
    public function testAFilterThatMatchesNobodySaysSoAndOffersToClear(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station).'?q=nobody-by-this-name');

        self::assertStringContainsString('Nobody matches', $body);
        self::assertStringContainsString('Clear the filters', $body);
    }

    /** An empty post is a normal state, and reads differently from a filtered one. */
    public function testAPostWithNobodyAtItReadsAsNormal(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Munge Camp', -29.75, -3.2);

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Nobody is stationed here', $body);
        self::assertStringNotContainsString('Clear the filters', $body);
    }

    /**
     * NO MODULE PUBLISHES YET, and the card says so in the product's own
     * words rather than drawing four empty rows.
     */
    public function testTheDockSaysNobodyPublishesRatherThanDrawingNaughts(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('What the modules publish', $body);
        self::assertStringContainsString('No module publishes figures for this station yet.', $body);
    }

    /**
     * WHAT A MODULE PUTS ON THE POST ITSELF: a banded card, after the area's
     * own, wearing the tag of the module that contributed it.
     *
     * THE AREA DRAWS THE BAND AND THE MODULE FILLS IT. The heading, the
     * summary line, the action and the provenance tag are all written by the
     * page; the contributor supplied words and a template of rows. That is
     * what keeps two modules' bands from coming out as two products.
     */
    public function testAModuleContributesABandAfterTheAreasOwnCards(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPostOfALiveArea();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('class="c rband" id="watch"', $body);
        self::assertStringContainsString('Watch and presence', $body);
        // ESCAPED, AND THAT IS THE POINT: a contributor supplies words, not
        // markup, so the ampersand it sent comes out as an ampersand.
        self::assertStringContainsString('day &amp; night · 2 rostered now', $body);
        self::assertStringContainsString('The rotation', $body);
        // The rows the module wrote, inside the band the area drew.
        self::assertStringContainsString('day 06-18 and night 18-06', $body);
        // And the tag, so a band that disappears reads as the system working.
        self::assertStringContainsString('ao-by patrols', $body);
    }

    /** The band stands after the postings board, never before it. */
    public function testTheContributedBandComesAfterTheAreasOwnColumn(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPostOfALiveArea();

        $body = $this->body($this->record($area, $station));

        self::assertLessThan(
            strpos($body, 'id="watch"'),
            strpos($body, 'Who is stationed here'),
            'the area draws its own cards first and the contributed bands after them',
        );
    }

    /**
     * WITH NO CONTRIBUTOR THERE IS NO BAND — no heading, no empty card, no
     * placeholder. An installation without the module reads a shorter page,
     * and nothing is missing that is not also absent.
     */
    public function testAPostOfAnAreaRunningNoSuchModuleDrawsNoBand(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringNotContainsString('rband', $body);
        self::assertStringNotContainsString('Watch and presence', $body);
    }

    /** A station of another area is not this page's subject. */
    public function testAStationOfAnotherAreaIsRefused(): void
    {
        $this->boot();
        $this->signIn();
        [, $station] = $this->aStaffedPost();
        $other = $this->anArea('Second Reserve');

        $this->browser()->request('GET', '/areas/'.$other->getUuidString().'/stations/'.$station->getUuidString());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    /** Reading how a post is staffed is a lens, gated on seeing the area. */
    public function testAViewerWhoMayNotSeeTheAreaIsRefused(): void
    {
        $this->boot([]);
        $this->signIn();
        [$area, $station] = $this->aStaffedPost();

        $this->browser()->request('GET', $this->record($area, $station));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------- fixtures

    private function record(AreaOfInterest $area, Station $station): string
    {
        return '/areas/'.$area->getUuidString().'/stations/'.$station->getUuidString();
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /**
     * THE BOARD ALONE, because the band above it names the lead whatever the
     * filter says: who leads a post is not a fact about a filter, and an
     * assertion over the whole page would be asserting that it is.
     */
    private function board(string $url): string
    {
        preg_match_all('#<div class="pline">.*?</div>#s', $this->body($url), $lines);

        return implode("\n", $lines[0]);
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aStaffedPost(): array
    {
        return $this->aStaffedPostIn($this->anArea());
    }

    /**
     * THE SAME POST, IN AN AREA THAT RUNS A MODULE. The ledger is what
     * decides whether a contributor is asked at all, so a suite about
     * contributed bands has to switch one on.
     *
     * @return array{0: AreaOfInterest, 1: Station}
     */
    private function aStaffedPostOfALiveArea(): array
    {
        return $this->aStaffedPostIn($this->aLiveArea());
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aStaffedPostIn(AreaOfInterest $area): array
    {
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');

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

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }
}
