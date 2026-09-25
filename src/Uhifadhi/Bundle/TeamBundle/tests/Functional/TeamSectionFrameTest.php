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
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeStationDirectory;

/**
 * TEAM WEARS THE AREA IDIOM — the ruling, asserted.
 *
 * An org-level section is read the way an area is read: the same header on
 * every tab (the section's name), a subline that says what THIS tab is for,
 * one tab strip between the head and the body with exactly one tab lit, and
 * the one Configure action at the right-hand end of the action row on every
 * tab. The tab set is Overview · People · Positions · Postings · Roles;
 * Configure is an action and never a tab, and the configure page shows its own
 * sections where a data tab shows the strip.
 *
 * THE STRIP IS THE SHELL'S, NOT THIS BUNDLE'S. Team contributes the list
 * through the same tabs contract a module uses, which is the whole point of
 * the contract: nothing here is a second implementation of a strip.
 */
final class TeamSectionFrameTest extends WebTestCaseWithSchema
{
    /** The five tabs of the section, and the label lit on each. */
    private const array TABS = ['Overview', 'People', 'Positions', 'Assignments', 'Roles', 'Ranks'];

    /** @return \Generator<string, array{string, string}> */
    public static function tabs(): \Generator
    {
        yield 'overview' => ['/team/overview', 'Overview'];
        yield 'people' => ['/team', 'People'];
        yield 'positions' => ['/team/positions', 'Positions'];
        yield 'assignments' => ['/team/assignments', 'Assignments'];
        yield 'roles' => ['/team/roles', 'Roles'];
        yield 'ranks' => ['/team/ranks', 'Ranks'];
    }

    #[DataProvider('tabs')]
    public function testEveryTabCarriesTheWholeStripWithItsOwnTabLit(string $path, string $lit): void
    {
        $crawler = $this->visit($path);

        self::assertSame(self::TABS, $crawler->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame([$lit], $crawler->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
    }

    /** EVERY TAB IS A LIVE LINK: a strip of dead labels is not a strip. */
    public function testEveryTabInTheStripIsALiveLink(): void
    {
        foreach ($this->visit('/team/overview')->filter('.atabs a')->each(static fn (Crawler $c): ?string => $c->attr('href')) as $href) {
            self::assertNotNull($href);
            $this->client->request('GET', (string) $href);
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * THE SAME HEADER ON EVERY TAB — the section's name — and a subline that is
     * this tab's own.
     */
    #[DataProvider('tabs')]
    public function testEveryTabIsHeadedBySectionNameAndCarriesItsOwnSubline(string $path, string $lit): void
    {
        $crawler = $this->visit($path);

        self::assertSame('Team', $crawler->filter('.pghead h1.pg')->text());
        self::assertNotSame('', trim($crawler->filter('.pghead .pgsub')->text()));
    }

    /** And the sublines differ: a shared line on every tab would say nothing. */
    public function testTheSublinesAreOnePerTab(): void
    {
        $sublines = [];
        foreach (self::tabs() as $tab) {
            $sublines[] = $this->visit($tab[0])->filter('.pghead .pgsub')->text();
        }

        self::assertSame($sublines, array_unique($sublines));
    }

    /**
     * THE ONE CONFIGURE ACTION, ON EVERY TAB, LAST IN THE ROW, opening the
     * surface's first section — the house's rank, not this section's choice.
     */
    #[DataProvider('tabs')]
    public function testEveryTabCarriesTheConfigureAction(string $path, string $lit): void
    {
        $actions = $this->visit($path)->filter('.pgact > *');

        self::assertGreaterThan(0, $actions->count());
        self::assertSame('Configure', trim($actions->last()->text()));
        self::assertSame('/team/configure/people', $actions->last()->attr('href'));
    }

    /** The configure page shows its SECTIONS where a data tab shows the strip. */
    public function testTheConfigurePageShowsItsSectionsInTheStrip(): void
    {
        $crawler = $this->visit('/team/configure/people');

        self::assertSame(
            ['People', 'Positions', 'Assignments', 'Ranks'],
            $crawler->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame(['People'], $crawler->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
    }

    /**
     * ON THE CONFIGURE SURFACE THE ACTION IS THE WAY BACK, not a link to where
     * you already are — the shell's rule, and it holds for an org section too.
     */
    public function testOnConfigureTheActionIsTheWayBack(): void
    {
        $action = $this->visit('/team/configure/people')->filter('.pgact > *')->last();

        self::assertSame('/team/overview', $action->attr('href'));
    }

    private bool $signedIn = false;

    private function visit(string $path): Crawler
    {
        if (!$this->signedIn) {
            FakeStationDirectory::clear();
            $this->administrator();
            $this->signedIn = true;
        }

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
