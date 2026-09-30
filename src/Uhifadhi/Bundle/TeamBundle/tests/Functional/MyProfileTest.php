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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\ApiToken;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\Mail;

/**
 * MY PROFILE (ruled 30 Sep, #69, design B with Details above Sign-in): the
 * signed-in person changes their own details, password and address, and signs
 * a phone out - nobody else's, and nothing an Admin sets.
 */
final class MyProfileTest extends WebTestCaseWithSchema
{
    private User $naserian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->naserian = $this->person('Naserian', 'Lekishon');
        $hasher = static::getContainer()->get('test_public.hasher');
        \assert($hasher instanceof UserPasswordHasherInterface);
        $this->naserian->setPassword($hasher->hashPassword($this->naserian, 'the old passphrase'));
        $this->em->flush();
        $this->client->loginUser($this->naserian);
    }

    public function testThePageCarriesTheCardsInTheRuledOrder(): void
    {
        $page = $this->client->request('GET', '/me/profile');

        self::assertResponseIsSuccessful();
        self::assertSame('Naserian Lekishon', $page->filter('h1')->text());
        self::assertSame(['Details', 'Sign-in', 'Signed in'], $this->tabsOf($page->filter('.recgrid .col')->eq(0)));
        self::assertContains('What you hold', $this->tabsOf($page->filter('.recgrid .col')->eq(1)));
        self::assertSame('/me/profile', $page->filter('.umenu-pop a')->first()->attr('href'), 'the name in the top bar opens a menu whose first item is My profile');
    }

    public function testDetailsAreSaved(): void
    {
        $page = $this->client->request('GET', '/me/profile');
        $this->client->submit($page->filter('form[action$="/me/profile/details"]')->form(['firstName' => 'Naserian', 'lastName' => 'Lekishon-Mollel', 'phone' => '+255 754 300 142']));

        self::assertResponseRedirects('/me/profile');
        $this->em->clear();
        $stored = $this->stored();
        self::assertSame('Lekishon-Mollel', $stored->getLastName());
        self::assertSame('+255 754 300 142', $stored->getPhone());
    }

    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $this->changePassword('not the passphrase', 'a brand new passphrase');

        self::assertStringContainsString('not your current password', $this->client->followRedirect()->filter('.flashes')->text());
        self::assertTrue($this->verifies('the old passphrase'));
    }

    public function testTheRightCurrentPasswordChangesItAndTheSessionSurvives(): void
    {
        $this->changePassword('the old passphrase', 'a brand new passphrase');

        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Your password is changed', $page->filter('.flashes')->text());
        self::assertTrue($this->verifies('a brand new passphrase'));
    }

    public function testANewAddressWaitsForTheLinkThenTakesOver(): void
    {
        $sent = $this->mailerThatKeeps();
        $page = $this->client->request('GET', '/me/profile');
        $this->client->submit($page->filter('form[action$="/me/profile/email"]')->form(['email' => 'naserian@example.test']));

        self::assertSame('n.lekishon@example.test', $this->stored()->getEmail(), 'sign-in stays on the old address');
        self::assertCount(1, $sent->messages);
        $letter = $sent->messages[0];
        self::assertInstanceOf(Email::class, $letter);
        self::assertSame('naserian@example.test', $letter->getTo()[0]->getAddress());
        $link = preg_match('#/me/profile/email/([a-f0-9]{64})#', (string) $letter->getTextBody(), $m) ? $m[1] : null;
        self::assertNotNull($link, 'the letter carries the confirming link');

        $this->client->request('GET', '/me/profile/email/'.$link);

        self::assertResponseRedirects('/me/profile');
        self::assertSame('naserian@example.test', $this->stored()->getEmail());
    }

    public function testYourOwnPhoneSignsOutAndNobodyElses(): void
    {
        $mine = new ApiToken($this->naserian, str_repeat('a', 64), new \DateTimeImmutable('+30 days'));
        $grace = $this->person('Grace', 'Ndosi');
        $theirs = new ApiToken($grace, str_repeat('b', 64), new \DateTimeImmutable('+30 days'));
        $this->em->persist($mine);
        $this->em->persist($theirs);
        $this->em->flush();
        $token = $this->tokenFrom('/me/profile');

        $this->client->request('POST', '/me/profile/handsets/'.$theirs->getId().'/sign-out', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/me/profile/handsets/'.$mine->getId().'/sign-out', ['_token' => $token]);
        self::assertResponseRedirects('/me/profile');
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(ApiToken::class)->find($mine->getId())?->getRevokedAt());
        self::assertNull($this->em->getRepository(ApiToken::class)->find($theirs->getId())?->getRevokedAt());
    }

    private function changePassword(string $current, string $new): void
    {
        $page = $this->client->request('GET', '/me/profile');
        $this->client->submit($page->filter('form[action$="/me/profile/password"]')->form(['currentPassword' => $current, 'newPassword' => $new, 'again' => $new]));
    }

    private function verifies(string $password): bool
    {
        $hasher = static::getContainer()->get('test_public.hasher');
        \assert($hasher instanceof UserPasswordHasherInterface);

        return $hasher->isPasswordValid($this->stored(), $password);
    }

    private function stored(): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($this->naserian->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return list<string> */
    private function tabsOf(Crawler $column): array
    {
        return $column->filter('.c > .tab')->each(static fn (Crawler $c): string => trim(str_replace($c->filter('.src')->text(''), '', $c->text())));
    }

    /** @return object{messages: list<RawMessage>} */
    private function mailerThatKeeps(): object
    {
        $kept = new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
        $this->client->disableReboot();
        static::getContainer()->set('team.mail', new Mail($kept, 'no-reply@example.test', 'Uhifadhi Nature Reserves'));

        return $kept;
    }
}
