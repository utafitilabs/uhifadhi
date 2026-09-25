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

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE PAGES THAT DRAW PRESENCE, BYTE FOR BYTE, AGAINST WHAT THEY DREW BEFORE.
 *
 * One ground, six people on it in every state a board can hold — verified at
 * a post, walked out of the ring, at a post with no ring, working elsewhere,
 * checked out, and reported by the check-in's own position alone — and the
 * three core pages that read it: the organization dashboard (whose plate
 * carries the live marks), the area overview and a station record with no
 * roster installed. Each body is compared with the one recorded in
 * `Fixtures/presence-pages/`, with only the identifiers a run mints for
 * itself (uuids) replaced by their order of appearance.
 *
 * THE SNAPSHOTS ARE THE BEFORE. They were recorded against the reading that
 * derived every figure from the raw pings; the reading from the check-in rows
 * must draw the same pages. `UHIFADHI_RECORD_PRESENCE_PAGES=1` rewrites them,
 * which is a decision about the product and never a way to make this pass.
 */
#[CoversNothing]
final class PresencePagesReadTheSameTest extends WebTestCase
{
    private const string DAY = '2026-09-19';

    public function testTheOrganizationDashboardDrawsTheSamePresence(): void
    {
        [$area] = $this->aGroundWithSixPeopleOnIt();

        $this->assertSameAsRecorded('org-dashboard', $this->body('/'));
        self::assertNotNull($area->getUuidString());
    }

    public function testTheAreaOverviewDrawsTheSamePresence(): void
    {
        [$area] = $this->aGroundWithSixPeopleOnIt();

        $this->assertSameAsRecorded('area-overview', $this->body('/areas/'.$area->getUuidString()));
    }

    public function testARosterFreeStationRecordDrawsTheSamePresence(): void
    {
        [$area, $eastgate] = $this->aGroundWithSixPeopleOnIt();

        $this->assertSameAsRecorded('station-record', $this->body('/areas/'.$area->getUuidString().'/stations/'.$eastgate->getUuidString()));
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aGroundWithSixPeopleOnIt(): array
    {
        $this->boot();
        $this->signIn();

        $area = $this->aLiveArea('Sample Reserve');
        $this->aZone($area, 'West', self::A_WEST_HALF);

        $eastgate = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $eastgate->setCatchmentM(300);
        $westgate = $this->stations()->add($area, 'Westgate Post', -29.9, -3.3, 'ST-02');
        $westgate->setCatchmentM(null);
        $this->em->flush();

        // Verified: pinged inside Eastgate's ring and still there.
        $asha = $this->aClaim($area, $this->aPerson('Asha', 'Mollel'), 'at_post', $eastgate, '06:02:00');
        $this->aPing($asha, -29.7501, -3.2001, '09:10:00', 81);
        $this->aPing($asha, -29.7502, -3.2002, '11:30:00', 78);

        // Verified off the nearest ping, then walked out of the ring.
        $baraka = $this->aClaim($area, $this->aPerson('Baraka', 'Sanka'), 'at_post', $eastgate, '06:05:00');
        $this->aPing($baraka, -29.7500, -3.2000, '07:00:00', 90);
        $this->aPing($baraka, -29.7350, -3.2000, '11:20:00', 60);

        // A post with no ring: measurable, never verifiable.
        $chausiku = $this->aClaim($area, $this->aPerson('Chausiku', 'Lema'), 'at_post', $westgate, '06:10:00');
        $this->aPing($chausiku, -29.9001, -3.3001, '10:00:00', null);

        // Working elsewhere, still reporting.
        $daudi = $this->aClaim($area, $this->aPerson('Daudi', 'Kimaro'), 'outside', null, '06:20:00');
        $this->aPing($daudi, -29.6, -3.1, '11:00:00', 55);

        // Checked out: on the day board, off the map.
        $eliya = $this->aClaim($area, $this->aPerson('Eliya', 'Massawe'), 'at_post', $eastgate, '05:40:00');
        $this->aPing($eliya, -29.7503, -3.2003, '06:30:00', 70);
        $eliya->setEndedAt(new \DateTimeImmutable(self::DAY.'T08:00:00+00:00'));
        $this->em->flush();

        // The check-in's own position and nothing since.
        $fatuma = $this->aClaim($area, $this->aPerson('Fatuma', 'Said'), 'at_post', $eastgate, '11:15:00');
        $fatuma->setPosition('{"type":"Point","coordinates":[-29.7504,-3.2004]}')
            ->setPositionAt(new \DateTimeImmutable(self::DAY.'T11:15:30+00:00'))
            ->setAccuracyM(12.0);
        $this->em->flush();
        $this->facts()->recordClaimFix($fatuma);
        $this->em->clear();

        $area = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        $eastgate = $this->em->getRepository(Station::class)->find($eastgate->getId());
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertInstanceOf(Station::class, $eastgate);

        return [$area, $eastgate];
    }

    /** The row facts, folded in the way the write service folds them. */
    private function facts(): PresenceFactsService
    {
        $facts = static::getContainer()->get('test_public.area.presence_facts');
        self::assertInstanceOf(PresenceFactsService::class, $facts);

        return $facts;
    }

    private function assertSameAsRecorded(string $name, string $body): void
    {
        $normalized = self::normalized($body);
        $file = __DIR__.'/Fixtures/presence-pages/'.$name.'.html';

        if ('1' === getenv('UHIFADHI_RECORD_PRESENCE_PAGES')) {
            file_put_contents($file, $normalized);
        }

        self::assertFileExists($file);
        self::assertSame((string) file_get_contents($file), $normalized, \sprintf('the %s draws a different page than the one recorded', $name));
    }

    /**
     * What a run mints for itself, replaced: every uuid and every hashed
     * identifier by the order it first appears in, and every row's own
     * wall-clock stamp (a `created_at`, never the pinned clock's day) by a
     * word.
     */
    private static function normalized(string $body): string
    {
        $seen = [];
        $body = (string) preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|(?<=&quot;)[0-9a-f]{16}(?:[0-9a-f]{16})?(?=&quot;)/',
            static function (array $match) use (&$seen): string {
                $seen[$match[0]] ??= 'id-'.(\count($seen) + 1);

                return $seen[$match[0]];
            },
            $body,
        );

        // A served asset carries its content digest in its name; a sheet or
        // script edited anywhere in the core changes it, and this test is about
        // presence, not about the digest.
        $body = (string) preg_replace('#(/assets/[^"\s]+?)-[A-Za-z0-9_-]{7}\.(css|js)#', '$1-digest.$2', $body);
        $body = (string) preg_replace('/(?!'.self::DAY.')\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00/', 'wall-clock', $body);

        // AND WHAT SUCH A STAMP PRINTS — "25 Sep · 15:42" is the run's minute.
        $body = (string) preg_replace('/(<time[^>]*datetime="wall-clock"[^>]*>)[^<]*/', '$1wall-clock', $body);

        // AND A LIVE MARK'S AGE, which the dashboard measures on the wall
        // clock rather than the pinned one: where each mark stands, when it
        // was fixed and whether it is stale are compared; how long ago that
        // is from this run's minute is not.
        return (string) preg_replace('/(&quot;age&quot;:&quot;)[^&]*(&quot;)/', '$1wall-clock$2', $body);
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);
        self::assertSame(200, $this->browser()->getResponse()->getStatusCode(), $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function aPerson(string $first, string $last): HostUser
    {
        $person = new HostUser()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function aClaim(AreaOfInterest $area, HostUser $person, string $statusKey, ?Station $station, string $time): CheckIn
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');

        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef('claim-'.$person->getFirstName())
            ->setLocalDate(new \DateTimeImmutable(self::DAY))
            ->setStatus($status)
            ->setStation($station)
            ->setOccurredAt(new \DateTimeImmutable(self::DAY.'T'.$time.'+00:00'))
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');

        $this->em->persist($checkIn);
        $this->em->flush();

        return $checkIn;
    }

    private function aPing(CheckIn $checkIn, float $lon, float $lat, string $time, ?int $battery): void
    {
        $area = $checkIn->getArea();
        $person = $checkIn->getPerson();
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertNotNull($person);

        $this->em->persist($ping = new PersonPosition()
            ->setArea($area)
            ->setPerson($person)
            ->setCheckIn($checkIn)
            ->setClientRef('ping-'.$checkIn->getClientRef().'-'.$time)
            ->setRecordedAt(new \DateTimeImmutable(self::DAY.'T'.$time.'+00:00'))
            ->setPosition(\sprintf('{"type":"Point","coordinates":[%F,%F]}', $lon, $lat))
            ->setAccuracyM(8.0)
            ->setBatteryPct($battery)
            ->setSource(PositionSourceEnum::Gps));
        $this->em->flush();
        $this->facts()->recordPings([$ping]);
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
