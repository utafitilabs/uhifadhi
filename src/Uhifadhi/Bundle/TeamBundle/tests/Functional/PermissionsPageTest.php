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
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * TEAM › PERMISSIONS — what every kind of person may do to every other, asked
 * of the rules the app enforces, for real accounts, changing nothing. A
 * Super Admin reads it, by tier and never by a pair. The band of the tiers ×
 * the main powers is the way in; picking a person adds their column beside
 * the tiers and their card; the ledger below has every cell.
 */
final class PermissionsPageTest extends WebTestCaseWithSchema
{
    private User $chief;
    private User $grace;

    protected function setUp(): void
    {
        parent::setUp();
        $kilimani = $this->area('Kilimani');
        $this->chief = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Upendo', 'Massawe', TeamRoleEnum::Admin);
        $this->grace = $this->person('Grace', 'Ndosi');
        $this->grace->setPosition($this->position('Head of station', ['directory.read', 'sign-in-help.manage']));
        $this->place($this->grace, [$kilimani]);
        $ranger = $this->person('Juma', 'Mollel');
        $ranger->setPosition($this->position('Ranger', ['directory.read']));
        $this->place($ranger, [$kilimani]);
        $faraway = $this->person('Lomayani', 'Laizer');
        $faraway->setPosition($this->position('Ranger at Tambarare', ['directory.read']));
        $this->place($faraway, [$this->area('Tambarare')]);
        $this->em->flush();
    }

    public function testOnlyASuperAdminOpensThePage(): void
    {
        $this->client->loginUser($this->chief);
        $this->client->request('GET', '/team/permissions');
        self::assertResponseIsSuccessful();

        $admin = $this->person('Rehema', 'Kimaro', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/team/permissions');
        self::assertResponseStatusCodeSame(403, 'an Admin, by tier');

        $this->client->loginUser($this->grace);
        $this->client->request('GET', '/team/permissions');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheLinkIsDrawnForASuperAdminAfterRanksAndForNobodyElse(): void
    {
        $this->client->loginUser($this->chief);
        $crawler = $this->client->request('GET', '/team');
        $tabs = $crawler->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text());
        self::assertSame('Permissions', end($tabs));
        self::assertCount(1, $crawler->filter('nav.nav a[href="/team/permissions"]'), 'and in the sidebar');

        $admin = $this->person('Rehema', 'Kimaro', TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/team');
        self::assertCount(0, $crawler->filter('a[href="/team/permissions"]'));
    }

    public function testTheRolesPageIsGone(): void
    {
        $this->client->loginUser($this->chief);
        $crawler = $this->client->request('GET', '/team');
        self::assertCount(0, $crawler->filter('a[href="/team/roles"]'));

        $this->client->request('GET', '/team/roles');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheBandIsTheTiersByTheMainPowers(): void
    {
        $band = $this->page()->filter('.perm-band');

        self::assertSame(['Power', 'Staff', 'Admin', 'Super Admin'], $band->filter('thead th')->each(static fn (Crawler $c): string => trim($c->text())));
        $makeSuper = $band->filter('tr[data-power="make-super-admin"] td');
        self::assertStringContainsString('never', $makeSuper->eq(1)->text(), 'Staff');
        self::assertStringContainsString('never', $makeSuper->eq(2)->text(), 'an Admin');
        self::assertStringContainsString('yes', $makeSuper->eq(3)->text(), 'a Super Admin');
    }

    public function testPickingAPersonPutsTheirColumnBesideTheTiersAndTheirCard(): void
    {
        $crawler = $this->page('?person='.$this->grace->getUuidString());

        self::assertSame('Grace Ndosi', trim($crawler->filter('.perm-band thead th.me')->text()));
        self::assertStringContainsString('own area', $crawler->filter('.perm-band tr[data-power="send-a-reset-link"] td.me')->text());
        self::assertStringContainsString('never', $crawler->filter('.perm-band tr[data-power="make-admin"] td.me')->text());
        $card = $crawler->filter('.perm-person');
        self::assertStringContainsString('Grace Ndosi', $card->text());
        self::assertStringContainsString('actions allowed', $card->text());
    }

    public function testTheLedgerHasEveryCellAndFiltersThem(): void
    {
        $all = $this->page();
        self::assertGreaterThan(0, $all->filter('tr.au-row')->count());

        $refused = $this->page('?answer=refused');
        self::assertSame([], array_values(array_filter(
            $refused->filter('tr.au-row')->each(static fn (Crawler $c): ?string => $c->attr('data-answer')),
            static fn (?string $answer): bool => 'refused' !== $answer,
        )));

        $grace = $this->page('?person='.$this->grace->getUuidString());
        self::assertSame(['Grace Ndosi'], array_values(array_unique($grace->filter('tr.au-row td.actor b')->each(static fn (Crawler $c): string => $c->text()))), 'a picked person narrows the ledger to them');
    }

    private function page(string $query = ''): Crawler
    {
        $this->client->loginUser($this->chief);
        $crawler = $this->client->request('GET', '/team/permissions'.$query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
