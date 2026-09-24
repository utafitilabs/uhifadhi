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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Station;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\People\AreaStationDirectory;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Area\DirectoryArea;
use Uhifadhi\Contracts\Area\PostedStation;

/**
 * THE STATION-SHAPED READ, ACROSS EVERY AREA AT ONCE.
 *
 * A postings board asks "who is at this station", and the person-shaped seam
 * cannot answer it: assembled from people, the list loses every station nobody
 * stands at — which is the one reading the board exists for.
 */
#[CoversClass(AreaStationDirectory::class)]
final class StationDirectoryTest extends IntegrationTestCase
{
    /** Every area's stations in one answer, because a reader counts across all of them. */
    public function testItReadsEveryAreasStationsInOneAnswer(): void
    {
        $kilimani = $this->anArea('Kilimani Crater');
        $olkeju = $this->anArea('Olkeju');
        $this->stations()->add($kilimani, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $this->stations()->add($olkeju, 'Mkwaju Station', -29.5, -3.4, 'ST-02');

        self::assertSame(
            ['Kilimani Crater/ST-01', 'Olkeju/ST-02'],
            array_map(static fn (PostedStation $s): string => $s->areaName.'/'.$s->code, $this->directory()->stations()),
        );
    }

    /** A station nobody stands at is in the answer, empty — never dropped. */
    public function testAStationNobodyStandsAtIsStillInTheAnswer(): void
    {
        $area = $this->anArea();
        $this->stations()->add($area, 'Ridge Outpost', -29.75, -3.2, 'ST-08');

        $stations = $this->directory()->stations();

        self::assertCount(1, $stations);
        self::assertTrue($stations[0]->isEmpty());
    }

    /** The leader stands first, because that is the order a board reads the post in. */
    public function testTheLeaderIsTheFirstPostAtTheStation(): void
    {
        $station = $this->aStation();
        $ranger = $this->aPerson('Tumaini Ndosi');
        $this->postings()->post($station, $ranger, PostingSource::WrittenHere);
        $lead = $this->postings()->post($station, $this->aPerson('Joseph Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);

        $posts = $this->directory()->stations()[0]->posts;

        self::assertTrue($posts[0]->leader);
        self::assertFalse($posts[1]->leader);
        self::assertSame($ranger->getUuidString(), $posts[1]->personUuid);
    }

    /** An ended posting is a history, and a board draws today. */
    public function testAnEndedPostingIsNotOnTheBoard(): void
    {
        $station = $this->aStation();
        $posting = $this->postings()->post($station, $this->aPerson('Joseph Mollel'), PostingSource::WrittenHere);
        $this->postings()->end($posting);

        self::assertTrue($this->directory()->stations()[0]->isEmpty());
    }

    /** A post on ground that belongs to no zone is legal, and says so. */
    public function testAStationOnNoZoneReportsNoZone(): void
    {
        $this->stations()->add($this->anArea(), 'Escarpment Roadside Station', -29.75, -3.2, 'ST-12');

        self::assertNull($this->directory()->stations()[0]->zoneName);
    }

    /** An area with no station is still offered as a choice, reading nought. */
    public function testAnAreaWithNoStationIsStillOffered(): void
    {
        $this->anArea('Kilimani Crater');
        $this->anArea('Sekenke');

        self::assertSame(
            ['Kilimani Crater', 'Sekenke'],
            array_map(static fn (DirectoryArea $a): string => $a->name, $this->directory()->areas()),
        );
    }

    private function directory(): AreaStationDirectory
    {
        /** @var AreaStationDirectory $directory */
        $directory = static::getContainer()->get('test_public.area.station_directory');

        return $directory;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function aStation(): Station
    {
        return $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2, 'ST-01');
    }

    private function aPerson(string $name): HostPerson
    {
        [$first, $last] = explode(' ', $name, 2);
        $person = new HostPerson()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }
}
