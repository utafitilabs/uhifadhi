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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * SETTINGS › DELETIONS IS A REGISTER — one row a delete, newest first, the
 * day a cell of the row and never a heading over a group: the house bar
 * narrows it by kind and by who deleted, the search finds a title, and the
 * register foot pages it. The installed core, since the page sits in the
 * shell's Settings frame.
 */
final class SettingsDeletionsRegisterTest extends FieldApiTestCase
{
    public function testEveryDeleteIsARowOfOneHouseTableNewestFirst(): void
    {
        $this->superAdmin();
        $this->line('2026-09-29 17:45', 'Asha Mollel', 'station', 'Station Test Post', '1 station, 2 postings');
        $this->line('2026-09-30 14:20', 'Naomi Kileo', 'patrol', 'Patrol P-0142', '1 patrol, 3 observations');
        $this->em->flush();

        $page = $this->visit('/settings/deletions');

        self::assertCount(0, $page->filter('h2.zone, .rln, tr.sxgrp'), 'no day heading, no fact line, no band row');
        $card = $page->filter('.c')->reduce(static fn (Crawler $c): bool => $c->filter('table.tbl')->count() > 0);
        self::assertCount(1, $card);
        self::assertSame(['When', 'What was deleted', 'What went with it'], $card->filter('thead th')->each(static fn (Crawler $c): string => $c->text()));
        $rows = $card->filter('table.tbl > tbody > tr');
        self::assertCount(2, $rows);
        self::assertSame('30 Sep', $rows->eq(0)->filter('td')->eq(0)->filter('time')->first()->text());
        self::assertSame('14:20', $rows->eq(0)->filter('td')->eq(0)->filter('.sub')->text());
        self::assertSame('patrol', $rows->eq(0)->filter('td')->eq(1)->filter('.chip')->text());
        self::assertSame('Patrol P-0142', $rows->eq(0)->filter('td')->eq(1)->filter('b')->text());
        self::assertSame('deleted by Naomi Kileo', self::squash($rows->eq(0)->filter('td')->eq(1)->filter('.sub')->text()));
        self::assertSame('1 patrol, 3 observations', self::squash($rows->eq(0)->filter('td')->eq(2)->text()));
        self::assertCount(0, $rows->filter('a'), 'the record is gone, so nothing links to it');
    }

    public function testTheBarNarrowsByKindAndByWhoDeletedAndTheSearchFindsATitle(): void
    {
        $this->superAdmin();
        $this->line('2026-09-28 09:10', 'Asha Mollel', 'area', 'Area Sandbox Reserve', '1 area');
        $this->line('2026-09-29 17:45', 'Asha Mollel', 'station', 'Station Test Post', '1 station');
        $this->line('2026-09-30 14:20', 'Naomi Kileo', 'station', 'Station Old Gate', '1 station');
        $this->em->flush();

        $page = $this->visit('/settings/deletions?q=gate');
        $kind = $page->filter('.lfilt details.i-dd[data-axis="kind"]');
        self::assertSame('all · 3', self::squash($kind->filter('summary.mchip.i-ddt .i-ddval')->text()), 'the counts are of the whole log');
        self::assertSame(['All 3', 'area 1', 'station 2'], $kind->filter('a.i-ddopt')->each(static fn (Crawler $o): string => $o->filter('.i-ddopt-l')->text().' '.$o->filter('.i-ddopt-n')->text()));
        self::assertSame('/settings/deletions?q=gate&kind=station', $kind->filter('a.i-ddopt')->last()->attr('href'));
        $by = $page->filter('.lfilt details.i-dd[data-axis="by"]');
        self::assertSame(['Anyone 3', 'Asha Mollel 2', 'Naomi Kileo 1'], $by->filter('a.i-ddopt')->each(static fn (Crawler $o): string => $o->filter('.i-ddopt-l')->text().' '.$o->filter('.i-ddopt-n')->text()));
        $search = $page->filter('.lfilt form.lsearch');
        self::assertSame('get', strtolower((string) $search->attr('method')));
        self::assertSame('gate', $search->filter('input.fld[name="q"]')->attr('value'));
        self::assertSame(['Station Old Gate'], $this->titles($page));

        self::assertSame(['Station Old Gate', 'Station Test Post'], $this->titles($this->visit('/settings/deletions?kind=station')));
        self::assertSame(['Station Test Post', 'Area Sandbox Reserve'], $this->titles($this->visit('/settings/deletions?by=Asha+Mollel')));
        $both = $this->visit('/settings/deletions?kind=station&by=Naomi+Kileo');
        self::assertSame(['Station Old Gate'], $this->titles($both));
        self::assertSame('station · 2', self::squash($both->filter('details.i-dd[data-axis="kind"] summary.on .i-ddval')->text()), 'a chosen chip reads its option');
    }

    public function testTheLogEndsInTheHouseFootAndIsPaged(): void
    {
        $this->superAdmin();
        for ($i = 1; $i <= 23; ++$i) {
            $this->line(\sprintf('2026-09-%02d 10:00', $i), 'Naomi Kileo', 'patrol', \sprintf('Patrol P-%04d', $i), '1 patrol');
        }
        $this->em->flush();

        $first = $this->visit('/settings/deletions');
        self::assertCount(20, $first->filter('table.tbl tbody tr'));
        $foot = $first->filter('.c > table.tbl + .rdf-foot');
        self::assertCount(1, $foot);
        self::assertSame('23 deletions', self::squash($foot->filter(':scope > span > span')->first()->text()));
        self::assertSame('20 per page', self::squash(str_replace('▾', '', $foot->filter('details.rdf-size > summary')->text())));
        self::assertSame('page 1 of 2', self::squash(str_replace('›', '', $foot->filter('.rdf-page')->text())));
        self::assertSame('/settings/deletions?page=2', $foot->filter('.rdf-page a[aria-label="Next page"]')->attr('href'));
        self::assertSame(['20', '50', '100', 'All'], $foot->filter('details.rdf-size a.i-ddopt')->each(static fn (Crawler $a): string => $a->text()));

        self::assertSame(['Patrol P-0003', 'Patrol P-0002', 'Patrol P-0001'], $this->titles($this->visit('/settings/deletions?page=2')));
        self::assertCount(23, $this->visit('/settings/deletions?per=all')->filter('table.tbl tbody tr'));
    }

    public function testAnEmptyLogSaysSo(): void
    {
        $this->superAdmin();
        $this->em->flush();

        $page = $this->visit('/settings/deletions');
        self::assertStringContainsString('Nothing has been deleted on this installation.', $page->filter('table.tbl tbody')->text());
        self::assertSame('0 deletions', self::squash($page->filter('.rdf-foot > span > span')->first()->text()));
    }

    private function superAdmin(): void
    {
        $naomi = $this->officeStaff('Naomi', 'Kileo')->setTeamRole(TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);
    }

    private function line(string $at, string $by, string $kind, string $title, string $went): void
    {
        $this->em->persist(new DeletionRecord(new \DateTimeImmutable($at), $by, null, $kind, 'ref-'.md5($title), $title, $went));
    }

    /** @return list<string> */
    private function titles(Crawler $page): array
    {
        return $page->filter('table.tbl tbody tr td:nth-child(2) b')->each(static fn (Crawler $b): string => $b->text());
    }

    private function visit(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
