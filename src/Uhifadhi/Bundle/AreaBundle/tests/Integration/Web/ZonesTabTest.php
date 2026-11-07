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
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverviewService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE ZONES HUB · OVERVIEW — how an area is divided, stated.
 *
 * Design: areas/<area>/zones/index.html. One strip of its own, Overview ·
 * Zones; the banner names the zones no station stands in; the strip, the four
 * figures, the plate and two plots belong here, and it lists nothing — the
 * register is the hub's other page.
 */
#[CoversClass(ZoneController::class)]
#[CoversClass(ZoneOverviewService::class)]
final class ZonesTabTest extends WebTestCase
{
    public function testTheHubCarriesItsOwnStripInPlaceOfTheAreaTabs(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        foreach (['' => 'Overview', '/list' => 'Zones'] as $suffix => $lit) {
            $body = $this->body($this->tab($area).$suffix);
            self::assertSame(1, substr_count($body, 'class="atabs"'), 'One tab strip per page.');
            preg_match('#<div class="atabs">(.*?)</div>#s', $body, $strip);
            preg_match_all('#<a href="[^"]*"( class="on")?>([^<]+)</a>#', $strip[1] ?? '', $tabs, \PREG_SET_ORDER);
            self::assertSame(['Overview', 'Zones'], array_map(static fn (array $tab): string => $tab[2], $tabs));
            self::assertSame([$lit], array_values(array_map(static fn (array $tab): string => $tab[2], array_filter($tabs, static fn (array $tab): bool => '' !== $tab[1]))));
            self::assertMatchesRegularExpression('#<h1[^>]*>\s*Zones\s*</h1>#', $body);
        }
    }

    public function testTheOverviewStatesTheSetAndListsNothing(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();
        $this->aZone($area, 'Eastern Sector', self::AN_EAST_HALF);

        $body = $this->body($this->tab($area));

        self::assertStringContainsString('<span class="banner-t"><b>1 zone has no station</b> <span class="banner-c">Eastern Sector</span>', $body);
        self::assertStringContainsString('href="'.$this->tab($area).'/list?stations=none"', $body);
        self::assertStringContainsString('class="factband ov-band"', $body);
        foreach (['Zones', 'Zoned', 'Unzoned', 'Stations', 'Imported'] as $field) {
            self::assertStringContainsString('<span class="k">'.$field.'</span>', $body);
        }
        self::assertStringContainsString('2<em>presence-driven</em>', $body);
        self::assertStringContainsString('1<em>0 in no zone</em>', $body);
        self::assertStringContainsString('class="grid kstrip ov-kpis"', $body);
        self::assertSame(4, substr_count($body, 'class="c kpi"'));
        self::assertMatchesRegularExpression('#Zones with a station</span>\s*<b class="disp">1</b>\s*<span class="sub">of 2 zones</span>#', $body);
        self::assertStringContainsString('All zones on the ground', $body);
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('map-legend', $body);
        self::assertStringContainsString('class="grid g2 ov-plots"', $body);
        self::assertStringContainsString('Extent by zone', $body);
        self::assertStringContainsString('Covered in ', $body);
        self::assertStringContainsString('Stations on the area', $body);
        self::assertStringNotContainsString('<table', $body, 'The Overview lists nothing; the register does.');
        self::assertStringNotContainsString('J. Mollel', $body, 'The people posted are the Stations hub\'s.');
    }

    public function testTheOverviewDrawsNoBannerWhenEveryZoneHasAStation(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        self::assertStringNotContainsString('class="banner', $this->body($this->tab($area)));
    }

    /**
     * NO MODULE PUBLISHES ABOUT A ZONE HERE, so the cards that would carry a
     * module's figure say so and keep their slot.
     */
    public function testTheModuleFiguresSayNobodyPublishesRatherThanBeingDropped(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        $body = $this->body($this->tab($area));

        self::assertSame(4, substr_count($body, 'class="c kpi"'));
        self::assertSame(3, substr_count($body, 'no module publishes this'));
    }

    /** An area nobody has zoned says what a zone is, rather than drawing an empty plate. */
    public function testAnAreaWithNoZoneSaysWhatAZoneIs(): void
    {
        $this->boot();
        $this->signIn();

        $body = strtolower($this->body($this->tab($this->anArea())));

        self::assertStringContainsString('no zones yet', $body);
        self::assertStringContainsString('lens', $body);
    }

    public function testAViewerWhoMayNotSeeTheAreaIsRefusedEverywhereInTheHub(): void
    {
        $this->boot([]);
        $this->signIn();
        [$area] = $this->anAreaWithAZonedPost();

        foreach (['', '/list'] as $suffix) {
            $this->browser()->request('GET', $this->tab($area).$suffix);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode(), $suffix);
        }
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
