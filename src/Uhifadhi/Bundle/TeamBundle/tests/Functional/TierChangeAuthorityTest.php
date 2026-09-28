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
 * WHO CHANGES A TIER (fixed 28 Sep 2026, then ruled the same day).
 *
 * Found on staging: an Admin, and any organization-wide holder of
 * `directory.manage`, could make anybody a Super Admin, themselves included.
 * Ruled: Admins make and unmake Admins — peers, as Google Workspace's super
 * admins are — while only a Super Admin makes a Super Admin or touches one,
 * and a position never grants a tier.
 */
final class TierChangeAuthorityTest extends WebTestCaseWithSchema
{
    private function changeTier(User $target, TeamRoleEnum $tier): void
    {
        $token = $this->tokenFrom('/team/'.$target->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$target->getUuidString().'/tier', ['_token' => $token, 'tier' => $tier->value]);
    }

    private function tierOf(string $email): TeamRoleEnum
    {
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $stored);

        return $stored->getTeamRole();
    }

    public function testAnAdminMayNotMakeAnybodyASuperAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->changeTier($grace, TeamRoleEnum::SuperAdmin);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TeamRoleEnum::Staff, $this->tierOf('g.ndosi@example.test'));
    }

    public function testAnAdminMayNotRaiseThemselves(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->changeTier($admin, TeamRoleEnum::SuperAdmin);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TeamRoleEnum::Admin, $this->tierOf('a.mollel@example.test'));
    }

    public function testAnAdminMakesAnotherPersonAnAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->changeTier($grace, TeamRoleEnum::Admin);
        self::assertResponseRedirects();
        self::assertSame(TeamRoleEnum::Admin, $this->tierOf('g.ndosi@example.test'));
    }

    public function testAdminsArePeersAndMayDemoteTheAdminWhoMadeThem(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $asha = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($grace);

        $this->changeTier($asha, TeamRoleEnum::Staff);
        self::assertResponseRedirects();
        self::assertSame(TeamRoleEnum::Staff, $this->tierOf('a.mollel@example.test'));
    }

    public function testAnAdminMayNotChangeASuperAdminsTier(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Baraka', 'Laizer', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->changeTier($naomi, TeamRoleEnum::Admin);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TeamRoleEnum::SuperAdmin, $this->tierOf('n.kileo@example.test'));
    }

    public function testASuperAdminMayDemoteAnotherSuperAdmin(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $baraka = $this->person('Baraka', 'Laizer', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($baraka);

        $this->changeTier($naomi, TeamRoleEnum::Admin);
        self::assertResponseRedirects();
        self::assertSame(TeamRoleEnum::Admin, $this->tierOf('n.kileo@example.test'));
    }

    public function testAnOrganizationWideDirectoryManagerMayNotMakeThemselvesASuperAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $officer = $this->person('Joseph', 'Mrema');
        $officer->setPosition($this->position('Personnel Officer', ['directory.manage', 'directory.read', 'personal-details.manage']));
        $this->place($officer);
        $this->em->flush();
        $this->client->loginUser($officer);

        $this->changeTier($officer, TeamRoleEnum::SuperAdmin);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TeamRoleEnum::Staff, $this->tierOf('j.mrema@example.test'));
    }

    public function testAnAdminSeesTheSuperAdminTierDisabledAndAWarningOnAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        self::assertCount(1, $crawler->filter('.mb-tiers button[name="tier"][value="super_admin"][disabled]'), 'Super Admin is not theirs to give');
        self::assertCount(0, $crawler->filter('.mb-tiers button[name="tier"][value="admin"][disabled]'), 'Admin is');
        self::assertStringContainsString('could remove you as an Admin', $crawler->filter('.mb-tiers')->html(), 'with the warning on it');
    }

    public function testAStaffMemberSeesEveryTierButtonDisabled(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $officer = $this->person('Joseph', 'Mrema');
        $officer->setPosition($this->position('Personnel Officer', ['directory.manage', 'directory.read', 'personal-details.manage']));
        $this->place($officer);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($officer);

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString().'/configure');
        $buttons = $crawler->filter('.mb-tiers button[name="tier"]');
        self::assertGreaterThan(0, $buttons->count());
        self::assertSame($buttons->count(), $crawler->filter('.mb-tiers button[name="tier"][disabled]')->count());
    }

    public function testASuperAdminChangesTiers(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $grace = $this->person('Grace', 'Ndosi');
        $asha = $this->person('Asha', 'Mollel');
        $this->em->flush();
        $this->client->loginUser($naomi);

        $this->changeTier($grace, TeamRoleEnum::Admin);
        self::assertResponseRedirects();
        $this->changeTier($asha, TeamRoleEnum::SuperAdmin);
        self::assertResponseRedirects();

        self::assertSame(TeamRoleEnum::Admin, $this->tierOf('g.ndosi@example.test'));
        self::assertSame(TeamRoleEnum::SuperAdmin, $this->tierOf('a.mollel@example.test'));
    }
}
