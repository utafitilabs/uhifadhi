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
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE PERMISSION HISTORY, BESIDE THE PERMISSIONS PAGE (ruled 11 Oct 2026, #75,
 * design D with the rail B): the rail reads everybody's newest changes; with a
 * person picked beside the tiers, the changes they made and the changes made
 * to them; and a dot on a power in the band — a power that changed in the
 * last 30 days — opens the changes behind it.
 */
final class PermissionHistoryRailTest extends WebTestCaseWithSchema
{
    private User $chief;
    private User $grace;
    private User $juma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chief = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->grace = $this->person('Grace', 'Ndosi');
        $this->juma = $this->person('Juma', 'Mollel');
        $this->em->flush();
    }

    public function testTheRailReadsEverybodysNewestChanges(): void
    {
        $this->accounts()->changeTier($this->grace, TeamRoleEnum::Admin, $this->chief);
        $this->accounts()->resetLinkSent($this->juma, $this->grace);

        $lines = $this->rail()->filter('.perm-rl');

        self::assertCount(2, $lines);
        self::assertStringContainsString('Grace Ndosi sent Juma Mollel a reset link', self::text($lines->eq(0)), 'newest first');
        self::assertStringContainsString('Naomi Kileo made Grace Ndosi Admin', self::text($lines->eq(1)));
        self::assertSame(['Staff', 'Admin'], $lines->eq(1)->filter('.perm-diff s, .perm-diff i')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertCount(1, $lines->eq(1)->filter('a[href="/team/'.$this->grace->getUuidString().'"]'), 'the person it was done to is a link to their record');
    }

    public function testAPickedPersonReadsTheChangesTheyMadeAndTheChangesMadeToThem(): void
    {
        $this->accounts()->changeTier($this->grace, TeamRoleEnum::Admin, $this->chief);
        $this->accounts()->resetLinkSent($this->juma, $this->grace);
        $this->accounts()->updateRecord($this->juma, 'Juma', 'Mollel', 'j.mollel@example.test', null);

        $rail = $this->rail('?person='.$this->grace->getUuidString());

        self::assertStringContainsString('by or to Grace Ndosi', $rail->filter('.tab')->first()->text());
        self::assertSame(
            ['Grace Ndosi sent Juma Mollel a reset link', 'Naomi Kileo made Grace Ndosi Admin'],
            $rail->filter('.perm-rl .perm-ft')->each(static fn (Crawler $c): string => self::text($c)),
        );
    }

    public function testADotMarksAPowerThatChangedInTheLastThirtyDaysAndOpensItsChanges(): void
    {
        $this->accounts()->changeTier($this->grace, TeamRoleEnum::Admin, $this->chief);
        $this->line(PermissionChangeEnum::ResetLinkSent, $this->juma, new \DateTimeImmutable('-40 days'));
        $this->em->flush();

        $page = $this->page();
        $dot = $page->filter('.perm-band tr[data-power="make-admin"] a.perm-dot');
        self::assertCount(1, $dot, 'make Admin changed today');
        self::assertStringContainsString('changes=make-admin', (string) $dot->attr('href'));
        self::assertCount(0, $page->filter('.perm-band tr[data-power="send-a-reset-link"] a.perm-dot'), 'a reset link forty days ago is no recent change');

        $rail = $this->rail('?changes=make-admin');
        self::assertStringContainsString('make Admin', $rail->filter('.tab')->first()->text());
        self::assertCount(1, $rail->filter('.perm-rl'));
        self::assertStringContainsString('made Grace Ndosi Admin', self::text($rail->filter('.perm-rl')));
    }

    public function testTheRailShowsTwelveAtATimeAndNeverScrollsInsideItself(): void
    {
        for ($i = 0; $i < 14; ++$i) {
            $this->line(PermissionChangeEnum::Deactivated, $this->juma, new \DateTimeImmutable(\sprintf('-%d hours', $i)));
        }
        $this->em->flush();

        $first = $this->rail();
        self::assertCount(12, $first->filter('.perm-rl'));
        self::assertStringContainsString('1–12 of 14', $first->filter('.sxfoot')->text());
        $next = $first->filter('.sxfoot a[href*="changes_page=2"]');
        self::assertCount(1, $next);

        self::assertCount(2, $this->rail('?changes_page=2')->filter('.perm-rl'));
    }

    public function testAnInstallationWithNoChangeYetSaysSo(): void
    {
        self::assertStringContainsString('No change recorded yet', $this->rail()->text());
    }

    private function line(PermissionChangeEnum $action, User $on, \DateTimeImmutable $at): void
    {
        $this->em->persist(new PermissionChange($at, $this->chief->getId(), 'Naomi Kileo', $action, PermissionChange::PERSON, $on->getId(), $on->getUuidString(), $on->getFullName()));
    }

    private function rail(string $query = ''): Crawler
    {
        $rail = $this->page($query)->filter('aside.perm-rail');
        self::assertCount(1, $rail, 'the history sits in the rail beside the band and the ledger');

        return $rail;
    }

    private function page(string $query = ''): Crawler
    {
        $this->client->loginUser($this->chief);
        $crawler = $this->client->request('GET', '/team/permissions'.$query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function text(Crawler $node): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($node->text()));
    }

    private function accounts(): UserService
    {
        $accounts = $this->client->getContainer()->get('test_public.'.UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);

        return $accounts;
    }
}
