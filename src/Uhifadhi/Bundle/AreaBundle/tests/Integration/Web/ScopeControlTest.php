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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Shell\AreasTheViewerMayOpen;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * THE SCOPE CONTROL HAS A DEFAULT, AND THE CORE SHIPS IT.
 *
 * THE DEFECT, RENDERED: the roster's organization pages came up with no scope
 * control at all. Everything else was right — the row, the strip, the figures
 * — because the shell draws the control only when something tags a
 * {@see \Uhifadhi\Contracts\Shell\ScopeSourceInterface}, and no installation
 * had. A seam whose default is "nothing" ships a feature that works in the
 * suite and is missing on every real page.
 *
 * SO THE AREA BUNDLE ANSWERS IT: it owns the areas and it already asks the
 * platform's authority question per area for the handset. An installation
 * gets the control with no wiring, and a host that wants a different list
 * still replaces the source.
 *
 * THE SAME AUTHORITY AS `/api/areas/mine`, asked the same way — `areas.read`
 * WITH THE AREA AS SUBJECT. Asking without it would answer "does this person
 * have authority anywhere", which would offer somebody a slice they cannot
 * open.
 */
final class ScopeControlTest extends WebTestCase
{
    /** @return list<Scope> */
    private function scopes(): array
    {
        $this->signIn();

        $source = static::getContainer()->get('test_public.area.scopes');
        self::assertInstanceOf(AreasTheViewerMayOpen::class, $source);

        return array_values([...$source->scopes()]);
    }

    /**
     * FOUR AREAS: the organization, then the four, by name. Organization
     * first because it is the widest reading and the one an org page opens
     * on.
     */
    public function testFourViewableAreasAreOfferedUnderTheOrganization(): void
    {
        $this->boot();
        foreach (['Crater', 'Northern Reserve', 'Salt Marsh', 'Western Range'] as $name) {
            $this->anArea($name);
        }

        $scopes = $this->scopes();

        self::assertSame(
            ['Organization — all areas', 'Crater', 'Northern Reserve', 'Salt Marsh', 'Western Range'],
            array_map(static fn (Scope $scope): string => $scope->label, $scopes),
        );
        self::assertTrue($scopes[0]->isOrganization());
        self::assertFalse($scopes[1]->isOrganization());
    }

    /**
     * ONE AREA IS NOT A CHOICE. "The organization" and "that area" are the
     * same reading, so the source offers the area alone and the shell's
     * existing rule — a control of one row is not a control — leaves the
     * action row with nothing in it.
     */
    public function testOneViewableAreaOffersNoChoiceAtAll(): void
    {
        $this->boot();
        $this->anArea('Crater');

        self::assertSame(
            ['Crater'],
            array_map(static fn (Scope $scope): string => $scope->label, $this->scopes()),
        );
    }

    /** An installation with no areas has nothing to scope, and says so by saying nothing. */
    public function testNoAreasOffersNothing(): void
    {
        $this->boot();

        self::assertSame([], $this->scopes());
    }

    /**
     * AN AREA THE VIEWER MAY NOT OPEN IS NOT IN THE CONTROL. Not disabled,
     * not greyed: absent — a disabled option is a list of the things
     * somebody is not allowed to see.
     */
    public function testAnAreaTheViewerMayNotOpenIsAbsent(): void
    {
        $this->boot(grants: []);
        $this->anArea('Crater');
        $this->anArea('Salt Marsh');

        self::assertSame([], $this->scopes());
    }

    /**
     * NO TOKEN, NO QUESTION. A page can render outside any firewall, and the
     * authorization checker throws there rather than answering false.
     */
    public function testNobodyLookingIsOfferedNothing(): void
    {
        $this->boot();
        $this->anArea('Crater');

        $source = static::getContainer()->get('test_public.area.scopes');
        self::assertInstanceOf(AreasTheViewerMayOpen::class, $source);

        self::assertSame([], array_values([...$source->scopes()]));
    }

    /**
     * AND THE WHOLE CHAIN, RENDERED: a contributed organization page, the
     * shell's own frame around it, and the control in its action row with
     * every slice this viewer may open — with nothing wired by the host.
     */
    public function testTheControlIsDrawnOnAContributedOrganizationPage(): void
    {
        $this->boot();
        foreach (['Crater', 'Northern Reserve', 'Salt Marsh', 'Western Range'] as $name) {
            $this->anArea($name);
        }
        $this->signIn();

        $page = $this->browser()->request('GET', '/sightings');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(
            ['Organization — all areas', 'Crater', 'Northern Reserve', 'Salt Marsh', 'Western Range'],
            $page->filter('.pgact form.ov-ctl option')->each(static fn (Crawler $o): string => trim($o->text())),
        );
    }

    /** And with one area there is no control on that page at all. */
    public function testWithOneAreaThePageCarriesNoControl(): void
    {
        $this->boot();
        $this->anArea('Crater');
        $this->signIn();

        $page = $this->browser()->request('GET', '/sightings');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertCount(0, $page->filter('form.ov-ctl'));
    }
}
