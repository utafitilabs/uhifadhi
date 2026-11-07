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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneRecordController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneEvent;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Enum\ZoneEventKind;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\ChattyFigureProvider;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;

/**
 * ONE ZONE, READ.
 *
 * A RECORD, NOT A TAB. A picked zone wears the station page's treatment: its
 * bare name as the h1, its context demoted to the subline, the crumb one step
 * longer, its own identity band — and no tab strip, because a zone is a thing
 * inside the area rather than one of the ways of looking at the area.
 *
 * ITS FIGURES ARE IN ITS BAND. A record page states them there the way the
 * station page does; there is no card row on a record.
 */
#[CoversClass(ZoneRecordController::class)]
final class ZoneRecordTest extends WebTestCase
{
    use HouseRegisterAssertions;

    public function testTheZoneIsDrawnAsARecordWithItsBandItsGroundAndItsPeople(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();

        $body = $this->body($this->record($area, $zone));

        self::assertStringContainsString('Western Sector', $body);
        self::assertStringContainsString('Extent', $body);
        self::assertStringContainsString('Stations', $body);
        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringContainsString('J. Mollel', $body);
        self::assertStringContainsString('map-plate', $body);
        // A record, so no tab strip and no figure cards.
        self::assertStringNotContainsString('class="atabs"', $body);
        self::assertStringNotContainsString('class="c kpi"', $body);
    }

    /**
     * THE RECORD'S SHAPE (design: areas/<area>/zones/detail.html): the facts
     * strip as its own overview, the index one row a card, the cards in one
     * column, every table the house table under its bar and over its foot,
     * and no band rows — the station is a column of the postings.
     */
    public function testTheRecordIsTheStripTheIndexAndOneColumnOfHouseTables(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();
        $this->em->persist(new ZoneEvent()
            ->setArea($area)
            ->setKind(ZoneEventKind::Renamed)
            ->setHeadline('Renamed to Western Sector')
            ->setDetail('no record moved')
            ->setOccurredAt(new \DateTimeImmutable('2026-09-18 07:42')));
        $this->em->flush();

        $body = $this->body($this->record($area, $zone));

        self::assertStringContainsString('class="factband ov-band"', $body);
        foreach (['Extent', 'Covered', 'Stations', 'Posted'] as $field) {
            self::assertStringContainsString('<span class="k">'.$field.'</span>', $body);
        }
        self::assertStringContainsString('Zone in Northern Conservation Reserve &middot; 1 station, 1 posted', $body);
        preg_match_all('#<a class="onpage-row" href="\#([a-z]+)"[^>]*>.*?<span class="onpage-l">([^<]+)</span>#', $body, $rows);
        self::assertSame(['ground', 'stations', 'posted', 'log'], $rows[1]);
        self::assertSame(['The ground', 'Its stations', 'Who works out of it', 'What happened here'], $rows[2]);
        self::assertStringNotContainsString('recgrid', $body, 'One column: no side column.');
        self::assertSame(3, substr_count($body, '<table class="tbl"'));
        self::assertSame(3, substr_count($body, 'class="rdf-foot"'));
        self::assertStringNotContainsString('sxgrp', $body);
        self::assertHouseBar($body, '#posted', 1);
        self::assertHouseBar($body, '#log', 1);
        foreach (['#stations', '#posted', '#log'] as $card) {
            self::assertHouseTable($body, $card);
        }
        self::assertSame('every change · 1', self::chips($body, '#log')[0]['chip']);
        self::assertSame(['Every change', 'Imported', 'Renamed', 'Boundary replaced', 'Removed', 'Cleared', 'Refused'], array_column(self::chips($body, '#log')[0]['options'], 'label'));
        self::assertHouseFoot($body, '#log', '1 line');
        self::assertSame(['any position · 1'], array_column(self::chips($body, '#posted'), 'chip'), 'One station: no station chip.');

        $renamed = $this->body($this->record($area, $zone).'?lkind=imported');
        self::assertStringContainsString('No line matches', $renamed);
        self::assertSame('imported · 0', self::chips($renamed, '#log')[0]['chip']);
        self::assertMatchesRegularExpression('#<thead><tr><th>Station</th><th>Code</th><th>Point</th><th class="num">Posted</th><th>Lead</th><th></th></tr></thead>#', $body);
        self::assertMatchesRegularExpression('#<thead><tr><th>Station</th><th>Person</th><th>Position</th><th>Department</th><th></th></tr></thead>#', $body);
        self::assertMatchesRegularExpression('#<td><span class="chip">Eastgate Post</span></td><td><b>J\. Mollel</b> <span class="chip ok">leads</span>#', $body);
        self::assertMatchesRegularExpression('#<thead><tr><th>What happened</th><th>When</th></tr></thead>#', $body);
        self::assertStringContainsString('--map-plate-height:min(58vh, 560px)', $body);
    }

    /** The way back is the set it belongs to. */
    public function testTheBandLeadsBackToTheWholeSet(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();

        self::assertStringContainsString(
            '/areas/'.$area->getUuidString().'/zones/list">Every zone &rarr;</a>',
            $this->body($this->record($area, $zone)),
        );
    }

    /**
     * NO MODULE PUBLISHES ABOUT THIS GROUND, and the band says so in two
     * words rather than a sentence: a qualifier long enough to wrap turns a
     * one-line band into two, and six of them turned it into three.
     */
    public function testTheModuleFactsSayNobodyPublishesThem(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();

        $body = $this->body($this->record($area, $zone));

        self::assertStringContainsString('<em>no module</em>', $body);
        self::assertStringNotContainsString('no module publishes this', $body);
    }

    /**
     * THE BAND IS A LINE, NOT A LIST — one fact a module, and the
     * module's own name on it.
     *
     * THE DEFECT: every figure every module published went in, so two
     * modules drew twelve facts wrapping onto four rows, each captioned
     * with a sentence. An identity band a reader scans four rows of is
     * not an identity band.
     */
    public function testTheBandTakesOneFactAModuleAndStaysOnOneLine(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aZoneWithAModuleOn();

        $band = $this->page($this->record($area, $zone))->filter('.factband')->first();

        // Extent · Covered · Stations · People · one a module, then the
        // spacer and the way back to the set.
        self::assertLessThanOrEqual(7, $band->children()->count());

        $labels = $band->filter('.f .k')->each(static fn (Crawler $c): string => $c->text());
        self::assertContains('Incidents', $labels, 'a module fact wears the MODULE\'s name');
        self::assertNotContains('Recorded', $labels, 'and not the figure\'s own label');
        self::assertNotContains('Open', $labels, 'the module\'s other three figures stay on the all-zones page');
    }

    /**
     * AND THE UNIT IS THE PERIOD, never the module's caption: a caption
     * is a sentence, and a sentence in a band is what made it four rows
     * tall.
     */
    public function testEachModuleFactIsCaptionedWithThePeriodAndNotASentence(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aZoneWithAModuleOn();

        $units = $this->page($this->record($area, $zone))->filter('.factband .f[data-module] .v em')
            ->each(static fn (Crawler $c): string => trim($c->text()));

        foreach ($units as $unit) {
            self::assertLessThanOrEqual(
                12,
                mb_strlen($unit),
                \sprintf('"%s" is a sentence; a band carries one short word under a figure.', $unit),
            );
        }
    }

    /** One door a module in the header, however many figures it publishes. */
    public function testTheHeaderOffersOneDoorAModule(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aZoneWithAModuleOn();

        $doors = $this->page($this->record($area, $zone))->filter('.pgact a.w-act')
            ->each(static fn (Crawler $c): string => trim($c->text()));

        self::assertSame(\count($doors), \count(array_unique($doors)), 'a module is offered once, not once per figure');
    }

    /** A zone with no post on it is an ordinary state and says which. */
    public function testAZoneWithNoPostSaysSo(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        // The eastern half: ground with no post standing on it.
        $zone = $this->aZone($area, 'Eastern Sector', '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}');

        $body = $this->body($this->record($area, $zone));

        self::assertStringContainsString('No station stands in Eastern Sector', $body);
        self::assertStringContainsString('Nobody works out of Eastern Sector', $body);
    }

    /** The stations card searches, and the search is in the address. */
    public function testTheStationsCardSearchesWithinTheZone(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();
        $this->stations()->add($area, 'Fig Tree Ranger Station', -29.8, -3.2);

        self::assertSame(['Eastgate Post'], $this->listed($this->record($area, $zone).'?q=eastgate'));
        self::assertCount(2, $this->listed($this->record($area, $zone)));
    }

    /** A zone of another area is not this page's subject. */
    public function testAZoneOfAnotherAreaIsRefused(): void
    {
        $this->boot();
        $this->signIn();
        [, $zone] = $this->aWorkedZone();
        $other = $this->anArea('Second Reserve');

        $this->browser()->request('GET', '/areas/'.$other->getUuidString().'/zones/'.$zone->getUuidString());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    public function testAViewerWhoMayNotSeeTheAreaIsRefused(): void
    {
        $this->boot([]);
        $this->signIn();
        [$area, $zone] = $this->aWorkedZone();

        $this->browser()->request('GET', $this->record($area, $zone));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    // ---------------------------------------------------------------- fixtures

    private function record(AreaOfInterest $area, Zone $zone): string
    {
        return '/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString();
    }

    /** The rendered page, as a crawler — what a band's shape is measured on. */
    private function page(string $url): Crawler
    {
        return new Crawler($this->body($url), $url);
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /** @return list<string> */
    private function listed(string $url): array
    {
        preg_match_all('#<tr data-station><td><b>([^<]+)</b>#', $this->body($url), $found);

        return array_map(trim(...), $found[1]);
    }

    /** @return array{0: AreaOfInterest, 1: Zone} */
    /**
     * The same zone, on an area that RUNS a module — a provider is asked
     * only where its module is switched on, so a band with module facts
     * in it needs the ledger to say so.
     *
     * @return array{AreaOfInterest, Zone}
     */
    private function aZoneWithAModuleOn(): array
    {
        [$area, $zone] = $this->aWorkedZone();

        $this->em->persist(new Module()
            ->setSlug(ChattyFigureProvider::SLUG)
            ->setName('Incidents')
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('field reports')
            ->setPosition(0));
        $this->em->flush();

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, ChattyFigureProvider::SLUG);

        return [$area, $zone];
    }

    /**
     * A zone with a post on it and somebody standing there.
     *
     * @return array{AreaOfInterest, Zone}
     */
    private function aWorkedZone(): array
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $lead = $this->postings()->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);

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
