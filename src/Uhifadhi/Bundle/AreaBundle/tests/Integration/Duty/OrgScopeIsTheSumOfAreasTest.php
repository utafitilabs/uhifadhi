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
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\HostPerson;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * AN ORGANIZATION FIGURE IS THE SUM OVER THE AREAS — asserted, because it is
 * the rule the whole organization dashboard rests on.
 *
 * EVERY FIGURE IS THE PER-AREA SERVICE WIDENED, NEVER A SECOND AGGREGATE
 * (ruled). Two derivations of one question drift, and the day they disagree
 * nobody can say which is right — so the wide reading walks the same loop as
 * the narrow one and concatenates it, and this suite is what keeps that true
 * rather than merely intended.
 *
 * IT IS NOT A TEST OF ARITHMETIC. Summing two numbers that happened to match
 * would prove nothing; what is asserted is that the organization's answer is
 * exactly the areas' answers, position for position, at one instant.
 */
#[CoversClass(PresenceService::class)]
final class OrgScopeIsTheSumOfAreasTest extends IntegrationTestCase
{
    private const string DAY = '2026-09-19';
    private const string NOW = '2026-09-19T11:42:00+03:00';

    /** THE WIDE ANSWER IS THE NARROW ANSWERS, person for person. */
    public function testTheOrganizationsLivePositionsAreTheAreasOwn(): void
    {
        $north = $this->anAreaReporting('Northern Reserve', 'Asha', 'Mollel');
        $south = $this->anAreaReporting('Southern Reserve', 'Tumaini', 'Ndosi');
        $asOf = new \DateTimeImmutable(self::NOW);

        $organization = $this->presence()->forScope(Scope::organization(), $asOf);
        $perArea = [
            ...$this->presence()->liveIn((string) $north->getUuidString(), $asOf)->positions,
            ...$this->presence()->liveIn((string) $south->getUuidString(), $asOf)->positions,
        ];

        self::assertCount(2, $organization->positions);
        self::assertSame(
            self::names($perArea),
            self::names($organization->positions),
            'The organization is its areas, and nothing else.',
        );
    }

    /** And one area asked through the wide door is that area, unchanged. */
    public function testOneAreaAskedThroughTheWideDoorIsThatAreaAlone(): void
    {
        $north = $this->anAreaReporting('Northern Reserve', 'Asha', 'Mollel');
        $this->anAreaReporting('Southern Reserve', 'Tumaini', 'Ndosi');
        $asOf = new \DateTimeImmutable(self::NOW);

        $scoped = $this->presence()->forScope(Scope::area((string) $north->getUuidString(), 'Northern Reserve'), $asOf);

        self::assertSame(
            self::names($this->presence()->liveIn((string) $north->getUuidString(), $asOf)->positions),
            self::names($scoped->positions),
        );
    }

    /**
     * EVERY POSITION KEEPS ITS OWN AREA'S CLOCK, which is what makes the sum
     * honest: areas ping at different rates, and one interval for all of them
     * would call a slow area's rangers stale beside a fast area's on exactly
     * the same silence — so the organization's stale count would not be the
     * areas' stale counts added up.
     */
    public function testStalenessIsJudgedByEachAreasOwnInterval(): void
    {
        $slow = $this->anAreaReporting('Slow Reserve', 'Asha', 'Mollel', pingMinutes: 60, fixAt: '2026-09-19T10:30:00+03:00');
        $quick = $this->anAreaReporting('Quick Reserve', 'Tumaini', 'Ndosi', pingMinutes: 5, fixAt: '2026-09-19T10:30:00+03:00');
        $asOf = new \DateTimeImmutable(self::NOW);

        // An hour-old fix: fresh where the handset pings hourly, long stale
        // where it pings every five minutes.
        self::assertSame(0, $this->presence()->liveIn((string) $slow->getUuidString(), $asOf)->staleCount());
        self::assertSame(1, $this->presence()->liveIn((string) $quick->getUuidString(), $asOf)->staleCount());

        self::assertSame(
            1,
            $this->presence()->forScope(Scope::organization(), $asOf)->staleCount(),
            'The organization’s stale count is the areas’ stale counts, not a re-judgement of them.',
        );
    }

    /** An installation with no areas answers an empty reading, not a failure. */
    public function testAnOrganizationWithNoAreasIsEmptyRatherThanAFailure(): void
    {
        $live = $this->presence()->forScope(Scope::organization(), new \DateTimeImmutable(self::NOW));

        self::assertSame([], $live->positions);
        self::assertTrue($live->isEmpty());
    }

    /**
     * @param list<LivePosition> $positions
     *
     * @return list<string>
     */
    private static function names(array $positions): array
    {
        $names = array_map(static fn (LivePosition $one): string => $one->personName, $positions);
        sort($names);

        return $names;
    }

    private function anAreaReporting(
        string $name,
        string $first,
        string $last,
        int $pingMinutes = 30,
        string $fixAt = '2026-09-19T11:38:00+03:00',
    ): AreaOfInterest {
        $area = $this->anArea($name);
        $area->setPingIntervalMinutes($pingMinutes);

        $stations = static::getContainer()->get('test_public.area.stations');
        \assert($stations instanceof StationService);
        $station = $stations->add($area, $name.' Gate', -29.75, -3.2);
        $station->setCatchmentM(500);
        $this->em->flush();

        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        \assert($statuses instanceof CheckInStatusService);
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ('at_post' === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $person = new HostPerson()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef(\sprintf('%s-%s', strtolower($last), uniqid()))
            ->setLocalDate(new \DateTimeImmutable(self::DAY))
            ->setStatus($status)
            ->setStation($station)
            ->setOccurredAt(new \DateTimeImmutable('2026-09-19T06:08:12+03:00'))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');
        $this->em->persist($checkIn);

        $ping = new PersonPosition()
            ->setArea($area)
            ->setPerson($person)
            ->setCheckIn($checkIn)
            ->setClientRef('fix-'.uniqid())
            ->setRecordedAt(new \DateTimeImmutable($fixAt))
            ->setPosition('{"type":"Point","coordinates":[-29.7500,-3.2000]}')
            ->setAccuracyM(8.0)
            ->setSource(PositionSourceEnum::Gps);
        $this->em->persist($ping);
        $this->em->flush();
        $this->foldPings($ping);

        return $area;
    }

    private function presence(): PresenceService
    {
        $presence = static::getContainer()->get('test_public.area.presence');
        \assert($presence instanceof PresenceService);

        return $presence;
    }
}
