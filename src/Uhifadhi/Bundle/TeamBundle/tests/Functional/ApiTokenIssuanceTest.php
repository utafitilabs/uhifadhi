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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE FIELD CLIENT'S WIRE CONTRACT, PINNED.
 *
 * `POST /api/auth/token` is the one endpoint a client reaches without a token,
 * and the shape of its answer is not ours to drift: a released client reads
 * these exact field names and will not be redeployed because a serializer was
 * reconfigured. So the assertions here are on the LITERAL document — every key,
 * in order, with nothing extra — rather than on "a token comes back".
 *
 * THE ONE THING A CLIENT MUST NOT HAVE TO GUESS is whether it may record. An
 * empty `permissions` array is a refusal; a MISSING one would read as "an older
 * installation, therefore permitted". It is always sent, including empty.
 */
final class ApiTokenIssuanceTest extends WebTestCaseWithSchema
{
    private const string ENDPOINT = '/api/auth/token';
    private const string PASSCODE = 'a-real-passcode';
    private const string DEVICE = '7f1c2b90-0000-4000-8000-000000000001';

    public function testSigningInHandsBackTheContractsExactDocument(): void
    {
        $this->ranger();

        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE, 'deviceId' => self::DEVICE, 'deviceName' => 'the spare handset']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $body = $this->body();
        self::assertSame(['token', 'expiresAt', 'ranger', 'permissions'], array_keys($body));

        self::assertIsString($body['token']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['token']);

        // UTC, to the second, with a literal Z — no offsets, no microseconds.
        self::assertIsString($body['expiresAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['expiresAt']);

        self::assertSame(
            ['id' => 'sl-0142', 'name' => 'Witness Mbise', 'role' => 'Ranger'],
            $body['ranger'],
        );

        // The grants the position holds, spelled `<concern>.<verb>` as the web
        // enforces them; this kernel carries the team's own concerns only.
        self::assertSame(['directory.read'], $body['permissions']);
    }

    /** The token that comes back is the one the client can then authenticate with. */
    public function testTheTokenHandedBackAuthenticatesTheNextRequest(): void
    {
        $this->ranger();
        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE]);

        $token = $this->body()['token'];
        self::assertIsString($token);

        $this->client->request('GET', '/api/_guarded', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * NO TOKEN IS 401, NOT 403. A client shows a person different things for
     * the two — "sign in again" against "you may not do that" — so the
     * authenticator claims only requests that present one, and everything else
     * meets the entry point.
     */
    public function testAnApiRequestWithNoTokenIsUnauthorizedRatherThanForbidden(): void
    {
        $this->client->request('GET', '/api/_guarded');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testAnApiRequestWithATokenNamingNobodyIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/_guarded', server: ['HTTP_AUTHORIZATION' => 'Bearer nothing-was-ever-issued-for-this']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * ONE ANSWER FOR "NO SUCH PERSON" AND "WRONG PASSCODE". Differing replies
     * turn this endpoint into a directory of who works here.
     *
     * @return \Generator<string, array{array<string, string>}>
     */
    public static function refusals(): \Generator
    {
        yield 'no such person' => [['rangerId' => 'sl-9999', 'passcode' => self::PASSCODE]];
        yield 'the wrong passcode' => [['rangerId' => 'sl-0142', 'passcode' => 'not-the-passcode']];
    }

    /**
     * @param array<string, string> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalIsOneDocumentWhicheverHalfWasWrong(array $payload): void
    {
        $this->ranger();
        $this->post($payload);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame([
            'code' => 'invalid_credentials',
            'message' => 'That service number and passcode do not match an account.',
            'retryable' => false,
            'details' => [],
        ], $this->body());
    }

    /** Staff who were never issued a service number sign in with their email. */
    public function testAnEmailAddressIsAcceptedAsTheIdentifier(): void
    {
        $this->ranger();
        $this->post(['rangerId' => 'w.mbise@example.test', 'passcode' => self::PASSCODE]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A DEACTIVATED ACCOUNT SIGNS IN NOWHERE. The web door refuses it through
     * the user checker a firewall names; this door has no firewall at all, so
     * it has to refuse the same thing itself — and say the same sentence, so a
     * client cannot tell a deactivated account from an unknown one.
     */
    public function testADeactivatedAccountIsRefusedLikeAnUnknownOne(): void
    {
        $this->ranger()->deactivate();
        $this->em->flush();

        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_credentials', $this->body()['code']);
    }

    public function testTheDeviceHeaderIsAcceptedWhereTheBodyNamesNoDevice(): void
    {
        $this->ranger();

        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE], ['HTTP_X_DORIA_DEVICE' => self::DEVICE]);
        $first = $this->body()['token'];

        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE], ['HTTP_X_DORIA_DEVICE' => self::DEVICE]);
        $second = $this->body()['token'];

        self::assertNotSame($first, $second, 'signing in again mints a new credential');

        // One handset, one row: the header scoped the token exactly as a body
        // field would have.
        $this->em->clear();
        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => 'w.mbise@example.test']);
        self::assertInstanceOf(User::class, $owner);
        self::assertCount(1, $this->em->getRepository(\Uhifadhi\Bundle\TeamBundle\Entity\ApiToken::class)->findBy(['owner' => $owner]));
    }

    /**
     * @return \Generator<string, array{string, int, string}>
     */
    public static function malformedRequests(): \Generator
    {
        yield 'not json at all' => ['not json', Response::HTTP_BAD_REQUEST, 'invalid_request'];
        yield 'a json array' => ['["sl-0142"]', Response::HTTP_BAD_REQUEST, 'invalid_request'];
        yield 'no identifier' => ['{"passcode":"x"}', Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_payload'];
        yield 'a blank identifier' => ['{"rangerId":"","passcode":"x"}', Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_payload'];
        yield 'no passcode' => ['{"rangerId":"sl-0142"}', Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_payload'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRequests')]
    public function testAMalformedRequestIsAnsweredInTheSameErrorDocument(string $raw, int $status, string $code): void
    {
        $this->client->request('POST', self::ENDPOINT, server: ['CONTENT_TYPE' => 'application/json'], content: $raw);

        self::assertSame($status, $this->client->getResponse()->getStatusCode());

        $body = $this->body();
        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($body));
        self::assertSame($code, $body['code']);
        self::assertFalse($body['retryable']);
    }

    /**
     * A TIER HOLDS EVERY PERMISSION, and the endpoint says so rather than
     * leaving a client to discover it on its first upload.
     */
    public function testATierHoldsTheWholeCatalogue(): void
    {
        $boss = new User()
            ->setEmail('n.kileo@example.test')
            ->setFirstName('Naomi')
            ->setLastName('Kileo')
            ->setRangerCode('sl-0001')
            ->setTeamRole(TeamRoleEnum::SuperAdmin);
        $boss->setPassword($this->hash($boss));
        $this->em->persist($boss);
        $this->em->flush();

        $this->post(['rangerId' => 'sl-0001', 'passcode' => self::PASSCODE]);

        $body = $this->body();
        self::assertIsArray($body['permissions']);
        self::assertContains('directory.manage', $body['permissions']);
        self::assertContains('positions.configure', $body['permissions']);
        self::assertIsArray($body['ranger']);
        self::assertSame('Super Admin', $body['ranger']['role'], 'the tier names the unfiled');
    }

    /**
     * @param array<string, string> $payload
     * @param array<string, string> $server
     */
    private function post(array $payload, array $server = []): void
    {
        $this->client->request(
            'POST',
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json', ...$server],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function ranger(): User
    {
        $position = new Position()->setName('Ranger');
        $position->setGrantValues(['areas.read'], ['areas.read']);
        $position->setGrantValues(['directory.read'], ['directory.read']);

        $user = new User()
            ->setEmail('w.mbise@example.test')
            ->setFirstName('Witness')
            ->setLastName('Mbise')
            ->setRangerCode('sl-0142')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);
        $user->setPassword($this->hash($user));

        $this->em->persist($position);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function hash(User $user): string
    {
        $hasher = static::getContainer()->get('test_public.hasher');
        \assert($hasher instanceof \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface);

        return $hasher->hashPassword($user, self::PASSCODE);
    }
}
