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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Model\StationCard;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneStationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT A ZONE'S CARD KNOWS ABOUT THE POSTS ON ITS GROUND.
 *
 * NOBODY IS POSTED TO A ZONE. Every number here is reached the long way —
 * the post's point falls inside the zone, and a person is posted to the post
 * — which is what lets a zone be redrawn without anybody's posting changing.
 *
 * THE CARD IS BOUNDED AND THE COUNT IS NOT. A card inside a card must be the
 * same height whether three people stand there or thirty, so the stack draws
 * a fixed number of faces and the line beside it states the whole.
 */
#[CoversClass(ZoneStationService::class)]
#[CoversClass(StationCard::class)]
final class ZoneStationServiceTest extends IntegrationTestCase
{
    public function testTheStaffingCountsThePostsOfEachZoneAndThePeopleAtThem(): void
    {
        $area = $this->anArea();
        $west = $this->aZone($area, 'West', self::A_WEST_HALF);
        $east = $this->aZone($area, 'East', self::A_EAST_HALF);

        $eastgate = $this->aStation($area, 'Eastgate Post', -29.75);
        $this->aStation($area, 'Fig Tree Ranger Station', -29.8);
        $this->aStation($area, 'Munge Camp', -29.25);
        $this->postings()->post($eastgate, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->postings()->post($eastgate, $this->aPerson('T. Ndosi'), PostingSource::FromTheirPage);

        $staffing = $this->service()->staffing($area);

        self::assertSame(2, $staffing->stationsIn((string) $west->getUuidString()));
        self::assertSame(1, $staffing->stationsIn((string) $east->getUuidString()));
        self::assertSame(2, $staffing->peopleIn((string) $west->getUuidString()));
        self::assertSame(0, $staffing->peopleIn((string) $east->getUuidString()));
    }

    /** A post on ground no zone covers belongs to no zone's count, and that is legal. */
    public function testAPostOnUnzonedGroundIsCountedNowhere(): void
    {
        $area = $this->anArea();
        $west = $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aStation($area, 'Eastern Station', -29.25);

        self::assertSame(0, $this->service()->staffing($area)->stationsIn((string) $west->getUuidString()));
    }

    public function testACardNamesThePostItsCodeItsLeadAndHowManyStandThere(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->aStation($area, 'Eastgate Post', -29.75);

        $lead = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);
        $this->postings()->appointLeader($lead);
        $this->postings()->post($station, $this->aPerson('T. Ndosi'), PostingSource::FromTheirPage);

        $cards = $this->service()->cardsFor($zone);

        self::assertCount(1, $cards);
        self::assertSame('Eastgate Post', $cards[0]->name);
        self::assertSame('ST-01', $cards[0]->code);
        self::assertSame(2, $cards[0]->posted);
        self::assertSame('J. Mollel', $cards[0]->leaderName);
        self::assertCount(2, $cards[0]->faces);
        self::assertSame(0, $cards[0]->beyondTheFaces());
    }

    /** A post nobody stands at is a card, not an omission: it is how a post starts. */
    public function testAPostWithNobodyAtItIsStillACard(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aStation($area, 'Munge Camp', -29.75);

        $cards = $this->service()->cardsFor($zone);

        self::assertCount(1, $cards);
        self::assertSame(0, $cards[0]->posted);
        self::assertNull($cards[0]->leaderName);
        self::assertSame([], $cards[0]->faces);
    }

    /** The stack stops; the count does not. */
    public function testTheFacesAreCappedAndTheRestAreCounted(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->aStation($area, 'Eastgate Post', -29.75);

        for ($i = 0; $i < StationCard::FACES + 3; ++$i) {
            $this->postings()->post($station, $this->aPerson('R. Ranger'.$i), PostingSource::WrittenHere);
        }

        $card = $this->service()->cardsFor($zone)[0];

        self::assertSame(StationCard::FACES + 3, $card->posted);
        self::assertCount(StationCard::FACES, $card->faces);
        self::assertSame(3, $card->beyondTheFaces());
    }

    // ---------------------------------------------------------------- fixtures

    private function service(): ZoneStationService
    {
        /** @var ZoneStationService $service */
        $service = static::getContainer()->get('test_public.area.zone_stations');

        return $service;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }

    private function aStation(AreaOfInterest $area, string $name, float $lon): Station
    {
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');

        return $stations->add($area, $name, $lon, -3.2, 'ST-01');
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
