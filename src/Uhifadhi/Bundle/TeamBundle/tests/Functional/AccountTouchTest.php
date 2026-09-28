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
 * WHO ACTS ON AN ACCOUNT (28 Sep 2026, from the ruling that only a Super
 * Admin makes or touches a Super Admin).
 *
 * Found while hiding the tiers: the account actions — editing the name and
 * email, a reset link, a resent invitation, deactivating and reactivating —
 * checked only the area a person is placed in. So a staff member holding
 * `directory.manage` across the organization could change an Admin's email
 * and send it a reset link, and an Admin could deactivate a Super Admin.
 * Now a Super Admin acts on anybody, an Admin on Admins and Staff, and Staff
 * on Staff alone.
 */
final class AccountTouchTest extends WebTestCaseWithSchema
{
    /** @var array{super: User, admin: User, grace: User, officer: User} */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $super = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Baraka', 'Laizer', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $officer = $this->person('Joseph', 'Mrema');
        $officer->setPosition($this->position('Personnel Officer', ['directory.read', 'directory.manage', 'personal-details.read', 'personal-details.manage']));
        $this->place($officer);
        $this->em->flush();
        $this->people = ['super' => $super, 'admin' => $admin, 'grace' => $grace, 'officer' => $officer];
    }

    /** @param array<string, string> $fields */
    private function post(User $by, User $member, string $action, array $fields = []): int
    {
        $this->client->loginUser($by);
        $token = $this->tokenFrom('/team/'.$this->people['grace']->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$member->getUuidString().$action, ['_token' => $token, 'return' => 'configure'] + $fields);

        return $this->client->getResponse()->getStatusCode();
    }

    private function isActive(string $email): bool
    {
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $stored);

        return $stored->isActive();
    }

    public function testStaffCannotDeactivateAnAdmin(): void
    {
        self::assertSame(403, $this->post($this->people['officer'], $this->people['admin'], '/deactivate'));
        self::assertTrue($this->isActive('a.mollel@example.test'));
    }

    public function testStaffCannotChangeAnAdminsEmail(): void
    {
        $admin = $this->people['admin'];
        $status = $this->post($this->people['officer'], $admin, '', ['firstName' => 'Asha', 'lastName' => 'Mollel', 'email' => 'taken@example.test', 'rangerCode' => '']);
        self::assertSame(403, $status);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => 'a.mollel@example.test']));
    }

    public function testStaffCannotSendAnAdminAResetLink(): void
    {
        self::assertSame(403, $this->post($this->people['officer'], $this->people['admin'], '/reset-link'));
    }

    public function testAnAdminCannotDeactivateASuperAdmin(): void
    {
        self::assertSame(403, $this->post($this->people['admin'], $this->people['super'], '/deactivate'));
        self::assertTrue($this->isActive('n.kileo@example.test'));
    }

    public function testAnAdminDeactivatesAnotherAdmin(): void
    {
        $other = $this->person('Paulo', 'Sanka', TeamRoleEnum::Admin);
        $this->em->flush();

        self::assertSame(302, $this->post($this->people['admin'], $other, '/deactivate'));
        self::assertFalse($this->isActive('p.sanka@example.test'));
    }

    public function testStaffStillDeactivateStaff(): void
    {
        self::assertSame(302, $this->post($this->people['officer'], $this->people['grace'], '/deactivate'));
        self::assertFalse($this->isActive('g.ndosi@example.test'));
    }

    public function testStaffSeeNoAccountControlsOnAnAdminsPage(): void
    {
        $this->client->loginUser($this->people['officer']);

        $crawler = $this->client->request('GET', '/team/'.$this->people['admin']->getUuidString().'/configure');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/deactivate"]'), 'no Deactivate');
        self::assertCount(0, $crawler->filter('form[action$="/reset-link"]'), 'no reset link');
        self::assertCount(0, $crawler->filter('button[form="signin-form"][type="submit"]'), 'no Save the sign-in');
    }
}
