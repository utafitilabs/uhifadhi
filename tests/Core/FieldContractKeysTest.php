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

use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * THE TWO DOCUMENTS, KEY BY KEY, AGAINST THE FIELD CONTRACT.
 *
 * A released handset reads these exact names and cannot be redeployed because a
 * serializer was reconfigured, a property was renamed, or a format was added to
 * the URL space. The endpoints' own suites assert what each field MEANS; this one
 * asserts only the NAMES and their ORDER, in one place, so the contract can be
 * read off a single file.
 *
 * NOTHING EXTRA IS TOLERATED EITHER. A stray `@context` or `@id` is the exact
 * failure a JSON-LD default would cause, and it would reach a client as an
 * unparseable document rather than as a build failure — so the assertions are on
 * the whole key list and not on the presence of the keys that are wanted.
 */
final class FieldContractKeysTest extends FieldApiTestCase
{
    /** `GET /api/me`: the account, and the permissions it holds. */
    private const array ME = ['ranger', 'permissions'];

    /** The account, as every document that names one spells it. */
    private const array RANGER = ['id', 'name', 'role'];

    /** `GET /api/areas/mine`: one key, whose list is the offline cache. */
    private const array AREAS_MINE = ['areas', 'postedAreaId', 'postedStationId'];

    private const array AREA = ['id', 'name', 'areaKm2', 'stations', 'team', 'boundary', 'posted'];

    private const array TEAM_MEMBER = ['id', 'name'];

    /** Pinned on both sides; the handset's `FieldContractTest` holds the same value. */
    private const string HANDSET_FIXTURE_SHA256 = 'caea4713f8ecfd035a5f678cf0a7ff4396f11283c290c667ebdeaa52954eb589';

    public function testTheAccountDocumentCarriesExactlyTheContractsKeys(): void
    {
        $body = $this->get('/api/me', $this->tokenFor($this->ranger()));

        self::assertSame(self::ME, array_keys($body));
        self::assertSame(self::RANGER, array_keys(self::nested($body, 'ranger')));
    }

    public function testTheAreasDocumentCarriesExactlyTheContractsKeys(): void
    {
        $this->area('Northern Conservation Reserve');
        $this->officeStaff('Naomi', 'Kileo');

        $body = $this->get('/api/areas/mine', $this->tokenFor($this->ranger()));

        self::assertSame(self::AREAS_MINE, array_keys($body));

        $area = self::nested($body, 'areas', 0);
        self::assertSame(self::AREA, array_keys($area));
        self::assertSame(self::TEAM_MEMBER, array_keys(self::nested($area, 'team', 0)));
    }

    /**
     * ONE FORMAT ON THIS URL SPACE. JSON-LD would answer the same resources with
     * `@context` and `@id` members, which is a document no client here parses —
     * so a request that asks for it is refused rather than served something the
     * contract does not describe.
     */
    /**
     * THE HANDSET'S FIXTURE IS WHAT THIS ENDPOINT SENDS.
     *
     * `tests/Core/Fixtures/field/areas-mine.json` is copied verbatim into the
     * handset's test resources, where the client decodes it. Both sides pin
     * its digest: change the document here and the handset's copy fails
     * until it is brought along, and the other way round. Before this test,
     * each side was green against its own idea of the contract while a real
     * station hung the handset's sign-in.
     */
    public function testTheHandsetFixtureIsWhatThisEndpointSends(): void
    {
        $path = __DIR__.'/Fixtures/field/areas-mine.json';
        $text = (string) file_get_contents($path);
        self::assertSame(self::HANDSET_FIXTURE_SHA256, hash('sha256', $text), 'the fixture changed: re-pin on BOTH sides');

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);

        $area = $this->area('Northern Conservation Reserve');
        $this->officeStaff('Naomi', 'Kileo');
        $ranger = $this->ranger();
        $this->postTo($this->station($area, 'Eastgate Post', -29.5, -3.2, 300, 'ST-01'), $ranger);

        $body = $this->get('/api/areas/mine', $this->tokenFor($ranger));

        self::assertSame(self::shape($fixture), self::shape($body));
    }

    /**
     * THE DAY'S DOCUMENTS, PINNED THE SAME WAY — §13 of the contract.
     *
     * The duty endpoints' own suites assert their key lists; what they could
     * not assert is that the handset READS those documents, because the
     * handset decodes its own idea of them. These four fixtures are copied
     * verbatim into the handset's test resources, both sides pin the digest,
     * and the handset decodes each one through its real client. Change a
     * document here and the phone's build fails until it is brought along.
     *
     * The check-in envelope is one fixture for two writes: §13A and §13B
     * answer the same three keys, and the duty suite proves it for both.
     */
    public function testTheHandsetRosterFixtureIsWhatThisEndpointSends(): void
    {
        $fixture = $this->pinned('duty-roster.json', self::DUTY_ROSTER_FIXTURE_SHA256);
        $area = $this->area('Northern Conservation Reserve');

        $body = $this->get(
            \sprintf('/api/areas/%s/me/roster?from=2026-09-01&to=2026-09-30', (string) $area->getUuidString()),
            $this->tokenFor($this->onDuty()),
        );

        self::assertSame(self::shape($fixture), self::shape($body));
    }

    public function testTheHandsetStationsFixtureIsWhatThisEndpointSends(): void
    {
        $fixture = $this->pinned('duty-stations.json', self::DUTY_STATIONS_FIXTURE_SHA256);
        $area = $this->area('Northern Conservation Reserve');
        $this->station($area, 'Eastgate Post', -29.5, -3.2, 300, 'ST-01');

        $body = $this->get(
            \sprintf('/api/areas/%s/stations', (string) $area->getUuidString()),
            $this->tokenFor($this->onDuty()),
        );

        self::assertSame(self::shape($fixture), self::shape($body));
    }

    public function testTheHandsetCheckInFixtureIsWhatThisEndpointSends(): void
    {
        $fixture = $this->pinned('duty-checkin.json', self::DUTY_CHECKIN_FIXTURE_SHA256);
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'Eastgate Post', -29.5, -3.2, 300, 'ST-01');

        $body = $this->send(
            'POST',
            \sprintf('/api/areas/%s/checkins', (string) $area->getUuidString()),
            $this->claim((string) $station->getUuidString()),
            $this->tokenFor($this->onDuty()),
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::shape($fixture), self::shape($body));
    }

    public function testTheHandsetPositionsFixtureIsWhatThisEndpointSends(): void
    {
        $fixture = $this->pinned('duty-positions.json', self::DUTY_POSITIONS_FIXTURE_SHA256);
        $area = $this->area('Northern Conservation Reserve');
        $station = $this->station($area, 'Eastgate Post', -29.5, -3.2, 300, 'ST-01');
        $token = $this->tokenFor($this->onDuty());
        $areaUuid = (string) $area->getUuidString();

        $this->send('POST', \sprintf('/api/areas/%s/checkins', $areaUuid), $this->claim((string) $station->getUuidString()), $token);
        $body = $this->send('POST', \sprintf('/api/areas/%s/positions', $areaUuid), [
            'batchRef' => self::CLAIM_REF.':positions',
            'positions' => [[
                'clientRef' => 'a71c0000-0000-4000-8000-000000000001',
                'personUuid' => 'sl-0142',
                'checkinRef' => self::CLAIM_REF,
                'recordedAt' => '2026-09-19T06:38:00+03:00',
                'lat' => -3.2,
                'lon' => -29.5,
                'accuracyM' => 8.0,
                'batteryPct' => 74,
                'source' => 'gps',
            ]],
        ], $token);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::shape($fixture), self::shape($body));
    }

    private const string CLAIM_REF = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    /** Pinned on both sides; the handset's `FieldContractTest` holds the same four values. */
    private const string DUTY_ROSTER_FIXTURE_SHA256 = 'af01ff668c00a9dcafcb22dac49059425206e9736c7b6d7ab4138ab4bf92c31d';
    private const string DUTY_STATIONS_FIXTURE_SHA256 = 'e20ab12d08aeba97da1de5989cea068b7c734359867bdd848ab52ae18818a6fd';
    private const string DUTY_CHECKIN_FIXTURE_SHA256 = '59ab5ed980790e9b8682d5e48e531ee505f777a03053f75e01d8fa9a1a7eaf23';
    private const string DUTY_POSITIONS_FIXTURE_SHA256 = '8a94e0453827a955d22822173f81cad7065cee9bde0c834b1f55cefe2cb30fc3';

    /** Somebody who may report a day: the park's reads, and the duty permission. */
    private function onDuty(): User
    {
        return $this->ranger('sl-0142', [...self::READS_THE_PARK, 'duty.record']);
    }

    /**
     * §13A's claim, as the app writes it — the same document the duty
     * suite drives its endpoints with.
     *
     * @return array<string, mixed>
     */
    private function claim(string $stationUuid): array
    {
        return [
            'clientRef' => self::CLAIM_REF,
            'personUuid' => 'sl-0142',
            'localDate' => '2026-09-19',
            'status' => 'at_post',
            'stationUuid' => $stationUuid,
            'occurredAt' => '2026-09-19T06:08:12+03:00',
            'lat' => -3.2,
            'lon' => -29.5,
            'accuracyM' => 8.0,
            'positionAt' => '2026-09-19T06:08:54+03:00',
            'deviceId' => '0f9ca41e-0000-4000-8000-000000000001',
            'appVersion' => '0.1.0',
        ];
    }

    /**
     * A fixture read off disk and checked against the digest both sides
     * pin, so the comparison is against the bytes the handset holds.
     *
     * @return array<string, mixed>
     */
    private function pinned(string $file, string $sha256): array
    {
        $text = (string) file_get_contents(__DIR__.'/Fixtures/field/'.$file);
        self::assertSame($sha256, hash('sha256', $text), $file.' changed: re-pin on BOTH sides');

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);

        return $fixture;
    }

    /**
     * The document's shape: every key, in order, at every depth; values
     * replaced by their type. Lists keep their first element only, because a
     * list's contract is the shape of one item.
     *
     * @return array<mixed>|string
     */
    private static function shape(mixed $value): array|string
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return [] === $value ? [] : [self::shape($value[0])];
            }

            return array_map(self::shape(...), $value);
        }

        // JSON has one number type; PHP decodes 35 as int and 35.5 as float.
        return \is_int($value) || \is_float($value) ? 'number' : get_debug_type($value);
    }

    public function testTheApiSpeaksJsonAndNothingElse(): void
    {
        $token = $this->tokenFor($this->ranger());

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);

        self::assertSame(406, $this->client->getResponse()->getStatusCode());
    }

    /** The answer is JSON whatever a client's Accept header happens to say. */
    public function testTheAnswerIsJsonForAClientThatAsksForAnything(): void
    {
        $token = $this->tokenFor($this->ranger());

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => '*/*',
        ]);

        self::assertStringContainsString(
            'application/json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }
}
