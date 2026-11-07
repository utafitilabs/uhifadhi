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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneListService;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;

/**
 * THE ZONES HUB · ZONES — every zone of the area, the house table under the
 * house bar (stations or not, the order, the search) and the house foot.
 *
 * Design: areas/<area>/zones/zones.html. The record does not list the zones
 * again: picking one is the zone record.
 */
#[CoversClass(ZoneListService::class)]
final class ZonesTableTest extends WebTestCase
{
    public function testEveryZoneOfTheAreaIsARow(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        self::assertSame(['Crater', 'Highlands', 'Salt Flats'], $this->rowsOf($this->tab($area)));
    }

    /** The row states the ground, and what stands on it. */
    public function testARowStatesTheExtentThePostsAndThePeople(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();
        $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $row = $this->rowHtml($this->tab($area), 'Crater');

        self::assertMatchesRegularExpression('#^<td><b>Crater</b></td><td class="num">[\d,]+</td>#', $row, 'the extent is a number');
        self::assertMatchesRegularExpression('#<td class="num">1</td><td class="num">0</td>#', $row, 'the station standing on it is counted, and nobody posted');
    }

    /**
     * A FIGURE IS PUBLISHED OR IT IS ABSENT. No module answers for a zone in
     * this suite, so Covered says so rather than drawing a nought — and a
     * nought would be a measurement nobody took.
     */
    public function testAFigureNobodyPublishesSaysSoRatherThanReadingNought(): void
    {
        $this->boot();
        $this->signIn();

        $row = $this->rowHtml($this->tab($this->threeZones()), 'Crater');

        self::assertStringContainsString('<td class="num"><span class="mono d" title="no module publishes it">&mdash;</span></td>', $row);
        self::assertStringNotContainsString('0 %', $row);
    }

    /** A zone with no station counts nought stations, as the design writes it. */
    public function testAZoneWithNoPostSaysNone(): void
    {
        $this->boot();
        $this->signIn();

        $row = $this->rowHtml($this->tab($this->threeZones()), 'Highlands');

        self::assertMatchesRegularExpression('#<td class="num">0</td><td class="num">0</td>#', $row);
    }

    public function testTheOrderIsTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        // BY EXTENT, widest first — Crater is the western half of the area.
        self::assertSame(
            ['Crater', 'Highlands', 'Salt Flats'],
            $this->rowsOf($this->tab($area).'?sort=extent'),
        );
        // AND BY POSTS, most first.
        $this->stations()->add($area, 'Highlands Station', -29.2, -3.5);
        self::assertSame('Highlands', $this->rowsOf($this->tab($area).'?sort=stations')[0]);
    }

    public function testTheHasStationsFilterNarrowsTheTable(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();
        $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        self::assertSame(['Crater'], $this->rowsOf($this->tab($area).'?stations=some'));
        self::assertSame(['Highlands', 'Salt Flats'], $this->rowsOf($this->tab($area).'?stations=none'));
    }

    /** The search is by name, under the house key. */
    public function testTheSearchIsByName(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        self::assertSame(['Highlands'], $this->rowsOf($this->tab($area).'?q=high'));
        self::assertStringContainsString('name="q"', $this->body($this->tab($area)));
        self::assertStringContainsString('placeholder="Find a zone by name&hellip;"', $this->body($this->tab($area)));
    }

    public function testNothingMatchingSaysSoAndOffersTheWayBack(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->tab($this->threeZones()).'?q=nowhere');

        self::assertStringContainsString('No zone matches', $body);
        self::assertStringContainsString('Clear the filters', $body);
    }

    /** An area with no zone says what a zone is, and where one comes from. */
    public function testAnAreaWithNoZoneSaysSo(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->tab($this->anArea()));

        self::assertStringContainsString('No zone in this area yet', $body);
    }

    /** Twenty to a page under the house foot, or all of them. */
    public function testTheTablePagesUnderTheHouseFoot(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        for ($n = 1; $n <= 21; ++$n) {
            $this->aZone($area, \sprintf('Zone %02d', $n), self::A_WEST_HALF);
        }

        self::assertCount(20, $this->rowsOf($this->tab($area)));
        self::assertSame(['Zone 21'], $this->rowsOf($this->tab($area).'?page=2'));
        self::assertCount(21, $this->rowsOf($this->tab($area).'?per=all'));
        $body = $this->body($this->tab($area).'?page=2');
        self::assertStringContainsString('class="rdf-foot"', $body);
        self::assertStringContainsString('21 zones', $body);
        self::assertStringContainsString('page <span class="pg">2</span> of 2', $body);
    }

    /** The bar: two house dropdowns and the search; no strip and no figures. */
    public function testTheRegisterIsTheHouseTableUnderTheHouseBar(): void
    {
        $this->boot();
        $this->signIn();
        $body = $this->body($this->tab($this->threeZones()));

        self::assertStringContainsString('Every zone', $body);
        foreach (['stations', 'sort'] as $axis) {
            self::assertStringContainsString('data-dd="'.$axis.'"', $body);
        }
        foreach (['Zone', 'km&sup2;', 'Covered', 'Stations', 'Posted'] as $head) {
            self::assertMatchesRegularExpression('#<th[^>]*>'.$head.'</th>#', $body);
        }
        self::assertMatchesRegularExpression('#<td class="door"><a class="open-btn" href="/areas/[^"]+/zones/[^"]+">Open</a></td>#', $body);
        self::assertStringNotContainsString('class="factband', $body, 'A table page carries no strip.');
        self::assertStringNotContainsString('class="c kpi"', $body);
    }

    /** The record does not list the zones again; the tab is where they are listed. */
    public function testTheCardIsAbsentOnAZonesRecord(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Crater', self::A_WEST_HALF);

        $body = $this->body('/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString());

        self::assertStringNotContainsString('zltbl', $body);
        self::assertStringNotContainsString('Zones in the area', $body);
    }

    /**
     * A MODULE COLUMN PER MODULE THE AREA RUNS, IN THE AREA'S OWN ORDER —
     * the order its Modules tab lists them in, which is the order somebody
     * arranged. Read off the catalogue instead, the columns came out in
     * whichever order the installation happened to hold its modules.
     */
    public function testTheModuleColumnsFollowTheAreasModuleOrder(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        // The catalogue holds them the other way about on purpose.
        $this->aModule('incidents', 'Incidents', 0);
        $this->aModule('patrols', 'Patrols', 1);

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, 'patrols');
        $modules->install($area, 'incidents');

        preg_match_all('#<th class="num">([A-Z][a-z]+)</th>#', $this->body($this->tab($area)), $found);

        self::assertSame(['Patrols', 'Incidents'], array_values(array_diff($found[1], ['Covered', 'Stations', 'Posted'])));
    }

    private function aModule(string $slug, string $name, int $position): void
    {
        $this->em->persist(new Module()
            ->setSlug($slug)
            ->setName($name)
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('the stand-in')
            ->setPosition($position));
        $this->em->flush();
    }

    private function threeZones(): AreaOfInterest
    {
        $area = $this->anArea();
        $this->aZone($area, 'Crater', self::A_WEST_HALF);
        $this->aZone($area, 'Highlands', '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.2,-3.6],[-29.2,-3.2],[-29.5,-3.2],[-29.5,-3.6]]]]}');
        $this->aZone($area, 'Salt Flats', '{"type":"MultiPolygon","coordinates":[[[[-29.2,-3.2],[-29.0,-3.2],[-29.0,-2.8],[-29.2,-2.8],[-29.2,-3.2]]]]}');

        return $area;
    }

    private function tab(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/zones/list';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /**
     * The zones table's rows, by name and in the order it drew them.
     *
     * @return list<string>
     */
    private function rowsOf(string $url): array
    {
        preg_match_all('#<tr data-zone><td><b>([^<]+)</b>#', self::zoneTable($this->body($url)), $found);

        return array_map(trim(...), $found[1]);
    }

    /** One row's markup, so a cell can be read of the zone it belongs to. */
    private function rowHtml(string $url, string $zone): string
    {
        foreach (explode('<tr data-zone>', self::zoneTable($this->body($url))) as $row) {
            if (str_contains($row, '<b>'.$zone.'</b>')) {
                return $row;
            }
        }

        self::fail(\sprintf('The zones table has no row for %s.', $zone));
    }

    private static function zoneTable(string $body): string
    {
        preg_match('#<table class="tbl" data-zones>(.*?)</table>#s', $body, $found);

        return $found[1] ?? '';
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
