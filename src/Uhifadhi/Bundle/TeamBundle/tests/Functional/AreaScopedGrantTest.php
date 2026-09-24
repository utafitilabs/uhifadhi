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

use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * NO PRIVILEGE ESCALATION BY AN AREA ADMINISTRATOR.
 *
 * The person half and the department half are their own suites. This is the
 * escalation half: an area-X administrator may not grant a pair their own
 * position does not hold, and may not confer TEAM ADMINISTRATION at all —
 * `positions.configure` and `departments.configure` are how another
 * administrator is made, which is organization-wide authority and a widening
 * past their own boundary. Nor may a bounded administrator change a person's
 * tier: Super Admin and Admin are organization-wide, and promoting somebody
 * to one is the plainest escalation there is.
 *
 * IT USED TO BE WRITTEN IN FLAT VALUES — one `team.manage` standing for the
 * whole of team administration, and `area.view` for an ordinary capability.
 * The ruling replaced both with (concern, verb) pairs, so the fence is now
 * named by the two pairs above rather than by one word.
 *
 * A tier (Super Admin / Admin), or a `team.manage` holder placed across the
 * whole organization, is UNBOUNDED and touches all of this. WHICH OF THE TWO
 * SOMEBODY IS COMES OFF THEIR PLACEMENT, not off the department their position
 * sat in: the ground is recorded against the person now, and an administrator
 * placed at one area is the bounded one.
 *
 * WHAT THEY MAY CONFER IS STILL READ OFF THEIR POSITION, because that is where
 * permissions live; the placement decides whether the fence applies at all.
 * Enforcement is server-side (a 403), exactly as the assignment and department
 * controllers do it; the matrix additionally draws the ungrantable rows
 * disabled, matching the "no wider-than-self grant" guard the area-admin design
 * draws.
 */
final class AreaScopedGrantTest extends WebTestCaseWithSchema
{
    // ---- the permission matrix: grant width -------------------------------

    /** A bounded admin may grant a permission their OWN position holds. */
    public function testAnAreaAdminMayGrantAPermissionTheyThemselvesHold(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        $ranger = $this->position('Ranger', []);
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token, 'grants' => ['directory.read'],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(['directory.read'], $stored->getGrantValues());
    }

    /** But NOT a permission their own position does not hold — that widens power. */
    public function testAnAreaAdminCannotGrantAPermissionBeyondTheirOwnAuthority(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        $ranger = $this->position('Ranger', []);
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token, 'grants' => ['surveys.read'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger'])?->getGrantValues());
    }

    /** And NEVER team.manage — conferring team administration is an unbounded act. */
    public function testAnAreaAdminCannotConferTeamManage(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        $deputy = $this->position('Deputy Warden', []);
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$deputy->getUuidString().'/permissions', [
            '_token' => $token, 'grants' => ['positions.configure'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Position::class)->findOneBy(['name' => 'Deputy Warden'])?->getGrantValues());
    }

    /**
     * A bounded save neither adds NOR strips a permission beyond the admin's
     * authority: what the position already held past their reach is frozen, so an
     * unrelated save cannot silently revoke it (nor is it a way around the fence).
     */
    public function testABoundedSaveFreezesPermissionsBeyondTheAdminsAuthority(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        // The position already carries a permission the admin does not hold.
        $ranger = $this->position('Ranger', ['surveys.read']);
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token, 'grants' => ['directory.read'],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertContains('directory.read', $stored->getGrantValues(), 'The grantable tick was saved.');
        self::assertContains('surveys.read', $stored->getGrantValues(), 'The untouchable grant was not stripped.');
    }

    /** The matrix draws the rows beyond the admin's authority disabled, with the guard note. */
    public function testTheMatrixDisablesPermissionsBeyondTheAdminsAuthority(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        $ranger = $this->position('Ranger', []);
        $this->em->flush();

        // THE MATRIX IS ON THE POSITION'S CONFIGURE PAGE NOW, one position
        // at a time, rather than on the register behind a query parameter.
        $crawler = $this->client->request('GET', '/team/positions/'.$ranger->getUuidString().'/configure');

        // A pair the admin holds is grantable — its box is enabled.
        self::assertCount(0, $crawler->filter('input[name="grants[]"][value="directory.read"][disabled]'), 'A pair the admin holds is grantable.');
        // One they do not hold is disabled, and so is team administration
        // even though they DO hold it: conferring it mints another
        // administrator, which is an organization-wide act.
        self::assertCount(1, $crawler->filter('input[name="grants[]"][value="surveys.read"][disabled]'), 'A pair beyond their authority is disabled.');
        self::assertCount(1, $crawler->filter('input[name="grants[]"][value="positions.configure"][disabled]'), 'Team administration is never grantable by a bounded administrator.');
    }

    /** A holder placed across the organization is unbounded: they grant anything, team.manage included. */
    public function testAnOrganizationWideAdminMayGrantAnything(): void
    {
        $north = $this->area('Northern Reserve');
        $orgAdmin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $orgAdmin->setPosition($this->position('Coordinator', self::ADMINISTRATOR));
        $this->place($orgAdmin);
        $ranger = $this->position('Ranger', []);
        $this->em->flush();
        $this->client->loginUser($orgAdmin);

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token, 'grants' => ['surveys.read', 'positions.configure'],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(['surveys.read', 'positions.configure'], $stored->getGrantValues());
    }

    // ---- the member record: changing a tier -------------------------------

    /** A bounded admin may not change a person's tier — Super Admin / Admin are org-wide. */
    public function testAnAreaAdminCannotChangeAPersonsTier(): void
    {
        $north = $this->area('Northern Reserve');
        $this->areaAdminHolding($north);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/tier', [
            '_token' => $token, 'tier' => TeamRoleEnum::Admin->value,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(TeamRoleEnum::Staff, $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test'])?->getTeamRole());
    }

    /** A holder placed across the organization is unbounded: they may change a tier. */
    public function testAnOrganizationWideAdminMayChangeATier(): void
    {
        $orgAdmin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $orgAdmin->setPosition($this->position('Coordinator', self::ADMINISTRATOR));
        $this->place($orgAdmin);
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();
        $this->client->loginUser($orgAdmin);

        $token = $this->tokenFrom('/team/'.$grace->getUuidString().'/configure');
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/tier', [
            '_token' => $token, 'tier' => TeamRoleEnum::Admin->value,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(TeamRoleEnum::Admin, $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test'])?->getTeamRole());
    }

    // ---- the cast ---------------------------------------------------------

    /**
     * WHAT TEAM ADMINISTRATION IS, IN PAIRS — the eight the upgrade backfills
     * the old single `team.manage` into. Spelled out rather than read from
     * the catalogue: a fixture that asked the catalogue what an administrator
     * holds would agree with itself however the declarations drifted.
     */
    private const array ADMINISTRATOR = [
        'directory.read', 'directory.manage',
        'personal-details.read', 'personal-details.manage',
        'positions.read', 'positions.configure',
        'departments.read', 'departments.configure',
    ];

    /**
     * Sign in as an AREA-X administrator: a Staff member holding team
     * administration and PLACED at $area, so they are the bounded kind, and
     * what they may confer is what they themselves hold.
     *
     * THEY HOLD `directory.read` AND NOT `surveys.read`, which is what the
     * two halves of the fence are tested with — one pair they may pass on,
     * one they may not.
     */
    private function areaAdminHolding(HostArea $area): User
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Staff);
        $admin->setPosition($this->position('Warden', self::ADMINISTRATOR));
        $this->place($admin, [$area]);
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
    }
}
