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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeStationDirectory;
use Uhifadhi\Contracts\Area\DirectoryArea;
use Uhifadhi\Contracts\Area\PostedStation;
use Uhifadhi\Contracts\Area\StationPost;

/**
 * THE ASSIGNMENTS TAB — who is stationed where, across every area, read-only.
 *
 * THE JOIN IS THE POINT. The ground publishes stations and the uuids standing
 * at them; this bundle says who those uuids are, what rank they hold and which
 * department the rank belongs to. Neither half can draw the page alone, and
 * both halves are asserted here.
 */
final class TeamPostingsTest extends WebTestCaseWithSchema
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeStationDirectory::clear();
    }

    protected function tearDown(): void
    {
        FakeStationDirectory::clear();
        parent::tearDown();
    }

    /** One band a station, and the person's rank and department beside them. */
    public function testEachStationIsABandAndItsPeopleAreTheRowsUnderIt(): void
    {
        $this->ground();
        $crawler = $this->visit('/team/assignments');

        self::assertSame(
            ['Eastgate Post · ST-01 · Crater · 2 stationed', 'Ridge Outpost · ST-08 · Ridge · nobody stationed'],
            $crawler->filter('tr.sxgrp td')->each(static fn (Crawler $c): string => trim(preg_replace('/\s+/', ' ', $c->text()) ?? '')),
        );

        $first = $crawler->filter('table.tbl tbody tr')->eq(1);
        self::assertStringContainsString('Joseph Mollel', $first->text());
        self::assertStringContainsString('Sergeant', $first->text());
        self::assertStringContainsString('Protection Service', $first->text());
    }

    /** The one who leads says so, and nobody else does. */
    public function testTheLeaderWearsTheChipAndNobodyElseDoes(): void
    {
        $this->ground();

        self::assertSame(
            ['leads'],
            $this->visit('/team/assignments')->filter('table.tbl .chip')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /**
     * A STATION NOBODY STANDS AT KEEPS ITS BAND and says so in one line.
     * Dropping it would hide the very thing the board's own figure counts.
     */
    public function testAStationWithNobodyKeepsItsBandAndSaysSo(): void
    {
        $this->ground();

        self::assertStringContainsString(
            'Nobody is stationed here',
            $this->visit('/team/assignments')->filter('table.tbl tbody')->text(),
        );
    }

    /** The five facts, of the whole installation and not of the filtered view. */
    public function testTheBandStatesTheFiveFacts(): void
    {
        $this->ground();

        self::assertSame(
            ['Assignments', 'Areas', 'Stations', 'Leaders', 'Departments'],
            $this->visit('/team/assignments')->filter('.factband .f .k')->each(static fn (Crawler $c): string => $c->text()),
        );
    }

    /**
     * AN AREA WITH NO STATION IS STILL OFFERED, reading nought: that an
     * installation has an area nobody has built out is the reading the board
     * is open for.
     */
    public function testEveryAreaIsOfferedIncludingTheOneWithNoStation(): void
    {
        $this->ground();

        $options = $this->visit('/team/assignments')->filter('.lfilt details')->eq(0)->filter('.i-ddopt');

        self::assertSame(
            ['all areas', 'Kilimani Crater', 'Sekenke'],
            $options->each(static fn (Crawler $c): string => trim($c->filter('.i-ddopt-l')->text())),
        );
        self::assertSame('0', $options->last()->filter('.i-ddopt-n')->text());
    }

    /** Picking a rank keeps the people who hold it and drops the rest. */
    public function testFilteringByRankKeepsOnlyThePeopleWhoHoldIt(): void
    {
        $this->ground();

        $rows = $this->visit('/team/assignments?rank=Ranger')->filter('table.tbl tbody tr:not(.sxgrp)');

        self::assertCount(1, $rows);
        self::assertStringContainsString('Tumaini Ndosi', $rows->text());
    }

    /** The search reads a person's name and a station's alike. */
    public function testTheSearchFindsAPersonAndAStation(): void
    {
        $this->ground();

        self::assertStringContainsString(
            'Joseph Mollel',
            $this->visit('/team/assignments?q=mollel')->filter('table.tbl tbody')->text(),
        );
        self::assertStringContainsString(
            'Ridge',
            $this->visit('/team/assignments?q=ridge')->filter('table.tbl tbody')->text(),
        );
    }

    /** Asking for the empty stations leaves the staffed ones out. */
    public function testAskingForTheEmptyStationsLeavesTheStaffedOnesOut(): void
    {
        $this->ground();

        self::assertSame(
            ['Ridge Outpost · ST-08 · Ridge · nobody stationed'],
            $this->visit('/team/assignments?posted=no')->filter('tr.sxgrp td')
                ->each(static fn (Crawler $c): string => trim(preg_replace('/\s+/', ' ', $c->text()) ?? '')),
        );
    }

    /**
     * AN INSTALLATION WITH NO GROUND PACKAGE IS A REAL INSTALLATION. The board
     * says the installation has no station rather than failing, which is what
     * makes the seam optional the way every other one is.
     */
    public function testAnInstallationWithNoStationSaysSo(): void
    {
        $this->administrator();

        self::assertStringContainsString(
            'No station has been recorded on this installation yet.',
            $this->visit('/team/assignments')->filter('table.tbl tbody')->text(),
        );
    }

    /** THE BOARD WRITES NOTHING: a posting is made on the station, in the area. */
    /**
     * EVERY HEADER SORTS ITS ONE COLUMN, within each station's band: the
     * headers are links, the sorted one says so, and the default order
     * stands the leader first.
     */
    public function testEveryHeaderSortsItsColumnWithinTheBand(): void
    {
        $this->ground();

        $crawler = $this->visit('/team/assignments');
        self::assertSame(['Person', 'Rank', 'Department'], $crawler->filter('thead th a')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Person', $crawler->filter('th.sorted a')->text());
        self::assertSame(['Joseph Mollel', 'Tumaini Ndosi'], $this->namesUnder($crawler));

        $byName = $this->visit('/team/assignments?sort=person&dir=desc');
        self::assertSame(['Tumaini Ndosi', 'Joseph Mollel'], $this->namesUnder($byName));
        self::assertSame('descending', $byName->filter('th.sorted')->attr('aria-sort'));

        $byRank = $this->visit('/team/assignments?sort=rank');
        self::assertSame('Rank', $byRank->filter('th.sorted a')->text());
        self::assertStringContainsString('dir=desc', (string) $byRank->filter('th.sorted a')->attr('href'));
    }

    /** @return list<string> */
    private function namesUnder(Crawler $crawler): array
    {
        return $crawler->filter('table.tbl tbody tr:not(.sxgrp) td:first-child a')->each(static fn (Crawler $c): string => $c->text());
    }

    public function testTheBoardCarriesNoForm(): void
    {
        $this->ground();
        $crawler = $this->visit('/team/assignments');

        self::assertCount(0, $crawler->filter('form[method="post"]'));
        self::assertStringContainsString('Team reads it, never writes it.', $crawler->filter('.sxfoot')->text());
    }

    /**
     * The ground, and the people standing on it: two at Eastgate — one leading —
     * and a station with nobody, in an installation whose second area has no
     * station at all.
     */
    private function ground(): void
    {
        $protection = $this->department('Protection Service');
        $sergeant = $this->position('Sergeant');
        $ranger = $this->position('Ranger');

        $joseph = $this->person('Joseph', 'Mollel')->setPosition($sergeant);
        $tumaini = $this->person('Tumaini', 'Ndosi')->setPosition($ranger);
        // THE DEPARTMENT BESIDE A NAME IS READ OFF THE PLACEMENT. It used to
        // arrive through the position; the ruling put it on the person, so
        // the board only has one to print once somebody has been placed.
        $this->place($joseph, null, [$protection]);
        $this->place($tumaini, null, [$protection]);
        $this->administrator();

        FakeStationDirectory::$areas = [
            new DirectoryArea('area-kilimani', 'Kilimani Crater'),
            new DirectoryArea('area-sekenke', 'Sekenke'),
        ];
        FakeStationDirectory::$stations = [
            new PostedStation(
                uuid: 'st-01',
                name: 'Eastgate Post',
                code: 'ST-01',
                areaUuid: 'area-kilimani',
                areaName: 'Kilimani Crater',
                zoneName: 'Crater',
                posts: [
                    new StationPost(self::uuid($joseph), new \DateTimeImmutable('2026-01-04'), true),
                    new StationPost(self::uuid($tumaini), new \DateTimeImmutable('2026-02-11')),
                ],
            ),
            new PostedStation(
                uuid: 'st-08',
                name: 'Ridge Outpost',
                code: 'ST-08',
                areaUuid: 'area-kilimani',
                areaName: 'Kilimani Crater',
                zoneName: 'Ridge',
            ),
        ];
    }

    private static function uuid(User $person): string
    {
        return (string) $person->getUuidString();
    }

    private function visit(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
