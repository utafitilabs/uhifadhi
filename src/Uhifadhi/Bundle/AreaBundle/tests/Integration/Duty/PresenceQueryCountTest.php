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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Duty;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\People\AreaPeopleStatus;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * WHAT A PRESENCE READ COSTS DOES NOT GROW WITH THE PINGS, AND A PING DOES NOT
 * READ THE AREA.
 *
 * Each read is counted twice: once over a small ground and once over a ground
 * with four times the people and ten times the pings. The live reads, the day
 * board and the People register's Status facet read the day's check-in rows —
 * a fixed number of statements, whatever the headcount — and the frame a ping
 * publishes reads the one ranger's row. The person's own day reads that
 * person alone.
 */
#[CoversClass(PresenceService::class)]
#[CoversClass(PresencePublisher::class)]
#[CoversClass(AreaPeopleStatus::class)]
final class PresenceQueryCountTest extends DutyWritesTestCase
{
    /** Reading one area live: the same statements for 3 people as for 12, for 2 pings each as for 20. */
    public function testALiveReadCostsTheSameWhateverTheHeadcountAndThePings(): void
    {
        $small = $this->statementsFor(people: 3, pings: 2, read: fn (AreaOfInterest $area) => $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::NOW)));
        $large = $this->statementsFor(people: 12, pings: 20, read: fn (AreaOfInterest $area) => $this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::NOW)));

        self::assertSame($small, $large);
        self::assertLessThanOrEqual(4, $large);
    }

    public function testTheOrganizationsLiveReadCostsTheSameWhateverTheHeadcount(): void
    {
        $small = $this->statementsFor(people: 3, pings: 2, read: fn () => $this->presence()->forScope(Scope::organization(), new \DateTimeImmutable(self::NOW)));
        $large = $this->statementsFor(people: 12, pings: 20, read: fn () => $this->presence()->forScope(Scope::organization(), new \DateTimeImmutable(self::NOW)));

        self::assertSame($small, $large);
    }

    public function testTheDayBoardCostsTheSameWhateverTheHeadcountAndThePings(): void
    {
        $small = $this->statementsFor(people: 3, pings: 2, read: fn (AreaOfInterest $area) => $this->presence()->dayIn((string) $area->getUuidString(), self::DAY));
        $large = $this->statementsFor(people: 12, pings: 20, read: fn (AreaOfInterest $area) => $this->presence()->dayIn((string) $area->getUuidString(), self::DAY));

        self::assertSame($small, $large);
        self::assertLessThanOrEqual(4, $large);
    }

    /** One person's day reads that person, and costs the same with one colleague or eleven. */
    public function testOnePersonsDayReadsThatPersonAlone(): void
    {
        $small = $this->statementsFor(people: 2, pings: 2, read: fn (AreaOfInterest $area, string $person) => $this->presence()->dayFor((string) $area->getUuidString(), $person, self::DAY));
        $large = $this->statementsFor(people: 12, pings: 20, read: fn (AreaOfInterest $area, string $person) => $this->presence()->dayFor((string) $area->getUuidString(), $person, self::DAY));

        self::assertSame($small, $large);
    }

    public function testTheStatusFacetCostsTheSameWhateverTheHeadcount(): void
    {
        $facet = static::getContainer()->get('test_public.area.people_status');
        self::assertInstanceOf(AreaPeopleStatus::class, $facet);

        $small = $this->statementsFor(people: 3, pings: 2, read: static fn (AreaOfInterest $area, string $person) => $facet->facetFor([$person]));
        $large = $this->statementsFor(people: 12, pings: 20, read: static fn (AreaOfInterest $area, string $person) => $facet->facetFor([$person]));

        self::assertSame($small, $large);
    }

    /**
     * THE FRAME A PING PUBLISHES IS ONE RANGER'S ROW. It costs the same on a
     * ground of three as on a ground of twelve, and never reads a ping.
     */
    public function testThePublishedFrameReadsOneRowAndNoPing(): void
    {
        $small = $this->statementsFor(people: 3, pings: 2, read: fn (AreaOfInterest $area, string $person) => $this->publisher()->publish((string) $area->getUuidString(), $person));
        $statements = $this->statements();
        $large = $this->statementsFor(people: 12, pings: 20, read: fn (AreaOfInterest $area, string $person) => $this->publisher()->publish((string) $area->getUuidString(), $person));

        self::assertSame($small, $large);
        self::assertLessThanOrEqual(4, $large);
        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'duty_position'))), 'the frame never reads the pings');
        self::assertCount(1, $this->updates, 'and the publish put one frame on the wire');
    }

    /**
     * A PING BATCH COSTS THE SAME ON A BUSY GROUND AS ON A QUIET ONE — its own
     * inserts, its own row, its own frame.
     */
    public function testAPingBatchCostsTheSameWhateverTheHeadcountAndThePingsBeforeIt(): void
    {
        $batch = fn (AreaOfInterest $area, string $person, string $claim) => $this->service()->ping($area, $this->personNamed($person), ['positions' => [
            $this->pingBody('late-1', $claim, '2026-09-19T11:40:00+03:00', lat: -3.2, lon: -29.75),
            $this->pingBody('late-2', $claim, '2026-09-19T11:41:00+03:00', lat: -3.2, lon: -29.7501),
        ]]);

        $small = $this->statementsFor(people: 3, pings: 2, read: $batch);
        $large = $this->statementsFor(people: 12, pings: 20, read: $batch);

        self::assertSame($small, $large);
    }

    /**
     * Seed a ground, then count the statements one call makes.
     *
     * @param \Closure(AreaOfInterest, string, string): mixed $read called with the area, the first person's uuid and their claim reference
     */
    private function statementsFor(int $people, int $pings, \Closure $read): int
    {
        $this->resetGround();
        [$area, $eastgate] = $this->ground();
        $service = $this->service();

        $first = null;
        for ($p = 0; $p < $people; ++$p) {
            $person = $this->aPerson('Ranger', 'No '.$p);
            $claim = 'claim-'.$p;
            $service->claim($area, $person, $this->claimBody($claim, $eastgate, '2026-09-19T06:0'.($p % 10).':00+03:00', lat: -3.2, lon: -29.75));
            $rows = [];
            for ($i = 0; $i < $pings; ++$i) {
                $rows[] = $this->pingBody('ping-'.$p.'-'.$i, $claim, \sprintf('2026-09-19T%02d:%02d:00+03:00', 7 + intdiv($i, 60), $i % 60), lat: -3.2 - $i / 10000, lon: -29.75);
            }
            $service->ping($area, $person, ['positions' => $rows]);
            $first ??= [(string) $person->getUuidString(), $claim];
        }
        self::assertNotNull($first);

        $this->em->clear();
        $area = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $area);

        $this->startCounting();
        $this->updates = [];
        $read($area, $first[0], $first[1]);

        return $this->queryCount();
    }

    private function personNamed(string $uuid): HostPerson
    {
        $person = $this->em->getRepository(HostPerson::class)->findOneBy(['uuid' => $uuid]);
        self::assertNotNull($person);

        return $person;
    }

    /** Two grounds in one test: the second starts from the empty schema the first did. */
    private function resetGround(): void
    {
        $this->em->clear();
        $this->em->getConnection()->executeStatement(
            'TRUNCATE duty_position, duty_checkin_correction, duty_checkin, duty_checkin_status, station, zone, area_of_interest, fixture_host_person RESTART IDENTITY CASCADE',
        );
    }
}
