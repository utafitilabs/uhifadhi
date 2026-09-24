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
    private const array AREAS_MINE = ['areas', 'postedAreaId'];

    private const array AREA = ['id', 'name', 'areaKm2', 'stations', 'team', 'boundary', 'posted'];

    private const array TEAM_MEMBER = ['id', 'name'];

    /** Pinned on both sides; the handset's `FieldContractTest` holds the same value. */
    private const string HANDSET_FIXTURE_SHA256 = '432c5a6c9e5575c2344b280bac4fc9736dcb9d1446e60539224528ed8dfcb246';

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
