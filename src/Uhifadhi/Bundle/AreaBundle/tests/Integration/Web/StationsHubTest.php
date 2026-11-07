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
use Uhifadhi\Bundle\AreaBundle\Controller\StationPostingsController;
use Uhifadhi\Bundle\AreaBundle\Controller\StationsController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE STATIONS HUB — Overview · Stations · Postings, one strip, its own.
 *
 * Design: areas/<area>/stations/index.html, stations.html and postings.html.
 * The strip and the figures belong to the Overview; the register and the
 * postings are tables with the house bar above and the house foot under.
 */
#[CoversClass(StationsController::class)]
#[CoversClass(StationPostingsController::class)]
final class StationsHubTest extends WebTestCase
{
    public function testTheHubCarriesItsOwnStripInPlaceOfTheAreaTabs(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();

        foreach (['' => 'Overview', '/list' => 'Stations', '/postings' => 'Postings'] as $suffix => $lit) {
            $strip = $this->strip($this->body($this->hub($area).$suffix));

            self::assertSame(['Overview', 'Stations', 'Postings'], $strip['labels'], 'The hub draws one strip, its own.');
            self::assertSame($lit, $strip['on']);
        }
    }

    public function testTheOverviewStatesTheSetAndCarriesNoTable(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();

        $body = $this->body($this->hub($area));

        self::assertStringContainsString('<span class="banner-t"><b>1 station has nobody posted</b> <span class="banner-c">Eastern Outpost</span>', $body);
        self::assertStringContainsString('href="'.$this->hub($area).'/list?posted=no"', $body);

        self::assertStringContainsString('class="factband ov-band"', $body);
        self::assertStringContainsString('class="banner banner-warn"', $body);
        self::assertStringNotContainsString('pp-drift', $body);
        foreach (['Stations', 'Zones', 'Unzoned', 'Departments', 'Period'] as $field) {
            self::assertStringContainsString('<span class="k">'.$field.'</span>', $body);
        }
        self::assertStringContainsString('2<em>1 posted</em>', $body);
        self::assertStringContainsString('1<em>of 1 has a station</em>', $body);
        self::assertStringContainsString('1<em>station in no zone</em>', $body);

        self::assertSame(4, substr_count($body, 'class="c kpi"'));
        self::assertStringContainsString('People posted', $body);
        self::assertStringContainsString('Leaders appointed', $body);

        self::assertStringContainsString('All stations on the ground', $body);
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('Stationed by station', $body);
        self::assertStringContainsString('Stations by zone', $body);
        self::assertStringContainsString('2 of 2 stations &middot; nothing hidden', $body);

        self::assertStringNotContainsString('<table', $body, 'The Overview lists nothing; the register does.');
        self::assertStringNotContainsString('Stationed people', $body);
    }

    public function testTheOverviewDrawsNoBannerWhenEveryStationIsPosted(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);

        self::assertStringNotContainsString('class="banner', $this->body($this->hub($area)));
    }

    public function testTheRegisterIsTheHouseTableUnderTheHouseBarAndFoot(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();

        $body = $this->body($this->hub($area).'/list');

        self::assertStringContainsString('Every station', $body);
        self::assertStringContainsString('class="lfilt"', $body);
        foreach (['zone', 'posted', 'lead', 'sort'] as $axis) {
            self::assertStringContainsString('data-dd="'.$axis.'"', $body);
        }
        self::assertStringContainsString('class="lsearch"', $body);
        self::assertStringContainsString('<table class="tbl', $body);
        foreach (['Station', 'Code', 'Zone', 'Point', 'Posted', 'Lead'] as $head) {
            self::assertMatchesRegularExpression('#<th[^>]*>'.$head.'</th>#', $body);
        }
        self::assertStringContainsString('class="rdf-foot"', $body);
        self::assertStringContainsString('2 stations', $body);
        self::assertStringContainsString('20</span> per page', $body);
        self::assertStringContainsString('Add a station', $body);
        self::assertMatchesRegularExpression('#<td class="door"><a class="open-btn" href="[^"]+">Open</a></td>#', $body);

        self::assertStringNotContainsString('class="factband', $body, 'A table page carries no strip.');
        self::assertStringNotContainsString('class="c kpi"', $body);
    }

    public function testTheRegisterNamesTheLeadAndTheZone(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();

        $body = $this->body($this->hub($area).'/list');

        self::assertMatchesRegularExpression('#<b>Eastgate Post</b>.*?Western Sector.*?J\. Mollel#s', $body);
        self::assertMatchesRegularExpression('#<b>Eastern Outpost</b>.*?No zone.*?—#s', $body);
    }

    public function testTheRegisterIsFilteredAndSizedByTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();
        $big = $this->anArea('Southern Conservation Reserve');
        for ($i = 1; $i <= 22; ++$i) {
            $this->stations()->add($big, \sprintf('Station %02d', $i), -29.75, -3.2);
        }

        self::assertSame(['Eastern Outpost'], $this->listed($this->hub($area).'/list?posted=no'));
        self::assertSame(['Eastgate Post'], $this->listed($this->hub($area).'/list?lead=yes'));
        self::assertSame(['Eastern Outpost'], $this->listed($this->hub($area).'/list?q=eastern'));

        $area = $big;
        self::assertCount(20, $this->listed($this->hub($area).'/list'));
        self::assertCount(22, $this->listed($this->hub($area).'/list?per=all'));
        self::assertCount(2, $this->listed($this->hub($area).'/list?page=2'));
        self::assertStringContainsString('page <span class="pg">2</span> of 2', $this->body($this->hub($area).'/list?page=2'));
    }

    public function testThePostingsNameTheirStationInAColumn(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();

        $body = $this->body($this->hub($area).'/postings');

        self::assertStringContainsString('Who is posted where', $body);
        foreach (['zone', 'station', 'position'] as $axis) {
            self::assertStringContainsString('data-dd="'.$axis.'"', $body);
        }
        self::assertStringContainsString('class="lsearch"', $body);
        foreach (['Person', 'Position', 'Department'] as $head) {
            self::assertMatchesRegularExpression('#<th[^>]*>'.$head.'</th>#', $body);
        }
        self::assertStringNotContainsString('sxgrp', $body, 'No band rows in a table: the station is a column.');
        self::assertMatchesRegularExpression('#<thead><tr><th>Station</th><th>Person</th>#', $body);
        self::assertMatchesRegularExpression('#<td><span class="chip">Eastgate Post</span><span class="sub">ST-01 · Western Sector</span></td><td><b>J\. Mollel</b> <span class="chip ok">leads</span>#', $body);
        self::assertMatchesRegularExpression('#<td class="door"><a class="open-btn" href="[^"]+">The station</a></td>#', $body);
        self::assertStringContainsString('T. Ndosi', $body);
        self::assertStringNotContainsString('Eastern Outpost', $body, 'A station with nobody posted has no postings to list.');
        self::assertStringContainsString('class="rdf-foot"', $body);
        self::assertStringContainsString('2 people', $body);
    }

    public function testThePostingsAreFilteredByStationAndSearch(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->aStaffedArea();
        $other = $this->stations()->add($area, 'Springs Post', -29.7, -3.1);
        $this->postings()->post($other, $this->aPerson('B.', 'Mwita'), PostingSource::WrittenHere);

        $body = $this->body($this->hub($area).'/postings?station='.$other->getUuidString());
        self::assertStringContainsString('B. Mwita', $body);
        self::assertStringNotContainsString('J. Mollel', $body);

        $found = $this->body($this->hub($area).'/postings?q=ndosi');
        self::assertStringContainsString('T. Ndosi', $found);
        self::assertStringNotContainsString('B. Mwita', $found);
    }

    public function testAViewerWhoMayNotSeeTheAreaIsRefusedEverywhereInTheHub(): void
    {
        $this->boot([]);
        $this->signIn();
        $area = $this->aStaffedArea();

        foreach (['', '/list', '/postings'] as $suffix) {
            $this->browser()->request('GET', $this->hub($area).$suffix);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode(), $suffix);
        }
    }

    // ---------------------------------------------------------------- fixtures

    private function hub(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/stations';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode(), $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /** @return array{labels: list<string>, on: string|null} */
    private function strip(string $body): array
    {
        self::assertSame(1, substr_count($body, 'class="atabs"'), 'One tab strip per page.');
        preg_match('#<div class="atabs">(.*?)</div>#s', $body, $strip);
        preg_match_all('#<a href="[^"]*"( class="on")?>([^<]+)</a>#', $strip[1] ?? '', $tabs, \PREG_SET_ORDER);

        $on = null;
        foreach ($tabs as $tab) {
            if ('' !== $tab[1]) {
                $on = $tab[2];
            }
        }

        return ['labels' => array_map(static fn (array $tab): string => $tab[2], $tabs), 'on' => $on];
    }

    /** @return list<string> */
    private function listed(string $url): array
    {
        preg_match_all('#<tr data-station>\s*<td><b>([^<]+)</b>#', $this->body($url), $found);

        return $found[1];
    }

    private function aStaffedArea(): AreaOfInterest
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T.', 'Ndosi'), PostingSource::FromTheirPage);

        $this->stations()->add($area, 'Eastern Outpost', -29.25, -3.2);

        return $area;
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
