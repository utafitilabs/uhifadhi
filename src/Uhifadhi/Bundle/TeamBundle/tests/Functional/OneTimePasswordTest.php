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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;

/**
 * A ONE-TIME PASSWORD, ISSUED BY AN ADMINISTRATOR (ruled 27 Sep 2026).
 *
 * The first row of the account card on a person's configure page. Eight
 * random capitals and digits become the person's password; the card shows
 * them once for the administrator to pass on; the History card records who
 * issued one and when. Only the tiers above the matrix see the row, and only
 * a Super Admin may issue one for an Admin or a Super Admin. A code nobody
 * has used expires after 72 hours; a used code is the person's password
 * until they change it.
 */
final class OneTimePasswordTest extends WebTestCaseWithSchema
{
    private function admin(): User
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    private function ranger(): User
    {
        $grace = $this->person('Grace', 'Ndosi')->setRangerCode('gn-0101');
        $this->em->flush();

        return $grace;
    }

    /** Issues a code for somebody from their configure page and reads it off the card. */
    private function issue(User $person): string
    {
        $url = '/team/'.$person->getUuidString().'/configure';
        $token = $this->tokenFrom($url);
        $this->client->request('POST', '/team/'.$person->getUuidString().'/one-time-password', ['_token' => $token, 'return' => 'configure']);
        self::assertResponseRedirects($url);

        $crawler = $this->client->followRedirect();
        $shown = $crawler->filter('[data-one-time-password]');
        self::assertCount(1, $shown, 'the card shows the code');

        return trim($shown->text());
    }

    private function fieldSignIn(string $rangerId, string $passcode): int
    {
        $this->client->request(
            'POST',
            '/api/auth/token',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['rangerId' => $rangerId, 'passcode' => $passcode], \JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse()->getStatusCode();
    }

    public function testAnAdministratorIssuesACodeTheCardShowsOnce(): void
    {
        $this->admin();
        $grace = $this->ranger();

        $code = $this->issue($grace);

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code, 'eight capitals and digits, no look-alikes');
        self::assertCount(1, $this->client->getCrawler()->filter('[data-controller="uhifadhi--team-bundle--copy"] button[data-copy-button][data-action="uhifadhi--team-bundle--copy#copy"]'), 'a Copy button sits beside the code');
        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertCount(0, $crawler->filter('[data-one-time-password]'), 'shown once: gone on the next visit');

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($stored, $code), 'the code is the password, stored hashed');
        self::assertStringNotContainsString($code, (string) $stored->getPassword());
    }

    public function testTheCodeSignsInOnThePhone(): void
    {
        $this->admin();
        $grace = $this->ranger();
        $code = $this->issue($grace);

        self::assertSame(Response::HTTP_OK, $this->fieldSignIn('gn-0101', $code));
    }

    public function testTheRowIsTheFirstOnTheAccountCard(): void
    {
        $this->admin();
        $grace = $this->ranger();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        $rows = $crawler->filter('.mb-danger .mb-drow .dt b')->each(static fn (Crawler $c): string => $c->text());

        self::assertSame('Issue a one-time password', $rows[0] ?? null);
    }

    public function testSomebodyBelowTheMatrixNeitherSeesNorIssuesOne(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $officer = $this->person('Joseph', 'Mrema');
        $officer->setPosition($this->position('Personnel Officer', ['personal-details.manage', 'directory.manage', 'directory.read']));
        $this->place($officer);
        $grace = $this->ranger();
        $this->em->flush();
        $this->client->loginUser($officer);

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('one-time password', $crawler->html());

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/one-time-password', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdminMayNotIssueOneForASuperAdmin(): void
    {
        $this->admin();
        $superAdmin = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $superAdmin);

        // No page to issue it from (only a Super Admin configures a Super Admin),
        // and the route refuses a token carried from another page.
        $this->client->request('GET', '/team/'.$superAdmin->getUuidString().'/configure');
        self::assertResponseStatusCodeSame(403);

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'a.mollel@example.test']);
        self::assertInstanceOf(User::class, $admin);
        $token = $this->tokenFrom('/team/'.$admin->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$superAdmin->getUuidString().'/one-time-password', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    /** Admins are peers (ruled 28 Sep 2026): one issues a code for another. */
    public function testAnAdminIssuesOneForAnotherAdmin(): void
    {
        $this->admin();
        $otherAdmin = $this->person('Baraka', 'Laizer', TeamRoleEnum::Admin);
        $this->em->flush();

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $this->issue($otherAdmin));
    }

    public function testASuperAdminMayIssueOneForAnAdmin(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $this->issue($admin));
    }

    public function testAnUnusedCodeExpiresAfterSeventyTwoHours(): void
    {
        $this->admin();
        $grace = $this->ranger();
        $code = $this->issue($grace);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        $stored->markOneTimePassword(new \DateTimeImmutable('-73 hours'));
        $this->em->flush();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->fieldSignIn('gn-0101', $code), 'an unused code older than 72 hours is refused');
    }

    public function testAUsedCodeStaysThePasswordUntilItIsChanged(): void
    {
        $this->admin();
        $grace = $this->ranger();
        $code = $this->issue($grace);

        self::assertSame(Response::HTTP_OK, $this->fieldSignIn('gn-0101', $code), 'first sign-in uses it');

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertNull($stored->getOneTimePasswordIssuedAt(), 'a used code is no longer pending');

        self::assertSame(Response::HTTP_OK, $this->fieldSignIn('gn-0101', $code), 'and keeps working after 72 hours, because it no longer expires');
    }

    public function testChoosingAPasswordEndsTheOneTimePassword(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $grace->markOneTimePassword(new \DateTimeImmutable('-1 hour'));
        $grace->setPassword('a-hash');

        self::assertNull($grace->getOneTimePasswordIssuedAt());
    }

    public function testTheHistoryNamesWhoIssuedIt(): void
    {
        $this->admin();
        $grace = $this->ranger();
        $this->issue($grace);

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        $lines = $crawler->filter('.hlist .hrow')->each(static fn (Crawler $c): string => $c->text());

        self::assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_contains($l, 'One-time password issued') && str_contains($l, 'A. Mollel')), implode(' | ', $lines));
    }

    public function testTheCodeIsEightCharactersFromTheReadableAlphabet(): void
    {
        $service = static::getContainer()->get('test_public.'.OneTimePasswordService::class);
        self::assertInstanceOf(OneTimePasswordService::class, $service);

        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $code = $service->generate();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
            $seen[$code] = true;
        }
        self::assertCount(200, $seen, 'random, not derived from the clock');
    }
}
