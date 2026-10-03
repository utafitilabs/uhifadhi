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

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * AN OPEN SESSION FOLLOWS THE ACCOUNT. Every request reads the account back
 * from the database and compares it with the one the session holds; when they
 * differ the session ends. Deactivating somebody, changing their tier, their
 * roles or their password must therefore end a session already open — not at
 * its expiry. All of it through the real firewall, on a page that asks no
 * permission, because that is where a stale session would otherwise survive.
 *
 * @see https://symfony.com/doc/current/security.html#understanding-how-users-are-refreshed-from-the-session
 * @see vendor/symfony/security-http/Firewall/ContextListener.php — hasUserChanged()
 */
final class OpenSessionFollowsTheAccountTest extends WebTestCaseWithSchema
{
    private User $grace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($this->grace);
        $this->client->request('GET', '/me/profile');
        self::assertResponseIsSuccessful('signed in, the page opens');
    }

    public function testDeactivationEndsAnOpenSession(): void
    {
        $this->stored()->deactivate();
        $this->em->flush();

        $this->client->request('GET', '/me/profile');

        self::assertResponseRedirects('/login', null, 'a deactivated account keeps nothing it opened before');
    }

    public function testATierChangeEndsAnOpenSession(): void
    {
        $this->stored()->setTeamRole(TeamRoleEnum::Admin);
        $this->em->flush();

        $this->client->request('GET', '/me/profile');

        self::assertResponseRedirects('/login');
    }

    public function testAChangeToTheStoredRolesEndsAnOpenSession(): void
    {
        $this->stored()->setRoles(['ROLE_AUDITOR']);
        $this->em->flush();

        $this->client->request('GET', '/me/profile');

        self::assertResponseRedirects('/login');
    }

    public function testAPasswordChangedElsewhereEndsAnOpenSession(): void
    {
        $this->stored()->setPassword('a hash set by somebody else');
        $this->em->flush();

        $this->client->request('GET', '/me/profile');

        self::assertResponseRedirects('/login');
    }

    public function testAnUnchangedAccountKeepsItsSession(): void
    {
        $this->client->request('GET', '/me/profile');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/me/profile');
        self::assertResponseIsSuccessful('the comparison must not sign anybody out on its own');
    }

    private function stored(): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->find($this->grace->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
