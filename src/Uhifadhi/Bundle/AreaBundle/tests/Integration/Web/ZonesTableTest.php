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
 * THE AREA'S ZONES, LISTED — owner-ruled: the zones tab lists every zone the
 * way it lists every station.
 *
 * THE STATIONS TABLE'S TWIN, which is the whole point: the same card, the
 * same grouped dropdowns, the same search and the same pager, so a reader
 * who has learnt one has learnt the other. Two tables of the same shape
 * written twice are two tables that drift.
 *
 * THE PICKER IS STILL THE SIDEBAR. This lists; picking one zone is the zone
 * record, and the record does not list them again — a page that both lists
 * and is one of the things listed reads as two pages.
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

        self::assertMatchesRegularExpression('#<td class="num"><b>[\d,]+</b></td>#', $row, 'the extent is a number');
        self::assertStringContainsString('<b>1</b>', $row, 'the station standing on it is counted');
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

        self::assertStringContainsString('no KPI yet', $row);
        self::assertStringNotContainsString('<b>0</b>%', $row);
    }

    /** A zone nobody is posted to says "none" rather than nought. */
    public function testAZoneWithNoPostSaysNone(): void
    {
        $this->boot();
        $this->signIn();

        $row = $this->rowHtml($this->tab($this->threeZones()), 'Highlands');

        self::assertStringContainsString('<em class="pnone">none</em>', $row);
    }

    public function testTheOrderIsTheAddress(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        // BY EXTENT, widest first — Crater is the western half of the area.
        self::assertSame(
            ['Crater', 'Highlands', 'Salt Flats'],
            $this->rowsOf($this->tab($area).'?order=extent'),
        );
        // AND BY POSTS, most first.
        $this->stations()->add($area, 'Highlands Station', -29.2, -3.5);
        self::assertSame('Highlands', $this->rowsOf($this->tab($area).'?order=stations')[0]);
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

    /** Its own search key: three lists on one page, and only one may own `q`. */
    public function testTheSearchIsByNameAndOnItsOwnKey(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->threeZones();

        self::assertSame(['Highlands'], $this->rowsOf($this->tab($area).'?zq=high'));
        // The stations card's own search is untouched by it.
        self::assertStringContainsString('name="zq"', $this->body($this->tab($area)));
    }

    public function testNothingMatchingSaysSoAndOffersTheWayBack(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->tab($this->threeZones()).'?zq=nowhere');

        self::assertStringContainsString('No zone matches', $body);
        self::assertStringContainsString('clear the search, or widen a filter', $body);
    }

    /** An area with no zone says what a zone is, and where one comes from. */
    public function testAnAreaWithNoZoneSaysSo(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->tab($this->anArea()));

        self::assertStringContainsString('No zone imported yet', $body);
    }

    /** Ten to a page, and the pager says what out of. */
    public function testTheTablePagesTenAtATime(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        for ($n = 1; $n <= 11; ++$n) {
            $this->aZone($area, \sprintf('Zone %02d', $n), self::A_WEST_HALF);
        }

        $first = $this->rowsOf($this->tab($area));
        $second = $this->rowsOf($this->tab($area).'?zpage=2');

        self::assertCount(10, $first);
        self::assertSame(['Zone 11'], $second);
        self::assertStringContainsString('1&ndash;10 of 11 zones', $this->body($this->tab($area)));
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

        preg_match_all('#<th class="num">([A-Z][a-z]+) [a-z]{3}</th>#', $this->body($this->tab($area)), $found);

        self::assertSame(['Patrols', 'Incidents'], $found[1]);
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
        return '/areas/'.$area->getUuidString().'/zones';
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
        preg_match_all('#<td class="zname">.*?<b>([^<]+)</b>#s', self::zoneTable($this->body($url)), $found);

        return array_map(trim(...), $found[1]);
    }

    /** One row's markup, so a cell can be read of the zone it belongs to. */
    private function rowHtml(string $url, string $zone): string
    {
        foreach (explode('<tr>', self::zoneTable($this->body($url))) as $row) {
            if (str_contains($row, '<b>'.$zone.'</b>')) {
                return $row;
            }
        }

        self::fail(\sprintf('The zones table has no row for %s.', $zone));
    }

    private static function zoneTable(string $body): string
    {
        preg_match('#<table class="tbl zdtbl zltbl">(.*?)</table>#s', $body, $found);

        return $found[1] ?? '';
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
