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
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * THE DAY A RANGER REPORTS — API-CONTRACT.md §13A–§13C, specified from the
 * handset's own bodies.
 *
 * THE BODIES BELOW ARE THE APP'S, MEMBER FOR MEMBER. They are the documents
 * `FakeApiClient` is driven with — the app's executable reference for this
 * section — with two deliberate departures, both of which would be defects
 * if they were not made:
 *
 *  * THE GROUND IS INVENTED. Every coordinate here is open water at −65°, and
 *    no real post, park or country appears anywhere in this repository.
 *  * A NULL IS ABSENT, not spelt. The handset serialises with `explicitNulls`
 *    off and defaults unwritten, so a claim with no fix sends no `lat` member
 *    at all and a check-out with nothing to correct sends no `corrections`.
 *    A suite that spelt them would be specifying a body no phone sends.
 *
 * WHAT THESE ENDPOINTS PROMISE, and what each specification here is about:
 *
 *  * A CLIENT REFERENCE IS ACCEPTED ONCE. The queue retries after a timeout,
 *    so a second arrival is the same claim, answered `duplicate` — never a
 *    second row.
 *  * NOTHING REWRITES WHAT WAS SAID. The 06:08 claim is what the ranger said
 *    at 06:08 and stays exactly that: a check-out adds its own fields, a
 *    correction is a row of its own, a late fix fills a claim that had none.
 *  * NOTHING DERIVED IS EVER STORED. There is no field in any of these
 *    requests that could carry a judgement, and nothing here writes one.
 *  * A BATCH THAT FAILS WHOLE NEVER DRAINS. The part-ack lists what was
 *    stored; the phone deletes exactly those.
 */
final class FieldDutyEndpointsTest extends FieldApiTestCase
{
    /** An invented post, on open water. */
    private const float POST_LON = -65.0;
    private const float POST_LAT = -3.2;

    private const string CLAIM_REF = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    public function testAClaimWithNoTokenIsUnauthorized(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->send('POST', $this->checkIns($area), ['clientRef' => self::CLAIM_REF]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($body));
        self::assertSame('unauthorized', self::leaf($body, 'code'));
    }

    /**
     * READING THE PARK IS NOT REPORTING A DAY. `duty.checkin` is its own
     * permission, declared by the bundle that enforces it, and an account
     * that may see an area but was never given it is refused here.
     */
    public function testAnAccountWithoutTheDutyPermissionIsForbidden(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $reader = $this->ranger('sl-0199');

        $body = $this->send(
            'POST',
            $this->checkIns($area),
            $this->claim($area),
            $this->tokenFor($reader),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('forbidden', self::leaf($body, 'code'));
        self::assertFalse(self::leaf($body, 'retryable'));
    }

    /** §13A: the claim, and the envelope the app parses. */
    public function testTheClaimIsStoredAndAnsweredWithTheContractsEnvelope(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());

        $body = $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertSame(['clientRef', 'duplicate', 'serverTime'], array_keys($body));
        self::assertSame(self::CLAIM_REF, self::leaf($body, 'clientRef'));
        self::assertFalse(self::leaf($body, 'duplicate'));

        $stored = $this->claimAsStored($area);
        self::assertSame('at_post', $stored->getStatus()?->getKey());
        self::assertSame($station->getId(), $stored->getStation()?->getId());
        self::assertSame('2026-09-19', $stored->getLocalDate()?->format('Y-m-d'));
        self::assertSame(8.0, $stored->getAccuracyM());
    }

    /**
     * THE TAP'S OWN CLOCK, READ WITH THE OFFSET IT WAS SENT WITH — 06:08:12
     * at +03:00 is 03:08:12 UTC, and that is the moment stored.
     *
     * THE OFFSET ITSELF IS NOT KEPT, and must not be missed: an instant is a
     * moment, every instant in this product is printed in the VIEWER's zone,
     * and the ranger's own day — the one fact an offset could not carry — is
     * sent separately as `localDate` for exactly that reason. What would be a
     * defect is reading `+03:00` as if it were the server's zone, which is
     * what a naive timestamp on this wire would cause.
     */
    public function testAnInstantIsReadWithTheOffsetItWasSentWith(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);

        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $this->tokenFor($this->onDuty()));

        $this->em->clear();
        $stored = $this->claimAsStored($area);
        self::assertSame('2026-09-19T03:08:12+00:00', self::asUtc($stored->getOccurredAt()));
        self::assertSame('2026-09-19T03:08:54+00:00', self::asUtc($stored->getPositionAt()));
    }

    /**
     * THE PERSON IS THE TOKEN'S, NEVER THE BODY'S. `personUuid` rides in the
     * document because the app has one to send, and the server ignores it: a
     * handset must not be able to report a day in somebody else's name by
     * editing a string.
     */
    public function testTheClaimIsFiledAgainstTheBearerAndNotThePersonInTheBody(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $bearer = $this->onDuty();
        $somebodyElse = $this->officeStaff('Naomi', 'Kileo');

        $claim = $this->claim($area, self::uuidOf($station));
        $claim['personUuid'] = 'sl-9999';

        $this->send('POST', $this->checkIns($area), $claim, $this->tokenFor($bearer));

        $stored = $this->claimAsStored($area);
        self::assertSame($bearer->getId(), $stored->getPerson()?->getId());
        self::assertNotSame($somebodyElse->getId(), $stored->getPerson()?->getId());
    }

    /** §13A: the retry after a timeout is the same claim, not a second one. */
    public function testTheSameClientReferenceTwiceIsOneClaim(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $claim = $this->claim($area, self::uuidOf($station));

        $this->send('POST', $this->checkIns($area), $claim, $token);
        $again = $this->send('POST', $this->checkIns($area), $claim, $token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertTrue(self::leaf($again, 'duplicate'));
        self::assertCount(1, $this->em->getRepository(CheckIn::class)->findAll());
    }

    /**
     * A FIX THAT HAS NOT LANDED NEVER BLOCKS THE CLAIM — the never-block rule,
     * on a second surface. The day is recorded with no position at all, and
     * what that means is derived later, on read.
     */
    public function testAClaimWithNoFixIsAccepted(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);

        $claim = $this->claim($area, self::uuidOf($station));
        unset($claim['lat'], $claim['lon'], $claim['accuracyM'], $claim['positionAt']);

        $this->send('POST', $this->checkIns($area), $claim, $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->claimAsStored($area)->getPosition());
    }

    /** §13A: a post is the one thing `at_post` cannot be claimed without. */
    public function testAtPostWithoutAStationIsRefused(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $claim = $this->claim($area);
        unset($claim['stationUuid']);

        $body = $this->send('POST', $this->checkIns($area), $claim, $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_payload', self::leaf($body, 'code'));
        self::assertSame('stationUuid', self::leaf($body, 'details', 'field'));
    }

    /** A word this area does not publish is refused by name, not swallowed. */
    public function testAStatusTheAreaDoesNotOfferIsRefused(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $claim = $this->claim($area);
        $claim['status'] = 'seconded';
        unset($claim['stationUuid']);

        $body = $this->send('POST', $this->checkIns($area), $claim, $this->tokenFor($this->onDuty()));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame('unsupported_status', self::leaf($body, 'code'));
    }

    /**
     * §13B: the check-out adds its own two fields and touches nothing else.
     * The 06:08 claim is still, exactly, what was said at 06:08.
     */
    public function testTheCheckOutAppendsAndRewritesNothing(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $body = $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), [
            'endedAt' => '2026-09-19T18:02:41+03:00',
            'handoverNote' => 'Gate lock is stiff',
        ], $token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['clientRef', 'duplicate', 'serverTime'], array_keys($body));

        $stored = $this->claimAsStored($area);
        self::assertSame('2026-09-19T15:02:41+00:00', self::asUtc($stored->getEndedAt()));
        self::assertSame('Gate lock is stiff', $stored->getHandoverNote());
        self::assertSame('2026-09-19T03:08:12+00:00', self::asUtc($stored->getOccurredAt()));
        self::assertSame('at_post', $stored->getStatus()?->getKey());
        self::assertSame($station->getId(), $stored->getStation()?->getId());
    }

    /**
     * §13B: the back-fill fills a claim that had NO position, and a claim that
     * already carried one is not re-positioned — a later, better fix is a
     * different moment, not a better version of this one.
     */
    public function testTheBackFillFillsAnEmptyPositionAndNeverReplacesOne(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());

        $claim = $this->claim($area, self::uuidOf($station));
        unset($claim['lat'], $claim['lon'], $claim['accuracyM'], $claim['positionAt']);
        $this->send('POST', $this->checkIns($area), $claim, $token);

        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), [
            'lat' => self::POST_LAT,
            'lon' => self::POST_LON,
            'accuracyM' => 8.0,
            'positionAt' => '2026-09-19T06:08:54+03:00',
        ], $token);

        $this->em->clear();
        $filled = $this->claimAsStored($area);
        self::assertNotNull($filled->getPosition());
        self::assertSame('2026-09-19T03:08:54+00:00', self::asUtc($filled->getPositionAt()));

        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), [
            'lat' => self::POST_LAT + 0.5,
            'lon' => self::POST_LON + 0.5,
            'accuracyM' => 3.0,
            'positionAt' => '2026-09-19T09:00:00+03:00',
        ], $token);

        $this->em->clear();
        $unchanged = $this->claimAsStored($area);
        self::assertSame('2026-09-19T03:08:54+00:00', self::asUtc($unchanged->getPositionAt()));
        self::assertSame(8.0, $unchanged->getAccuracyM());
    }

    /** §13B: a correction is a second claim with its own start, appended. */
    public function testACorrectionIsAppendedWithItsOwnStart(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), $this->correction(), $token);

        $this->em->clear();
        $stored = $this->claimAsStored($area);
        self::assertCount(1, $stored->getCorrections());

        $correction = $stored->getCorrections()->first();
        self::assertNotFalse($correction);
        self::assertSame('special', $correction->getStatus()?->getKey());
        self::assertSame('Escort', $correction->getNote());
        self::assertNull($correction->getStation());
        self::assertSame('2026-09-19T04:40:00+00:00', self::asUtc($correction->getEffectiveFrom()));
    }

    /**
     * §13B: the patch is safe to send repeatedly — the app sends the WHOLE
     * history of the claim every time it syncs — so the same correction
     * reference cannot append a second copy of itself.
     */
    public function testTheSameCorrectionTwiceIsOneCorrection(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), $this->correction(), $token);
        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), $this->correction(), $token);

        $this->em->clear();
        self::assertCount(1, $this->claimAsStored($area)->getCorrections());
    }

    /** A patch against a reference this area never took is refused by name. */
    public function testAPatchOnAnUnknownClaimIsRefused(): void
    {
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->send(
            'PATCH',
            $this->checkIn($area, 'ffffffff-0000-4000-8000-000000000000'),
            ['endedAt' => '2026-09-19T18:02:41+03:00'],
            $this->tokenFor($this->onDuty()),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('unknown_checkin', self::leaf($body, 'code'));
    }

    /** §13C: the batch, and the part-ack the phone deletes by. */
    public function testThePingsAreStoredAndAnsweredWithThePartAck(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $body = $this->send('POST', $this->positions($area), $this->batch([
            $this->ping('a71c0000-0000-4000-8000-000000000001', '2026-09-19T06:38:00+03:00'),
            $this->ping('a71c0000-0000-4000-8000-000000000002', '2026-09-19T07:08:00+03:00'),
        ]), $token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['accepted', 'acceptedUuids', 'duplicate', 'serverTime'], array_keys($body));
        self::assertTrue(self::leaf($body, 'accepted'));
        self::assertFalse(self::leaf($body, 'duplicate'));
        self::assertSame([
            'a71c0000-0000-4000-8000-000000000001',
            'a71c0000-0000-4000-8000-000000000002',
        ], self::nested($body, 'acceptedUuids'));

        self::assertCount(2, $this->em->getRepository(PersonPosition::class)->findAll());
    }

    /**
     * §13C: a ping already held is ACKNOWLEDGED AGAIN, never stored twice. The
     * phone is carrying it because it never saw the first answer, and a
     * silence here would leave it carrying it for ever.
     */
    public function testAPingAlreadyHeldIsAcknowledgedAgainAndNotStoredTwice(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $batch = $this->batch([$this->ping('a71c0000-0000-4000-8000-000000000001', '2026-09-19T06:38:00+03:00')]);
        $this->send('POST', $this->positions($area), $batch, $token);
        $again = $this->send('POST', $this->positions($area), $batch, $token);

        self::assertTrue(self::leaf($again, 'duplicate'));
        self::assertSame(['a71c0000-0000-4000-8000-000000000001'], self::nested($again, 'acceptedUuids'));
        self::assertCount(1, $this->em->getRepository(PersonPosition::class)->findAll());
    }

    /**
     * §13C: A BATCH THAT FAILS WHOLE IS A BATCH THAT NEVER DRAINS. A ping
     * naming a watch this area does not hold is simply not acknowledged; its
     * neighbours are stored and the phone keeps exactly the one that was not
     * listed.
     */
    public function testAPingForAnUnknownWatchLeavesTheRestOfTheBatchAccepted(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());
        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);

        $orphan = $this->ping('a71c0000-0000-4000-8000-000000000009', '2026-09-19T06:38:00+03:00');
        $orphan['checkinRef'] = 'ffffffff-0000-4000-8000-000000000000';

        $body = $this->send('POST', $this->positions($area), $this->batch([
            $orphan,
            $this->ping('a71c0000-0000-4000-8000-000000000001', '2026-09-19T07:08:00+03:00'),
        ]), $token);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['a71c0000-0000-4000-8000-000000000001'], self::nested($body, 'acceptedUuids'));
        self::assertCount(1, $this->em->getRepository(PersonPosition::class)->findAll());
    }

    /**
     * A DAY HOLDS ANY NUMBER OF WATCHES — ruled 2026-09-21. Somebody
     * checks out at noon and checks in again at four, and the second
     * claim is an ordinary claim: a new client reference, a new row.
     * Nothing caps a day, and nothing about the second write is special.
     */
    public function testAChecKInAfterACheckOutOpensAnotherWatchOnTheSameDay(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'North Gate Post', self::POST_LON, self::POST_LAT);
        $token = $this->tokenFor($this->onDuty());

        $this->send('POST', $this->checkIns($area), $this->claim($area, self::uuidOf($station)), $token);
        $this->send('PATCH', $this->checkIn($area, self::CLAIM_REF), ['endedAt' => '2026-09-19T12:00:00+03:00'], $token);

        $afternoon = $this->claim($area, self::uuidOf($station));
        $afternoon['clientRef'] = 'c0ffee00-0000-4000-8000-000000000002';
        $afternoon['occurredAt'] = '2026-09-19T16:00:00+03:00';

        $body = $this->send('POST', $this->checkIns($area), $afternoon, $token);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertFalse(self::leaf($body, 'duplicate'), 'a second watch is not a repeat of the first');
        self::assertCount(2, $this->em->getRepository(CheckIn::class)->findAll());
    }

    /** An area the URI names that this installation does not have. */
    public function testAnUnknownAreaIsRefused(): void
    {
        $body = $this->send(
            'POST',
            '/api/areas/ffffffff-0000-4000-8000-000000000000/checkins',
            ['clientRef' => self::CLAIM_REF],
            $this->tokenFor($this->onDuty()),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('unknown_area', self::leaf($body, 'code'));
    }

    // ─── The cast, and the documents the handset sends ──────────────────────

    /** Somebody who may report a day: area view, and the duty permission. */
    private function onDuty(): User
    {
        return $this->ranger('sl-0142', [...self::READS_THE_PARK, 'duty.record']);
    }

    /**
     * §13A's document, exactly as the app writes it — absent where the app
     * omits, present where it sends.
     *
     * @return array<string, mixed>
     */
    private function claim(AreaOfInterest $area, ?string $stationUuid = null): array
    {
        return [
            'clientRef' => self::CLAIM_REF,
            'personUuid' => 'sl-0142',
            'localDate' => '2026-09-19',
            'status' => 'at_post',
            'stationUuid' => $stationUuid ?? '00000000-0000-4000-8000-00000000ffff',
            'occurredAt' => '2026-09-19T06:08:12+03:00',
            'lat' => self::POST_LAT,
            'lon' => self::POST_LON,
            'accuracyM' => 8.0,
            'positionAt' => '2026-09-19T06:08:54+03:00',
            'deviceId' => '0f9ca41e-0000-4000-8000-000000000001',
            'appVersion' => '0.1.0',
        ];
    }

    /**
     * §13B's corrections member, as the app sends it.
     *
     * @return array<string, mixed>
     */
    private function correction(): array
    {
        return [
            'corrections' => [
                [
                    'clientRef' => '9f2a0000-0000-4000-8000-000000000001',
                    'status' => 'special',
                    'effectiveFrom' => '2026-09-19T07:40:00+03:00',
                    'note' => 'Escort',
                ],
            ],
        ];
    }

    /**
     * §13C's batch.
     *
     * @param list<array<string, mixed>> $positions
     *
     * @return array<string, mixed>
     */
    private function batch(array $positions): array
    {
        return ['batchRef' => self::CLAIM_REF.':positions', 'positions' => $positions];
    }

    /**
     * One duty ping — the patrol track's point with the subject changed.
     *
     * @return array<string, mixed>
     */
    private function ping(string $clientRef, string $recordedAt): array
    {
        return [
            'clientRef' => $clientRef,
            'personUuid' => 'sl-0142',
            'checkinRef' => self::CLAIM_REF,
            'recordedAt' => $recordedAt,
            'lat' => self::POST_LAT,
            'lon' => self::POST_LON,
            'accuracyM' => 8.0,
            'batteryPct' => 74,
            'source' => 'gps',
        ];
    }

    /**
     * AN INSTANT, SAID IN ONE ZONE so that two of them can be compared. Every
     * expectation here is written in UTC for that reason alone — no screen
     * prints a time this way.
     */
    private static function asUtc(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function checkIns(AreaOfInterest $area): string
    {
        return \sprintf('/api/areas/%s/checkins', self::uuidOf($area));
    }

    private function checkIn(AreaOfInterest $area, string $clientRef): string
    {
        return \sprintf('/api/areas/%s/checkins/%s', self::uuidOf($area), $clientRef);
    }

    private function positions(AreaOfInterest $area): string
    {
        return \sprintf('/api/areas/%s/positions', self::uuidOf($area));
    }

    /** The public address of a record that has been written, which always has one. */
    private static function uuidOf(AreaOfInterest|Station $record): string
    {
        $uuid = $record->getUuidString();
        \assert(\is_string($uuid), 'a persisted record carries its public address');

        return $uuid;
    }

    /**
     * The one claim this area holds, READ BACK FROM THE DATABASE and not from
     * the answer: what an endpoint says it did and what it wrote are two
     * assertions, and only the second one is about the record.
     */
    private function claimAsStored(AreaOfInterest $area): CheckIn
    {
        $claim = $this->em->getRepository(CheckIn::class)->findOneBy(['clientRef' => self::CLAIM_REF]);
        \assert($claim instanceof CheckIn, 'the area holds the claim that was sent');
        \assert($area->getId() === $claim->getArea()?->getId(), 'and holds it against the area the URI named');

        return $claim;
    }
}
