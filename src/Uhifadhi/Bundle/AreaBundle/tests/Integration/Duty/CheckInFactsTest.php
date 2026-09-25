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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Bundle\AreaBundle\Command\PresenceRebuildCommand;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\UnverifiedReason;

/**
 * THE PING WRITES ITS RANGER'S OWN ROW — the facts a board reads, stored with
 * the ping that changed them.
 *
 * A batch of pings updates one check-in row: how many pings, the first and
 * the last, the last fix and how far it lies from the watch's post, the
 * nearest any fix came to that post, the zone the last fix falls in and the
 * nearest post to it. In the same transaction as the pings, so a reader never
 * sees one without the other; by folding the batch into the row, so the cost
 * of a ping does not grow with the pings before it.
 *
 * JUDGEMENTS ARE NOT FACTS. Nothing here stores "verified": a ring widened
 * next month re-judges the same rows.
 */
#[CoversClass(CheckInService::class)]
#[CoversClass(PresenceFactsService::class)]
#[CoversClass(PresenceRebuildCommand::class)]
final class CheckInFactsTest extends DutyWritesTestCase
{
    private const string CLAIM = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    public function testAPingBatchWritesTheWatchsFactsOntoItsRow(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate, lat: -3.2003, lon: -29.7503));

        // Out of order, as a batch drained after an offline stretch arrives.
        $service->ping($area, $person, ['positions' => [
            $this->pingBody('p-2', self::CLAIM, '2026-09-19T06:50:00+03:00', lat: -3.2010, lon: -29.7400, battery: 64),
            $this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2000, lon: -29.7500, battery: 70),
        ]]);

        $facts = $this->rowFacts(self::CLAIM);
        self::assertSame(2, $facts['ping_count'], 'the claim\'s own fix is a fix, not a ping');
        self::assertSame('2026-09-19T03:10:00', $facts['first_ping_at']);
        self::assertSame('2026-09-19T03:50:00', $facts['last_ping_at']);
        self::assertEqualsWithDelta(-29.74, $facts['lon'], 1e-9);
        self::assertEqualsWithDelta(-3.201, $facts['lat'], 1e-9);
        self::assertSame('2026-09-19T03:50:00', $facts['last_fix_at']);
        self::assertSame(8.0, $facts['last_fix_accuracy_m']);
        self::assertSame(64, $facts['last_fix_battery_pct']);
        self::assertEqualsWithDelta($this->metres(-29.74, -3.201, -29.75, -3.2), $facts['last_fix_m'], 1e-6);
        self::assertEqualsWithDelta(0.0, $facts['closest_m'], 1e-6, 'the nearest fix of the watch stood on the post');
        self::assertSame('West', $facts['zone']);
        self::assertSame('Eastgate Post', $facts['nearest_station']);
    }

    public function testThePingsAndTheirFactsCommitInOneTransaction(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));

        $this->startCounting();
        $service->ping($area, $person, ['positions' => [
            $this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2, lon: -29.75),
        ]]);

        $statements = $this->statements();
        $begin = array_search('"START TRANSACTION"', $statements, true);
        $commit = array_search('"COMMIT"', $statements, true);
        $insert = self::firstIndex($statements, 'INSERT INTO duty_position');
        $update = self::firstIndex($statements, 'UPDATE duty_checkin');

        self::assertIsInt($begin);
        self::assertIsInt($commit);
        self::assertSame(1, \count(array_keys($statements, '"START TRANSACTION"', true)), 'one transaction');
        self::assertTrue($begin < $insert && $insert < $update && $update < $commit, 'the ping and the row it moves are written together: '.implode(' | ', $statements));
    }

    /**
     * A BATCH THAT ARRIVES LATE DOES NOT MOVE THE PHONE BACKWARDS. The count
     * and the first ping take it in; the last fix stays the newest one.
     */
    public function testALateBatchCountsButDoesNotMoveTheLastFixBack(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $service->ping($area, $person, ['positions' => [$this->pingBody('p-2', self::CLAIM, '2026-09-19T07:00:00+03:00', lat: -3.2, lon: -29.74)]]);

        $service->ping($area, $person, ['positions' => [$this->pingBody('p-1', self::CLAIM, '2026-09-19T06:30:00+03:00', lat: -3.2, lon: -29.7501)]]);

        $facts = $this->rowFacts(self::CLAIM);
        self::assertSame(2, $facts['ping_count']);
        self::assertSame('2026-09-19T03:30:00', $facts['first_ping_at']);
        self::assertSame('2026-09-19T04:00:00', $facts['last_fix_at']);
        self::assertEqualsWithDelta(-29.74, $facts['lon'], 1e-9);
        self::assertEqualsWithDelta($this->metres(-29.7501, -3.2, -29.75, -3.2), $facts['closest_m'], 1e-6, 'but the late one came closest to the post');
    }

    /** A batch the area already holds stores nothing and counts nothing. */
    public function testARepeatedBatchCountsOnce(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $batch = ['positions' => [$this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2, lon: -29.75)]];

        $service->ping($area, $person, $batch);
        $service->ping($area, $person, $batch);

        self::assertSame(1, $this->rowFacts(self::CLAIM)['ping_count']);
    }

    /** A position back-filled onto a claim that had none becomes its fix. */
    public function testABackFilledPositionIsTheClaimsFix(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        [$checkIn] = $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        self::assertNull($this->rowFacts(self::CLAIM)['last_fix_at']);

        $service->amend($checkIn, ['lat' => -3.2002, 'lon' => -29.7502, 'accuracyM' => 12.0]);

        $facts = $this->rowFacts(self::CLAIM);
        self::assertSame(0, $facts['ping_count']);
        self::assertEqualsWithDelta(-29.7502, $facts['lon'], 1e-9);
        self::assertSame(12.0, $facts['last_fix_accuracy_m']);
        self::assertNull($facts['last_fix_battery_pct']);
        self::assertSame('Eastgate Post', $facts['nearest_station']);
    }

    /**
     * A CORRECTION TO ANOTHER POST RE-MEASURES THE WATCH against the post it
     * now names — the one watch, from its own pings.
     */
    public function testACorrectionToAnotherPostReMeasuresTheWatch(): void
    {
        [$area, $eastgate, $westgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        [$checkIn] = $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $service->ping($area, $person, ['positions' => [$this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.3001, lon: -29.9001)]]);

        $service->amend($checkIn, ['corrections' => [[
            'clientRef' => 'k-1',
            'effectiveFrom' => '2026-09-19T06:05:00+03:00',
            'status' => 'at_post',
            'stationUuid' => $westgate->getUuidString(),
        ]]]);

        $facts = $this->rowFacts(self::CLAIM);
        self::assertEqualsWithDelta($this->metres(-29.9001, -3.3001, -29.9, -3.3), $facts['closest_m'], 1e-6);
        self::assertEqualsWithDelta($this->metres(-29.9001, -3.3001, -29.9, -3.3), $facts['last_fix_m'], 1e-6);
        self::assertSame(1, $facts['ping_count'], 'the tally is the tally whatever the post');
    }

    /**
     * THE RING IS READ WHEN THE PAGE READS. Widen it and the same rows read
     * verified — nothing stored said otherwise.
     */
    public function testWideningTheRingReJudgesTheSameRows(): void
    {
        [$area, $eastgate] = $this->ground();
        $eastgate->setCatchmentM(50);
        $this->em->flush();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $service->ping($area, $person, ['positions' => [$this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2, lon: -29.7490)]]);

        $before = $this->rowFacts(self::CLAIM);
        self::assertSame(DayState::AtPostUnverified, $this->day($area->getUuidString())->state);
        self::assertSame(UnverifiedReason::OutsideRing, $this->day($area->getUuidString())->lastWatch()?->unverifiedReason);
        self::assertFalse($this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::NOW))->positions[0]->insideCatchment);

        $eastgate->setCatchmentM(2000);
        $this->em->flush();
        $this->em->clear();

        self::assertSame($before, $this->rowFacts(self::CLAIM), 'the ring is not a fact of the row');
        self::assertSame(DayState::AtPostVerified, $this->day($area->getUuidString())->state);
        self::assertTrue($this->presence()->liveIn((string) $area->getUuidString(), new \DateTimeImmutable(self::NOW))->positions[0]->insideCatchment);
    }

    /**
     * THE REBUILD REPRODUCES WHAT THE PINGS WROTE. The raw positions are kept,
     * and the command recomputes every row's facts from them — idempotent, so
     * a second run changes nothing.
     */
    public function testTheRebuildCommandReproducesTheFactsFromThePings(): void
    {
        [$area, $eastgate, $westgate] = $this->ground();
        $asha = $this->aPerson();
        $baraka = $this->aPerson('Baraka', 'Sanka');
        $service = $this->service();
        $service->claim($area, $asha, $this->claimBody(self::CLAIM, $eastgate, lat: -3.2003, lon: -29.7503));
        $service->ping($area, $asha, ['positions' => [
            $this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2, lon: -29.75, battery: 80),
            $this->pingBody('p-2', self::CLAIM, '2026-09-19T06:50:00+03:00', lat: -3.201, lon: -29.74, battery: 75),
        ]]);
        $service->ping($area, $asha, ['positions' => [$this->pingBody('p-0', self::CLAIM, '2026-09-19T06:09:00+03:00', lat: -3.3, lon: -29.6)]]);
        $service->claim($area, $baraka, $this->claimBody('claim-b', $westgate, '2026-09-19T06:30:00+03:00'));
        $service->ping($area, $baraka, ['positions' => [$this->pingBody('p-3', 'claim-b', '2026-09-19T06:40:00+03:00', lat: -3.31, lon: -29.91)]]);

        $written = [$this->rowFacts(self::CLAIM), $this->rowFacts('claim-b')];

        $this->em->getConnection()->executeStatement(
            'UPDATE duty_checkin SET ping_count = 0, first_ping_at = NULL, last_ping_at = NULL, last_fix = NULL, last_fix_at = NULL,'
            .' last_fix_accuracy_m = NULL, last_fix_battery_pct = NULL, last_fix_m = NULL, closest_m = NULL,'
            .' last_fix_zone_id = NULL, nearest_station_id = NULL',
        );

        $tester = $this->command();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 check-ins', $tester->getDisplay());
        self::assertSame($written, [$this->rowFacts(self::CLAIM), $this->rowFacts('claim-b')]);

        self::assertSame(0, $tester->execute([]));
        self::assertSame($written, [$this->rowFacts(self::CLAIM), $this->rowFacts('claim-b')], 'a second run is the same run');
    }

    /** --area and the day window narrow what is recomputed; everything else is left as it is. */
    public function testTheRebuildIsNarrowedByAreaAndDay(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $service = $this->service();
        $service->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $service->ping($area, $person, ['positions' => [$this->pingBody('p-1', self::CLAIM, '2026-09-19T06:10:00+03:00', lat: -3.2, lon: -29.75)]]);
        $this->em->getConnection()->executeStatement('UPDATE duty_checkin SET ping_count = 0');

        $tester = $this->command();
        self::assertSame(0, $tester->execute(['--area' => $area->getUuidString(), '--from' => '2026-09-20', '--until' => '2026-09-30']));
        self::assertSame(0, $this->rowFacts(self::CLAIM)['ping_count'], 'a day outside the window is not touched');

        self::assertSame(0, $tester->execute(['--area' => $area->getUuidString(), '--from' => self::DAY, '--until' => self::DAY]));
        self::assertSame(1, $this->rowFacts(self::CLAIM)['ping_count']);

        self::assertSame(1, $tester->execute(['--area' => '0f6b0a60-0000-7000-8000-000000000000']), 'an area nobody knows is refused');
    }

    private function day(?string $areaUuid): PersonDay
    {
        $days = $this->presence()->dayIn((string) $areaUuid, self::DAY);
        self::assertCount(1, $days);

        return $days[0];
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('area:presence:rebuild'));
    }

    /** @param list<string> $statements */
    private static function firstIndex(array $statements, string $prefix): int
    {
        foreach ($statements as $index => $sql) {
            if (str_starts_with(ltrim($sql), $prefix)) {
                return $index;
            }
        }

        self::fail(\sprintf('no statement starts with "%s": %s', $prefix, implode(' | ', $statements)));
    }
}
