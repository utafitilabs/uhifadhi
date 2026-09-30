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

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * §5.6, DESIGN FIDELITY — THE "SCOPED TO <AREA>" STATEMENT.
 *
 * The area-admin design draws a banner ("You are scoped to Southern Reserve") on the
 * team / department / position management chrome, telling a bounded admin WHICH
 * ground bounds them. It is shown to a bounded (area-X) `team.manage` holder on
 * every management surface, and to nobody else — an unbounded holder (a tier,
 * or somebody placed across the whole organization) manages every area, so
 * there is no one ground to name. The area is named through the contract's
 * AreaInterface::getName().
 *
 * THE GROUND COMES OFF THE PLACEMENT, and a placement may name several areas,
 * so the banner states the first plus a count rather than one name — one line,
 * with the full list on the person's own record.
 */
final class AreaScopedBannerTest extends WebTestCaseWithSchema
{
    /**
     * A bounded admin sees the banner, naming their authority-area, on every
     * management surface — the roster, the record, the invite page, the positions
     * matrix, and the departments manager.
     *
     * @return iterable<string, array{string}>
     */
    public static function managementChrome(): iterable
    {
        yield 'roster' => ['/team'];
        // No invite: adding somebody is the tiers' alone (ruled 30 Sep, #67),
        // so a bounded seat never reaches that page to be told its area.
        yield 'positions' => ['/team/positions'];
        yield 'departments' => ['/departments'];
    }

    #[DataProvider('managementChrome')]
    public function testTheBannerNamesTheAreaForABoundedAdmin(string $path): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('You are scoped to Northern Reserve', $crawler->filter('.scope-fence')->html());
    }

    /** And on one person's record, the other management surface. */
    public function testTheBannerShowsOnTheMemberRecordForABoundedAdmin(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('You are scoped to Northern Reserve', $crawler->filter('.scope-fence')->html());
    }

    /** Placed at more than one area, the banner states the first plus a count. */
    public function testTheBannerCountsTheRestWhenAPlacementNamesSeveralAreas(): void
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Staff);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->place($admin, [$this->area('Northern Reserve'), $this->area('Western Reserve')]);
        $this->em->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('You are scoped to Northern Reserve +1', $crawler->filter('.scope-fence')->html());
    }

    /** A tier (Super Admin) is unbounded — no banner, there is no one ground. */
    public function testATierSeesNoBanner(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.scope-fence'));
    }

    /** A team.manage holder placed across the organization is unbounded too — no banner. */
    public function testAnOrganizationWideHolderSeesNoBanner(): void
    {
        $orgAdmin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $orgAdmin->setPosition($this->administratorPosition('Coordinator'));
        $this->place($orgAdmin);
        $this->em->flush();
        $this->client->loginUser($orgAdmin);

        $crawler = $this->client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.scope-fence'));
    }

    /**
     * Sign in as an AREA-X administrator — a Staff member holding team.manage
     * through their position and PLACED at $area, which is the ground the
     * banner names.
     */
    private function areaAdminIn(HostArea $area): User
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Staff);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->place($admin, [$area]);
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    /**
     * WHAT ADMINISTERING THE TEAM IS, WRITTEN AS PAIRS. `team.manage` was one
     * flat value; it is eight (concern, verb) pairs now, and these are the
     * eight the upgrade backfills it into, so a fixture that used to say
     * "this person administers the team" still says exactly that.
     */
    private function administratorPosition(string $name): Position
    {
        return $this->position($name, [
            'directory.read',
            'directory.manage',
            'personal-details.read',
            'personal-details.manage',
            'positions.read',
            'positions.configure',
            'departments.read',
            'departments.configure',
        ]);
    }
}
