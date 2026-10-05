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
 * WHO SEES A TIER (ruled 28 Sep 2026).
 *
 * Admins and Super Admins see every person's tier, their own and each
 * other's, on every surface. Staff see no tier anywhere — not a pill, a
 * column, a count, a filter or a sentence naming one — whatever their
 * position lets them read or manage: below the matrix a person works by rank
 * and position, and a list of who holds the top tiers is a list of targets.
 */
final class TierSightTest extends WebTestCaseWithSchema
{
    /** @var array{super: User, admin: User, grace: User, officer: User} */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $super = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $admin = $this->person('Asha', 'Mollel', TeamRoleEnum::Admin);
        $grace = $this->person('Grace', 'Ndosi');
        $officer = $this->person('Joseph', 'Mrema');
        $officer->setPosition($this->position('Personnel Officer', [
            'directory.read', 'directory.manage', 'directory.export',
            'personal-details.read', 'personal-details.manage', 'positions.read',
        ]));
        $this->place($officer);
        $this->em->flush();
        $this->people = ['super' => $super, 'admin' => $admin, 'grace' => $grace, 'officer' => $officer];
    }

    /** @return list<string> */
    private function pages(): array
    {
        $p = $this->people;

        return [
            '/team',
            '/team?tier=super_admin',
            '/team/overview',
            '/team/positions',
            '/team/'.$p['super']->getUuidString(),
            '/team/'.$p['admin']->getUuidString(),
            '/team/'.$p['super']->getUuidString().'/configure',
            '/team/'.$p['admin']->getUuidString().'/configure',
            '/team/'.$p['grace']->getUuidString().'/configure',
        ];
    }

    public function testStaffSeeNoTierOnAnyTeamPage(): void
    {
        $this->client->loginUser($this->people['officer']);

        foreach ($this->pages() as $url) {
            $this->client->request('GET', $url);
            $status = $this->client->getResponse()->getStatusCode();
            self::assertContains($status, [200, 403, 404], $url);
            if (200 !== $status) {
                continue;
            }
            $body = (string) $this->client->getResponse()->getContent();
            foreach (['Super Admin', 'Super admin', 'by tier', 'class="tier ', 'Above the matrix'] as $tell) {
                self::assertStringNotContainsString($tell, $body, $url.' names a tier ('.$tell.')');
            }
            self::assertDoesNotMatchRegularExpression('/>\s*(Admin|Staff)\s*</', $body, $url.' draws a tier as a label');
        }
    }

    public function testStaffCannotFilterPeopleByTier(): void
    {
        $this->client->loginUser($this->people['officer']);

        $crawler = $this->client->request('GET', '/team?tier=super_admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Grace', $crawler->filter('main')->text(), 'the tier filter is ignored, not applied');
    }

    public function testTheStaffExportCarriesNoTierColumn(): void
    {
        $this->client->loginUser($this->people['officer']);

        $this->client->request('GET', '/team/people.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString('Super Admin', $csv);
        self::assertStringNotContainsString('Tier', strtok($csv, "\n") ?: '');
    }

    /**
     * A SUPER ADMIN'S TIER IS FOR SUPER ADMINS (ruled 28 Sep 2026, later the
     * same day): an Admin sees a Super Admin as a rank and a position — no
     * pill, chip, count, filter, by-tier line or fact naming the tier.
     */
    public function testAnAdminSeesNoSuperAdminTierAnywhere(): void
    {
        $this->client->loginUser($this->people['admin']);
        $super = $this->people['super']->getUuidString();

        foreach (['/team', '/team?tier=super_admin', '/team/overview', '/team/positions', '/team/'.$super] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $body = (string) $this->client->getResponse()->getContent();
            foreach (['Super Admin', 'Super admin', 't-super'] as $tell) {
                self::assertStringNotContainsString($tell, $body, $url.' names the Super Admin tier ('.$tell.')');
            }
        }
    }

    public function testAnAdminStillSeesAdminAndStaffTiers(): void
    {
        $this->client->loginUser($this->people['admin']);

        $crawler = $this->client->request('GET', '/team');
        self::assertCount(1, $crawler->filter('.tier.t-admin'), 'the Admin is named');
        self::assertGreaterThan(0, $crawler->filter('.tier.t-staff')->count(), 'and Staff are');
    }

    public function testASuperAdminSeesEveryTier(): void
    {
        $this->client->loginUser($this->people['super']);

        $crawler = $this->client->request('GET', '/team');
        self::assertCount(1, $crawler->filter('.tier.t-super'));
        self::assertCount(1, $crawler->filter('.tier.t-admin'));
    }
}
