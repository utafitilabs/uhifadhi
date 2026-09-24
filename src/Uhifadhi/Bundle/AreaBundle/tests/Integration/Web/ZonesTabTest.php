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
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE ZONES TAB, OVER REAL HTTP — how an area is divided, read.
 *
 * PICK ALL ZONES OR ONE. This is the all-zones state: the area's band, the
 * area's figures, the whole ground on one plate, and under it the stations
 * and the people those zones account for. Picking one is the zone record,
 * which is the same surface one step in.
 *
 * A LENS, NOT A FENCE. Reading it is for anybody who may see the area; every
 * write is on the configure section, and the only control here that changes
 * anything is the Configure action the frame draws.
 */
#[CoversClass(ZoneController::class)]
final class ZonesTabTest extends WebTestCase
{
    public function testTheTabDrawsTheBandTheFiguresThePlateAndBothCards(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $body = $this->body($this->tab($area));

        self::assertStringContainsString('People stationed', $body);
        // The ground, wearing the house map contract.
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('map-legend', $body);
        // And the two cards under it.
        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringContainsString('J. Mollel', $body);
        self::assertStringContainsString('class="atabs"', $body);
    }

    /**
     * THE BAND IS FOLLOWED BY THE FIGURES, and by nothing in between.
     *
     * RULED 2026-09-20: the selection row goes. "All zones · the whole area"
     * named a selection the page cannot change — the picker is the sidebar —
     * and it put two module actions a third of the way down a page whose
     * first job is to state the set. What it said, the band and the heading
     * already say.
     */
    public function testTheBandIsFollowedByTheFiguresWithNoSelectionRow(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $body = $this->body($this->tab($area));

        self::assertStringNotContainsString('zsel', $body);
        // The plate's own heading still says it — "All zones on the ground" is
        // what the card is about, and the row that went was the selection.
        self::assertStringNotContainsString('<h2', $body);
        self::assertMatchesRegularExpression('#</div>\s*<div class="grid kstrip">#', $body);
    }

    /**
     * NO MODULE PUBLISHES ABOUT A ZONE HERE, so the cards that would carry a
     * module's figure say so and keep their slot — a row of three where the
     * design has four is a different design.
     */
    public function testTheModuleFiguresSayNobodyPublishesRatherThanBeingDropped(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $body = $this->body($this->tab($area));

        self::assertSame(4, substr_count($body, 'class="c kpi"'));
        self::assertStringContainsString('no module publishes this', $body);
    }

    /** An area nobody has zoned says what a zone is, rather than drawing an empty table. */
    public function testAnAreaWithNoZoneSaysWhatAZoneIs(): void
    {
        $this->boot();
        $this->signIn();

        $body = strtolower($this->body($this->tab($this->anArea())));

        self::assertStringContainsString('no zones yet', $body);
        self::assertStringContainsString('lens', $body);
    }

    /** The stations card carries the house filter row, and the address is its state. */
    public function testTheStationsCardFiltersOffTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();
        $this->stations()->add($area, 'Eastern Outpost', -29.25, -3.2);

        self::assertSame(['Eastgate Post'], $this->listed($this->tab($area).'?q=eastgate'));
        self::assertSame(['Eastern Outpost'], $this->listed($this->tab($area).'?zone=unzoned'));
        self::assertSame(['Eastern Outpost'], $this->listed($this->tab($area).'?lead=no'));
    }

    /** The people card searches by name, on a key of its own. */
    public function testThePeopleCardSearchesByName(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $body = $this->body($this->tab($area).'?person=ndosi');

        self::assertStringContainsString('T. Ndosi', $body);
        self::assertStringNotContainsString('>J. Mollel<', $body);
    }

    public function testAViewerWhoMayNotSeeTheAreaIsRefused(): void
    {
        $this->boot([]);
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $this->browser()->request('GET', $this->tab($area));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------- fixtures

    private function tab(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/zones';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /**
     * The stations card's rows alone — the plate names every post in the
     * area on purpose, so "not in the card" is asked of the card.
     *
     * @return list<string>
     */
    private function listed(string $url): array
    {
        // THE ZONES TABLE IS THE STATIONS TABLE'S TWIN and writes the same
        // row markup, so the question is asked of the stations table by
        // name: the one that is not the zones list.
        preg_match('#<table class="tbl zdtbl">(.*?)</table>#s', $this->body($url), $table);
        preg_match_all('#<td class="zname">.*?<b>([^<]+)</b>#s', $table[1] ?? '', $found);

        return array_map(trim(...), $found[1]);
    }

    /** @return array{0: AreaOfInterest, 1: Zone} */
    private function anAreaWithAZonedPost(): array
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T.', 'Ndosi'), PostingSource::FromTheirPage);

        return [$area, $zone];
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
