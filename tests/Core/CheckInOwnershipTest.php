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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * A CHECK-IN IS CHANGED BY ITS OWNER, OR BY THE HEAD OF THEIR STATION (ruled
 * 30 Sep, #67, class 6).
 *
 * Recording duty in an area let anybody who records there end or correct
 * somebody else's check-in - the handset API asked for the pair and never
 * whose claim it was. The owner changes their own; the head of the station
 * they are posted at changes it for them (the phoneless crew member); the
 * tiers change anybody's. Another ranger, or the head of another station, is
 * refused, and the claim stays exactly as it was.
 */
final class CheckInOwnershipTest extends FieldApiTestCase
{
    private const float POST_LON = -65.0;
    private const float POST_LAT = -3.2;
    private const string CLAIM_REF = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    private AreaOfInterest $area;

    /** @var array<string, string> who => bearer token, all issued before the first request */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->area = $this->area('Northern Conservation Reserve');
        $gate = $this->station($this->area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $other = $this->station($this->area, 'South Dip Post', self::POST_LON + 0.1, self::POST_LAT);

        $owner = $this->ranger('sl-0142', [...self::READS_THE_PARK, 'duty.record']);
        $colleague = $this->sameSeat($owner, 'sl-0200');
        $head = $this->sameSeat($owner, 'sl-0300');
        $headElsewhere = $this->sameSeat($owner, 'sl-0400');
        $admin = new User()->setEmail('admin@example.test')->setFirstName('Desta')->setLastName('Haile')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Admin)->setVerified(true);
        $this->em->persist($admin);
        $this->em->flush();

        $this->postTo($gate, $owner);
        $this->postTo($gate, $colleague);
        $this->postTo($gate, $head)->setLeader(true);
        $this->postTo($other, $headElsewhere)->setLeader(true);
        $this->em->flush();

        foreach (['owner' => $owner, 'colleague' => $colleague, 'head' => $head, 'headElsewhere' => $headElsewhere, 'admin' => $admin] as $who => $person) {
            $this->tokens[$who] = $this->tokenFor($person);
        }

        $this->send('POST', \sprintf('/api/areas/%s/checkins', $this->area->getUuidString()), $this->claim((string) $gate->getUuidString()), $this->tokens['owner']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
    }

    public function testTheOwnerEndsTheirOwnCheckIn(): void
    {
        $this->endAs('owner');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->stored()->getEndedAt());
    }

    public function testAnotherRangerAtTheSameStationIsRefused(): void
    {
        $this->endAs('colleague');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->stored()->getEndedAt(), 'the claim stays as it was');
    }

    public function testTheHeadOfTheirStationEndsItForThem(): void
    {
        $this->endAs('head');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->stored()->getEndedAt());
    }

    public function testTheHeadOfAnotherStationIsRefused(): void
    {
        $this->endAs('headElsewhere');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->stored()->getEndedAt(), 'the claim stays as it was');
    }

    public function testAnAdminEndsAnybodysCheckIn(): void
    {
        $this->endAs('admin');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    private function endAs(string $who): void
    {
        $this->send('PATCH', \sprintf('/api/areas/%s/checkins/%s', $this->area->getUuidString(), self::CLAIM_REF), [
            'endedAt' => '2026-09-19T18:02:41+03:00',
            'handoverNote' => 'Gate lock is stiff',
        ], $this->tokens[$who]);
    }

    /** Somebody else in the owner's seat, placed as widely: the same pairs, the same ground. */
    private function sameSeat(User $owner, string $rangerCode): User
    {
        $person = new User()->setEmail($rangerCode.'@example.test')->setFirstName('Colleague')->setLastName($rangerCode)
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)->setVerified(true)->setRangerCode($rangerCode);
        $person->setPosition($owner->getPosition());
        $placement = new Placement()->acrossTheOrganization()->inDepartment($this->homeDepartment($this->em));
        $this->em->persist($placement);
        $person->setPlacement($placement);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function stored(): CheckIn
    {
        $this->em->clear();
        $claim = $this->em->getRepository(CheckIn::class)->findOneBy(['clientRef' => self::CLAIM_REF]);
        self::assertInstanceOf(CheckIn::class, $claim);

        return $claim;
    }

    /** @return array<string, mixed> */
    private function claim(string $stationUuid): array
    {
        return [
            'clientRef' => self::CLAIM_REF,
            'localDate' => '2026-09-19',
            'status' => 'at_post',
            'stationUuid' => $stationUuid,
            'occurredAt' => '2026-09-19T06:08:12+03:00',
            'lat' => self::POST_LAT,
            'lon' => self::POST_LON,
            'accuracyM' => 8.0,
            'positionAt' => '2026-09-19T06:08:54+03:00',
            'deviceId' => '0f9ca41e-0000-4000-8000-000000000001',
            'appVersion' => '0.1.0',
        ];
    }
}
