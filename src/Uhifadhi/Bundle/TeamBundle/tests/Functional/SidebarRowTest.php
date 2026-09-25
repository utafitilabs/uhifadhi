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

use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE ROW IN THE SIDEBAR — asserted as markup a browser received, because that
 * is where the defect was.
 *
 * A module with screens and no row is a module reachable only by somebody who
 * already knows the URL, which is the same as not shipping it. The platform's
 * one sentence is "a module registers with the registry and renders in the shell",
 * and the second half of it is this file.
 *
 * THE INTERESTING QUESTIONS ARE ASKED FROM OFF THE PAGE. Whether the row is
 * there at all is only half of it; the other half is who sees it, and a suite
 * that only ever asked from /team would have to sign in as somebody who can
 * reach /team before it could ask. So most of these requests go to
 * `/_elsewhere` — a page in the shell's frame that is nobody's module (see
 * ShellPageController) — which is the position a real viewer is in.
 *
 * GATING IS THE SOURCE'S JOB, and the shell says so: a row a viewer may not
 * have is ABSENT, never hidden, because a hidden row leaks its existence to
 * whoever reads the HTML. So the assertions below are about the string not
 * being in the response at all.
 */
final class SidebarRowTest extends WebTestCaseWithSchema
{
    /**
     * THREE ROWS AND TWO HEADINGS. Performance files under Observatory
     * because it is a way of LOOKING at the organization; the org chart's
     * own two rows sit under Organization, in that order, and each row is
     * gated on the same permission as the screen behind it.
     */
    public function testEveryRowThisBundleContributesIsThereForSomebodyWhoMayAdministerTheTeam(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Observatory', 'Organization'],
            $crawler->filter('nav.nav .nav-hd')->each(static fn ($node): string => $node->text()),
        );

        $rows = $crawler->filter('nav.nav a.nav-item');
        self::assertCount(3, $rows);
        self::assertSame(
            ['/departments/performance', '/departments', '/team'],
            $rows->each(static fn ($node): string => (string) $node->attr('href')),
        );
        self::assertSame(
            ['Performance', 'Departments', 'Team'],
            $rows->each(static fn ($node): string => $node->filter('span')->text()),
        );
    }

    /** And Performance carries its three screens under it, as the strip does. */
    public function testThePerformanceRowCarriesItsThreeScreens(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/departments/performance');

        self::assertSame(
            ['/departments/performance', '/departments/performance/topics', '/departments/performance/briefing'],
            $crawler->filter('nav.nav .ntree a.ntt')->each(static fn ($node): string => (string) $node->attr('href')),
        );
    }

    /**
     * NOT LIT FROM SOMEWHERE ELSE. "Where am I" is the sidebar's whole job, and
     * a row lit on a page it does not lead to answers it wrongly.
     */
    public function testNeitherRowIsLitOnAPageThatIsNotThisModules(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        // Performance carries screens of its own and is therefore a branch,
        // and a branch nobody is standing in is closed to its row.
        self::assertSame(
            ['nav-item closed', 'nav-item', 'nav-item'],
            $crawler->filter('nav.nav a.nav-item')->each(static fn ($node): string => (string) $node->attr('class')),
        );
    }

    public function testTheRowIsLitOnTheRoster(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['nav-item closed', 'nav-item', 'nav-item path'], $this->rowClasses($crawler));
        self::assertSame('People', trim($crawler->filter('nav.nav .ntree .ntt.on')->text()));
    }

    /**
     * AND MARKED ON THE MATRIX TOO, which is one row for two screens on purpose:
     * the design draws Team as a single flat row, and /team/positions is a
     * screen INSIDE it rather than a second place in the product.
     */
    public function testTheRowIsLitOnThePermissionMatrix(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertResponseIsSuccessful();
        self::assertSame(['nav-item closed', 'nav-item', 'nav-item path'], $this->rowClasses($crawler));
        self::assertSame('Positions', trim($crawler->filter('nav.nav .ntree .ntt.on')->text()));
    }

    /**
     * AND DEPARTMENTS IS A SECOND PLACE, not a screen inside the roster — which
     * is the whole reason it has a top-level address. Standing on it marks its
     * own row and leaves Team alone; a `/team/departments` would have marked
     * both, because "am I here" is decided by path prefix.
     *
     * THE SECTION ROW CARRIES `path`, NOT `on`. One ground per tree (ruled
     * 2026-09-20): the ground and the focus line go to the screen inside the
     * section, and the section above it carries the accent as ink.
     */
    public function testTheDepartmentsRowIsLitOnItsOwnScreenAndTheRosterRowIsNot(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertSame(['nav-item closed', 'nav-item path', 'nav-item'], $this->rowClasses($crawler));
    }

    /**
     * A SECTION'S CHILDREN ARE ITS OWN SCREENS, AND THE RUNG SAYS SO.
     *
     * The sidebar draws a PLACE (`.nta`, a name in bold with its own branch)
     * and a SCREEN (`.ntt`) differently, and the difference is what tells a
     * reader an area apart from the tabs inside it. A section has no place
     * between it and the screens its own tab strip carries, so its children
     * are screens — the same five, in the same order, because the tree and
     * the strip are two readings of one list.
     *
     * The rung below them is where the places are: a department RECORD hangs
     * off the register and is drawn as one (`.ntm`, with the department's own
     * hue on its dot).
     */
    public function testASectionsChildrenAreDrawnAsItsScreensAndNotAsPlacesInsideIt(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('nav.nav .ntree .nta'), 'a section has no place rung');

        // The open tree is the one the viewer is in; Performance's is in the
        // document and folded, which is the sidebar's own rule.
        self::assertSame(
            ['Overview', 'People', 'Positions', 'Assignments', 'Roles', 'Ranks'],
            $crawler->filter('nav.nav .ntree:not(.closed) .ntt')->each(static fn ($node): string => trim($node->text())),
        );
    }

    /**
     * A COLLEAGUE WITHOUT team.manage GETS NO ROW — and gets no mention of one.
     * The gate on the row is the same permission as the gate on the screen, so
     * the sidebar cannot offer a door that closes in somebody's face.
     */
    public function testAColleagueWithoutTeamManageSeesNoRow(): void
    {
        $ranger = $this->person('Juma', 'Mwakalinga', TeamRoleEnum::Staff);
        $ranger->setPosition($this->position('Ranger', ['surveys.read']));
        $this->em->flush();
        $this->client->loginUser($ranger);

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav a.nav-item'));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Organization', $body);
        self::assertStringNotContainsString('Departments', $body);
    }

    /**
     * AND A STRANGER NEVER GETS AS FAR AS A SIDEBAR. Under the documented
     * posture the front door is closed: everything that is not the sign-in
     * screen or one of the recovery paths sends an anonymous visitor to
     * `/login`, so the question "what does a stranger see in the nav" has one
     * answer and it is "the sign-in card". The row cannot leak from a page a
     * stranger cannot reach.
     */
    public function testAnAnonymousVisitorNeverReachesAPageWithASidebar(): void
    {
        $this->client->request('GET', '/_elsewhere');

        self::assertResponseRedirects('http://localhost/login');
    }

    /** @return list<string> */
    private function rowClasses(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('nav.nav a.nav-item')->each(static fn ($node): string => (string) $node->attr('class'));
    }

    /** The sign-in screen still shows no navigation, deliberately. */
    public function testTheSignInScreenHasNoSidebar(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav'));
    }
}
