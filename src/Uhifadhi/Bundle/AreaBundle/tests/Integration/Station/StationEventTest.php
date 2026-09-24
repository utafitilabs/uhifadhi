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
use Uhifadhi\Bundle\AreaBundle\Entity\StationEvent;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Enum\StationEventKind;
use Uhifadhi\Bundle\AreaBundle\Repository\StationEventRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationEventService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT HAPPENED AT A POST, IN LINES.
 *
 * EVERY LINE IS SOMETHING SOMEBODY DID — or something the ground did. A post
 * recorded, renamed, moved, closed; somebody posted, somebody's posting
 * ended, somebody put in charge; and the one nobody did: the zone under it
 * changed because an import moved the ring.
 *
 * THE MOVE CARRIES ITS SIZE AND ITS DIRECTION, measured by the database on
 * the spheroid. "The point changed" is a fact nobody can check; "moved 340 m
 * west" is one somebody can walk to.
 *
 * A RE-DERIVATION IS LOGGED WHERE THE ANSWER MOVED, and nowhere else. Every
 * zone write re-asks the question for every station in the area, so logging
 * the unchanged ones would put a line on every post in the area each time
 * anybody edited a zone.
 */
#[CoversClass(StationEvent::class)]
#[CoversClass(StationEventService::class)]
final class StationEventTest extends IntegrationTestCase
{
    private const string AN_EAST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}';

    public function testRecordingAStationOpensItsLog(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2, 'ST-01', 'n.kileo');

        $lines = $this->log($station);
        self::assertCount(1, $lines);
        self::assertSame(StationEventKind::Recorded, $lines[0]->getKind());
        self::assertSame('Station recorded', $lines[0]->getHeadline());
        self::assertSame('by n.kileo', $lines[0]->getDetail());
        self::assertSame('n.kileo', $lines[0]->getActor());
    }

    public function testARenameSaysBothNames(): void
    {
        $station = $this->aStation();

        $this->stations()->rename($station, 'Eastgate Main Gate', 'n.kileo');

        self::assertSame('“Eastgate Post” renamed to “Eastgate Main Gate”', $this->log($station)[0]->getHeadline());
    }

    /** Renaming to the same name is not an event, because nothing happened. */
    public function testRenamingToTheSameNameLogsNothing(): void
    {
        $station = $this->aStation();
        $before = \count($this->log($station));

        $this->stations()->rename($station, 'Eastgate Post');

        self::assertCount($before, $this->log($station));
    }

    public function testAMoveSaysHowFarAndWhichWay(): void
    {
        $station = $this->aStation();

        // A fifth of a degree west along the same parallel.
        $this->stations()->moveTo($station, -29.95, -3.2, 'n.kileo');

        $moved = $this->lineOfKind($station, StationEventKind::PointMoved);
        self::assertNotNull($moved);
        self::assertMatchesRegularExpression('/^Point moved [\d,]+ m west$/', (string) $moved->getHeadline());
    }

    /** A move that does not move is not a move. */
    public function testMovingAStationToWhereItAlreadyIsLogsNoMove(): void
    {
        $station = $this->aStation();

        $this->stations()->moveTo($station, -29.75, -3.2);

        self::assertNull($this->lineOfKind($station, StationEventKind::PointMoved));
    }

    /**
     * THE ONE LINE NOBODY WROTE. An import moves the ring under a post that
     * nobody touched, and the post's own page is where that shows.
     */
    public function testAnImportThatChangesTheZoneWritesALineOnTheStation(): void
    {
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->stations()->rederiveFor($area, 'a zoning scheme was imported');

        $derived = $this->lineOfKind($station, StationEventKind::ZoneDerived);
        self::assertNotNull($derived);
        self::assertSame('Now in West', $derived->getHeadline());
        self::assertSame('a zoning scheme was imported', $derived->getDetail());
        self::assertNull($derived->getActor(), 'the ground did it, not a person');
    }

    public function testAStationThatFallsOutOfEveryZoneSaysSo(): void
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);

        $this->zones()->remove($zone);

        self::assertSame('Now in no zone', $this->lineOfKind($station, StationEventKind::ZoneDerived)?->getHeadline());
    }

    /** A zone write that does not move this station's answer leaves its log alone. */
    public function testAZoneWriteThatChangesNothingHereWritesNoLine(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $station = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2);
        $before = \count($this->log($station));

        // A second zone, nowhere near this post.
        $this->zones()->create($area, 'East', self::AN_EAST_HALF);

        self::assertCount($before, $this->log($station));
    }

    // ------------------------------------------------------- the people lines

    public function testPostingSomebodySaysWhichDoorItCameIn(): void
    {
        $station = $this->aStation();

        $this->postings()->post($station, $this->aPerson('T. Ndosi'), PostingSource::FromTheirPage, null, 'n.kileo');

        $line = $this->lineOfKind($station, StationEventKind::Posted);
        self::assertNotNull($line);
        self::assertSame('T. Ndosi stationed here', $line->getHeadline());
        self::assertSame('by n.kileo · from their own page', $line->getDetail());
    }

    public function testEndingAPostingAndAppointingALeaderEachLeaveALine(): void
    {
        $station = $this->aStation();
        $posting = $this->postings()->post($station, $this->aPerson('J. Mollel'), PostingSource::WrittenHere);

        $this->postings()->appointLeader($posting, 'n.kileo');
        $this->postings()->end($posting, null, 'a.mchome');

        self::assertSame('J. Mollel leads here', $this->lineOfKind($station, StationEventKind::LeaderAppointed)?->getHeadline());
        self::assertSame('J. Mollel’s assignment ended', $this->lineOfKind($station, StationEventKind::PostingEnded)?->getHeadline());
    }

    public function testClosingAndReopeningAPostAreBothLines(): void
    {
        $station = $this->aStation();

        $this->stations()->deactivate($station, actor: 'n.kileo');
        self::assertSame('by n.kileo · nothing was deleted', $this->lineOfKind($station, StationEventKind::Deactivated)?->getDetail());

        $this->stations()->reactivate($station, 'n.kileo');
        self::assertSame('Station reopened', $this->lineOfKind($station, StationEventKind::Reactivated)?->getHeadline());
    }

    /** Closing a post that is already closed is not an event. */
    public function testClosingAClosedPostLogsNothing(): void
    {
        $station = $this->aStation();
        $this->stations()->deactivate($station);
        $before = \count($this->log($station));

        $this->stations()->deactivate($station);

        self::assertCount($before, $this->log($station));
    }

    /** Newest first, because that is how the card reads. */
    public function testTheLogIsNewestFirst(): void
    {
        $station = $this->aStation();
        $this->stations()->rename($station, 'Eastgate Main Gate');

        self::assertSame(StationEventKind::Renamed, $this->log($station)[0]->getKind());
        self::assertSame(StationEventKind::Recorded, $this->log($station)[1]->getKind());
    }

    // ---------------------------------------------------------------- fixtures

    /** @return list<StationEvent> */
    private function log(Station $station): array
    {
        /** @var StationEventRepository $events */
        $events = static::getContainer()->get('test_public.area.station_event_repository');

        return $events->findByStation($station);
    }

    private function lineOfKind(Station $station, StationEventKind $kind): ?StationEvent
    {
        foreach ($this->log($station) as $line) {
            if ($kind === $line->getKind()) {
                return $line;
            }
        }

        return null;
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

    private function zones(): \Uhifadhi\Bundle\AreaBundle\Service\ZoneService
    {
        /** @var \Uhifadhi\Bundle\AreaBundle\Service\ZoneService $service */
        $service = static::getContainer()->get('test_public.area.zones');

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
