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
 * BOTH DOORS ARE THROTTLED, and neither is throttled by the other's mechanism.
 *
 * The web form has a firewall in front of it, so its throttling is the
 * firewall's: `login_throttling` in the installation's own security file, five
 * attempts a minute. The field endpoint has NO firewall — deliberately, because
 * a stale token must never be what stops somebody signing in again — so nothing
 * upstream can count for it, and it counts for itself: five attempts a minute
 * per identifier, twenty per address.
 *
 * TWO LIMITERS, NOT ONE, and the pair is the point. Per-identifier stops a
 * targeted guess against one person's account; per-address stops a spray across
 * many. Either alone leaves the other attack untouched.
 *
 * COUNTED BEFORE THE CREDENTIAL IS WEIGHED, so a valid credential replayed in a
 * storm is throttled like any other traffic.
 */
final class SignInThrottlingTest extends WebTestCaseWithSchema
{
    private const string ENDPOINT = '/api/auth/token';
    private const string PASSCODE = 'a-real-passcode';

    /**
     * THE FIELD DOOR: the sixth try on one identifier inside the window is
     * refused, in the same document every other failure uses — and `retryable`
     * is TRUE here, alone among the refusals, because waiting genuinely helps.
     */
    public function testASixthAttemptOnOneIdentifierInsideAMinuteIsThrottled(): void
    {
        $this->ranger();

        for ($i = 1; $i <= 5; ++$i) {
            $this->post(['rangerId' => 'sl-0142', 'passcode' => 'not-the-passcode']);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'attempt '.$i.' is an ordinary refusal');
        }

        $this->post(['rangerId' => 'sl-0142', 'passcode' => 'not-the-passcode']);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($this->body()));
        self::assertSame('rate_limited', $this->body()['code']);
        self::assertTrue($this->body()['retryable']);
    }

    /** A correct passcode does not buy a fresh budget: the count is of attempts. */
    public function testAValidCredentialReplayedInAStormIsThrottledToo(): void
    {
        $this->ranger();

        for ($i = 1; $i <= 5; ++$i) {
            $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE]);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        }

        $this->post(['rangerId' => 'sl-0142', 'passcode' => self::PASSCODE]);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
    }

    /**
     * THE SPRAY IS THE OTHER LIMITER'S. Twenty a minute from one address, no
     * matter how many identifiers it names — so a run of five attempts against
     * each of five accounts is stopped by the address, which the per-identifier
     * budget never would have seen.
     */
    public function testTwentyAttemptsFromOneAddressAreEnoughWhoeverTheyName(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->post(['rangerId' => 'sl-'.str_pad((string) $i, 4, '0', \STR_PAD_LEFT), 'passcode' => 'guess']);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'attempt '.$i.' is an ordinary refusal');
        }

        $this->post(['rangerId' => 'sl-9999', 'passcode' => 'guess']);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
        self::assertSame('rate_limited', $this->body()['code']);
    }

    /** The identifier is counted case-insensitively; an address is not two accounts. */
    public function testTheIdentifierIsCountedWithoutRegardToCase(): void
    {
        $this->ranger();

        for ($i = 1; $i <= 5; ++$i) {
            $this->post(['rangerId' => 0 === $i % 2 ? 'SL-0142' : 'sl-0142', 'passcode' => 'not-the-passcode']);
        }

        $this->post(['rangerId' => 'sl-0142', 'passcode' => 'not-the-passcode']);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());
    }

    /**
     * THE WEB DOOR, throttled by the firewall rather than by a controller.
     * The test kernel writes the same `login_throttling` line the skeleton's
     * security.yaml ships, so what is proved here is the shape an installation
     * actually has.
     *
     * IT IS NOT A 429, and that difference is right rather than an
     * inconsistency: a person meets the sign-in form again with a sentence
     * saying to wait, where a field client meets a status code it switches on.
     */
    public function testASixthFormSignInInsideAMinuteIsRefusedForBeingTooMany(): void
    {
        $this->ranger();

        for ($i = 1; $i <= 5; ++$i) {
            self::assertStringNotContainsString('Too many failed login attempts', $this->submitSignIn(), 'attempt '.$i.' is an ordinary refusal');
        }

        self::assertStringContainsString('Too many failed login attempts', $this->submitSignIn());
    }

    /** One failed sign-in through the real form, and the page it lands back on. */
    private function submitSignIn(): string
    {
        $form = $this->client->request('GET', '/login')->selectButton('Sign in')->form([
            '_username' => 'w.mbise@example.test',
            '_password' => 'not-the-passcode',
        ]);
        $this->client->submit($form);

        return (string) $this->client->followRedirect()->html();
    }

    /** @param array<string, string> $payload */
    private function post(array $payload): void
    {
        $this->client->request(
            'POST',
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function ranger(): User
    {
        $position = new Position()->setName('Ranger');
        $position->setGrantValues(['areas.read'], ['areas.read']);

        $user = new User()
            ->setEmail('w.mbise@example.test')
            ->setFirstName('Witness')
            ->setLastName('Mbise')
            ->setRangerCode('sl-0142')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setPosition($position);

        $hasher = static::getContainer()->get('test_public.hasher');
        \assert($hasher instanceof \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface);
        $user->setPassword($hasher->hashPassword($user, self::PASSCODE));

        $this->em->persist($position);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
