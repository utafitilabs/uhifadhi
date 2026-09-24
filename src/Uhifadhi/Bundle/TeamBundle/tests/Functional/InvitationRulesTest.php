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

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Controller\InviteController;
use Uhifadhi\Bundle\TeamBundle\Controller\PasswordResetController;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE INVITATION RULES, APPLIED — what the People configure section sets is
 * what every invitation is sent under.
 */
#[CoversClass(InviteController::class)]
#[CoversClass(PasswordResetController::class)]
#[CoversClass(UserService::class)]
final class InvitationRulesTest extends WebTestCaseWithSchema
{
    /** With the rule at "invitation only" the password path is withheld and refused. */
    public function testInvitationOnlyWithholdsAndRefusesThePasswordPath(): void
    {
        $this->administrator();
        // The token is minted while the path is still offered; the rule then flips under it.
        $token = $this->tokenFrom('/team/invite', 'form[action$="/team"] input[name="_token"]');
        $this->settings()->setInvitationRules(7, InvitationUnitEnum::Days, 1, false);

        $crawler = $this->client->request('GET', '/team/invite');
        self::assertCount(0, $crawler->filter('form[action$="/team"]'), 'the create-with-a-password form is withheld');
        self::assertStringContainsString('invitation only', $crawler->filter('.iv-ways')->text());

        $this->client->request('POST', '/team', [
            '_token' => $token, 'email' => 'a.person@example.test', 'firstName' => 'A', 'lastName' => 'Person', 'password' => 'twelve-letters',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('invitation only', $this->client->followRedirect()->filter('.flashes')->text());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'a.person@example.test']));
    }

    /** The default rule offers the password path, as before. */
    public function testAllowedOffersThePasswordPath(): void
    {
        $this->administrator();

        self::assertCount(1, $this->client->request('GET', '/team/invite')->filter('form[action$="/team"]'));
    }

    /** An invitation is stamped with the validity in force when it is sent. */
    public function testAnInvitationIsStampedWithTheValidityInForce(): void
    {
        $this->administrator();
        $this->settings()->setInvitationRules(36, InvitationUnitEnum::Hours, 1, true);

        $before = new \DateTimeImmutable();
        $invited = $this->accounts()->invite('n.person@example.test', null, null);

        $expires = $invited->getInvitationExpiresAt();
        self::assertInstanceOf(\DateTimeImmutable::class, $expires);
        $hours = ($expires->getTimestamp() - $before->getTimestamp()) / 3600;
        self::assertGreaterThan(35.9, $hours);
        self::assertLessThan(36.1, $hours);
    }

    /** A link past its validity is stale, and stays stale on the POST. */
    public function testALinkPastItsValidityIsStale(): void
    {
        $invited = $this->accounts()->invite('n.person@example.test', null, null);
        $token = (string) $invited->getVerificationToken();
        // The token is minted while the link is open; it then lapses under it.
        $csrf = $this->tokenFrom('/invite/'.$token, 'form input[name="_token"]');
        $invited->setInvitationExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/invite/'.$token);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form input[name="password"]'));

        $this->client->request('POST', '/invite/'.$token, ['_token' => $csrf, 'name' => 'N Person', 'password' => 'twelve-letters']);
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.person@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->isVerified());
    }

    /** A link inside its validity opens. */
    public function testALinkInsideItsValidityOpens(): void
    {
        $invited = $this->accounts()->invite('n.person@example.test', null, null);
        $token = (string) $invited->getVerificationToken();

        $crawler = $this->client->request('GET', '/invite/'.$token);
        self::assertCount(1, $crawler->filter('form input[name="password"]'));
    }

    private function settings(): TeamSettingsService
    {
        $settings = static::getContainer()->get('test_public.'.TeamSettingsService::class);
        \assert($settings instanceof TeamSettingsService);

        return $settings;
    }

    private function accounts(): UserService
    {
        $accounts = static::getContainer()->get('test_public.'.UserService::class);
        \assert($accounts instanceof UserService);

        return $accounts;
    }
}
