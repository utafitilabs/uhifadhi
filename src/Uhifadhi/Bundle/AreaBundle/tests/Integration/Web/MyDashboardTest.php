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

/**
 * A DASHBOARD FOR EVERYONE (open item #19, option A ruled 28 Sep 2026).
 *
 * `/` is the organization's dashboard for somebody who may read the areas.
 * Everybody else — a ranger, a clerk — used to meet a 403 there; now they meet
 * their own: the cards each package contributes about them, laid out as the
 * approved design places them.
 */
final class MyDashboardTest extends WebTestCase
{
    /** @var list<string> */
    /** A ranger: reads their area (the handset needs it) and holds no organization dashboard. */
    private const array NO_AREAS = ['areas.read', 'duty.read', 'duty.record'];

    public function testSomebodyWhoCannotReadTheAreasGetsTheirOwnDashboard(): void
    {
        $this->boot(self::NO_AREAS);
        $person = $this->signInAsPerson()->named('Naserian', 'Lekishon');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('Naserian', $crawler->filter('h1')->text());
        self::assertStringContainsString((string) $person->getUuidString(), $crawler->filter('[data-fake="figure"]')->text(), 'asked about the person signed in');
    }

    public function testEveryCardStandsInItsSlotInItsOrder(): void
    {
        $this->boot(self::NO_AREAS);
        $this->signInAsPerson()->named('Naserian', 'Lekishon');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/');

        self::assertCount(1, $crawler->filter('.md-figures [data-fake="figure"]'));
        self::assertCount(1, $crawler->filter('.md-pages [data-fake="door"]'));
        self::assertCount(1, $crawler->filter('[data-slot="row"] [data-fake="row"]'));
        self::assertSame(['left-first', 'left-second'], $crawler->filter('[data-slot="left"] [data-fake]')->each(static fn ($c): string => (string) $c->attr('data-fake')));
    }

    public function testSomebodyWhoReadsTheAreasStillGetsTheOrganizationsDashboard(): void
    {
        $this->boot();
        $this->signInAsPerson()->named('Asha', 'Mollel');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-fake]'), 'no personal cards on the organization dashboard');
    }

    /** Ruled 29 Sep 2026: reading an area is not the organization dashboard's grant. */
    public function testReadingTheAreasIsNotEnoughForTheOrganizationsDashboard(): void
    {
        $this->boot(self::NO_AREAS);
        $this->signInAsPerson()->named('Naserian', 'Lekishon');
        $this->em->flush();

        $crawler = $this->browser()->request('GET', '/');

        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-fake="figure"]'), 'their own, although they read the areas');
    }

    public function testAHolderOfTheOrganizationsDashboardHasADoorToTheirOwn(): void
    {
        $this->boot();
        $this->signInAsPerson()->named('Asha', 'Mollel');
        $this->em->flush();

        $door = $this->browser()->request('GET', '/')->filter('a[href="/me"]');
        self::assertCount(1, $door);

        $crawler = $this->browser()->request('GET', '/me');
        self::assertSame(200, $this->browser()->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-fake="figure"]'), 'their own cards at /me');
    }
}
