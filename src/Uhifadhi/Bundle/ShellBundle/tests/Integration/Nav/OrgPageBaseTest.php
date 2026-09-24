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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Nav;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\FixtureOrgModule;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\FixtureScopeSource;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * THE ORG BASE — the page frame an organization-level screen extends.
 *
 * RULED: the page-level tab strip of an org page set is the SHELL'S, exactly
 * as the sidebar row is. The first module to ship one wrote its own base —
 * its own `.atabs` loop, its own scope-control include, its own trail — and
 * the second would have written another one; two copies of a strip drift, and
 * the tab and the sidebar row then disagree about which screens a module has.
 *
 * SO THE STRIP IS BUILT FROM THE SAME DECLARATION THE SIDEBAR IS MOUNTED
 * FROM — `orgPages()`, filtered to the routes this application actually
 * mounted — and a module's org page fills a body and nothing else.
 *
 * NO AREA ANYWHERE IN IT, which is the whole difference from the area frame:
 * an organization-level screen is the area screen one scope WIDER, so naming
 * an area in its trail would be naming the one thing it is not about.
 */
final class OrgPageBaseTest extends ContractTestCase
{
    private const string PAGE = '@fixtures/org_page.html.twig';

    /**
     * The two screens the fixture application mounts, plus one it does not.
     *
     * @return list<OrgPage>
     */
    private static function threeDeclaredPages(): array
    {
        return [
            new OrgPage('overview', 'Overview', 'fixture_org_overview'),
            new OrgPage('today', 'Today', 'fixture_org_today'),
            new OrgPage('live', 'Live', 'fixture_org_live_not_mounted'),
        ];
    }

    /** @return list<string> */
    private function strip(Crawler $page): array
    {
        return $page->filter('.page > .atabs a')->each(static fn (Crawler $a): string => trim($a->text()));
    }

    /**
     * THE STRIP IS THE MODULE'S DECLARED PAGES, MOUNTED ONES ONLY, AND THE
     * MODULE DREW NONE OF IT. A route this installation has not mounted is
     * left out rather than drawn as a link to a 404 — the same rule the
     * sidebar keeps, because it is the same list.
     */
    public function testTheStripIsTheModulesMountedPagesAndTheModuleDrawsNoneOfIt(): void
    {
        $this->on('fixture_org_today');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $page = $this->crawl(self::PAGE);

        self::assertSame(['Overview', 'Today'], $this->strip($page));
        self::assertCount(1, $page->filter('.atabs'), 'one strip, drawn by the frame');
    }

    /** AND THE SCREEN THE VIEWER IS ON IS THE LIT ONE, exactly one of them. */
    public function testTheCurrentScreenIsTheLitTab(): void
    {
        $this->on('fixture_org_today');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $page = $this->crawl(self::PAGE);

        self::assertSame(
            ['Today'],
            $page->filter('.atabs a.on')->each(static fn (Crawler $a): string => trim($a->text())),
        );
    }

    /**
     * IT STANDS WHERE EVERY OTHER STRIP STANDS — between the page head and
     * the body. The module that wrote its own put it inside the body, which
     * is half a rung lower than every other strip in the product.
     */
    public function testTheStripStandsBetweenTheHeadAndTheBody(): void
    {
        $this->on('fixture_org_overview');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $html = $this->render(self::PAGE);

        $head = strpos($html, 'class="pghead"');
        $strip = strpos($html, 'class="atabs"');
        $body = strpos($html, 'class="pgbody"');

        self::assertIsInt($head);
        self::assertIsInt($strip);
        self::assertIsInt($body);
        self::assertGreaterThan($head, $strip, 'the strip comes after the page head');
        self::assertLessThan($body, $strip, 'and before the body');
    }

    /**
     * THE ONE CONTROL AN ORGANIZATION-LEVEL PAGE CARRIES, in the action row,
     * and it is the shell's own component. A module states no scope control.
     */
    public function testTheScopeControlIsInTheActionRow(): void
    {
        $this->on('fixture_org_overview');
        FixtureOrgModule::$pages = self::threeDeclaredPages();
        FixtureScopeSource::$scopes = [
            Scope::organization(),
            Scope::area('11111111-1111-1111-1111-111111111111', 'Crater'),
        ];

        $page = $this->crawl(self::PAGE);

        self::assertCount(1, $page->filter('.pgact form.ov-ctl select'));
        self::assertSame(
            ['Organization — all areas', 'Crater'],
            $page->filter('.pgact form.ov-ctl option')->each(static fn (Crawler $o): string => trim($o->text())),
        );
    }

    /**
     * NO AREA IN THE TRAIL. The stand-in host is standing in an area — it
     * says so on every other page — and an organization-level screen still
     * names none, because it is about all of them.
     */
    public function testTheTrailNamesTheModuleAndNoArea(): void
    {
        $this->on('fixture_org_today');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $crumb = trim($this->crawl(self::PAGE)->filter('.crumb')->text());

        self::assertStringNotContainsString('Test Area', $crumb, 'the area the host is in is not this page\'s trail');
        self::assertStringContainsString('Sightings', $crumb, 'the module is');
        self::assertStringContainsString('Today', $crumb, 'and the screen the viewer is on');
    }

    /**
     * THE SLICE SURVIVES A TAB. Somebody reading Crater who moves from
     * Overview to Today is still reading Crater: the strip carries the
     * address's own `?area=`, because the alternative is a control that
     * silently resets every time you change screen.
     */
    public function testTheStripCarriesTheSliceTheAddressNames(): void
    {
        $this->on('fixture_org_overview', ['area' => '11111111-1111-1111-1111-111111111111']);
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $hrefs = $this->crawl(self::PAGE)->filter('.atabs a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));

        self::assertSame(
            ['/sightings?area=11111111-1111-1111-1111-111111111111', '/sightings/today?area=11111111-1111-1111-1111-111111111111'],
            $hrefs,
        );
    }

    /** And an address naming no slice keeps the plain one. */
    public function testWithNoSliceNamedTheTabsAreThePlainAddresses(): void
    {
        $this->on('fixture_org_overview');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $hrefs = $this->crawl(self::PAGE)->filter('.atabs a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));

        self::assertSame(['/sightings', '/sightings/today'], $hrefs);
    }

    /**
     * ONE TAB IS NOT A CHOICE — the same rule the area strip keeps. A module
     * with one mounted screen gets no strip, rather than a lone underlined
     * word pretending to be navigation.
     */
    public function testAModuleWithOneMountedScreenGetsNoStrip(): void
    {
        $this->on('fixture_org_overview');
        FixtureOrgModule::$pages = [new OrgPage('overview', 'Overview', 'fixture_org_overview')];

        self::assertCount(0, $this->crawl(self::PAGE)->filter('.atabs'));
    }

    /**
     * AND THE BASE RENDERS HONESTLY WHERE THE REQUEST IS IN NOBODY'S PAGE
     * SET — no strip, no module name, no error. A page reached at an address
     * its module did not declare is a mistake worth seeing as a plain page,
     * not as a 500.
     */
    public function testARouteInNoPageSetDrawsNoStrip(): void
    {
        $this->on('some_other_route');
        FixtureOrgModule::$pages = self::threeDeclaredPages();

        $page = $this->crawl(self::PAGE);

        self::assertCount(0, $page->filter('.atabs'));
        self::assertStringContainsString('the org body', $page->filter('.pgbody')->text());
    }

    /**
     * The request the page is drawn for — the route the viewer is on, and
     * whatever slice the address names.
     *
     * @param array<string, string> $query
     */
    private function on(string $route, array $query = []): void
    {
        $stack = self::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);

        $request = Request::create('/', 'GET', $query);
        $request->attributes->set('_route', $route);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack->push($request);
    }
}
