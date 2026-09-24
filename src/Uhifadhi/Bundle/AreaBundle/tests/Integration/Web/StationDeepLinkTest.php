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

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Model\StationRegister;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;

/**
 * A DEEP LINK INTO THE STATIONS REGISTER LANDS ON THE STATION IT NAMES.
 *
 * THE DEFECT THIS PINS. The register paginates eight cards to a page, and
 * `?open=<uuid>` only ever opened a card the FIRST page happened to hold. So
 * from the ninth station on, every link that names one — the record's "Edit
 * the station", the empty state's "Post somebody", the redirect after a form
 * posts — answered with page one and nothing open. A link that silently does
 * nothing is worse than a broken one: nobody reports it, they just conclude
 * there is no way to post anybody.
 *
 * THE PAGE IS DECIDED WHERE THE PAGING IS. The register service has just
 * filtered and ordered the set when it answers, so it is the only place that
 * can say which page a row is on without a second copy of the sort, the
 * filters and the page size.
 */
#[CoversClass(StationConfigureController::class)]
final class StationDeepLinkTest extends WebTestCase
{
    /** One more than a page holds, so the last one is on page two. */
    private const int STATIONS = 9;

    /**
     * THE NINTH STATION, NAMED IN THE ADDRESS, OPENS — on page two, which the
     * page was never asked for and worked out for itself.
     */
    public function testALinkToTheNinthStationLandsOnPageTwoWithItOpen(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $ninth] = $this->nineStations();

        $url = $this->section($area).'?open='.$ninth->getUuidString();

        self::assertSame(['Station 09'], $this->listed($url), 'Page two holds the one row after the first eight.');
        self::assertMatchesRegularExpression(
            '#<details class="zcard on focusline"\s+open>#',
            $this->body($url),
            'And it is open, and marked as the one the address is about.',
        );
    }

    /** Asking for nothing in particular still answers with the first page. */
    public function testWithNoStationNamedTheRegisterOpensOnItsFirstPage(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->nineStations();

        $listed = $this->listed($this->section($area));

        self::assertCount(8, $listed, 'A page of the register is eight rows.');
        self::assertSame('Station 01', $listed[0]);
        self::assertNotContains('Station 09', $listed);
    }

    /**
     * AND A PAGE SOMEBODY ASKED FOR IS NOT OVERRULED BY A LINK THAT NAMES
     * NOTHING — the two halves of the address do not fight.
     */
    public function testAnAskedForPageStandsWhenNoStationIsNamed(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->nineStations();

        self::assertSame(['Station 09'], $this->listed($this->section($area).'?page=2'));
    }

    /**
     * THE PAGE IS WORKED OUT AGAINST THE ORDER IN FORCE, not against the
     * default one: reversed by most-posted or by zone, the ninth row by name
     * is somewhere else entirely, and the link must still land on it.
     */
    public function testThePageIsWorkedOutAgainstTheOrderInForce(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $ninth] = $this->nineStations();

        $url = $this->section($area).'?sort=zone&open='.$ninth->getUuidString();

        self::assertContains('Station 09', $this->listed($url), 'Whatever the order puts it on, that page answers.');
        self::assertStringContainsString('open>', $this->body($url));
    }

    /**
     * A LINK THAT NAMES A CLOSED STATION SHOWS IT (ruled 2026-09-21). The
     * register rests on the active ones, so the card used to be simply
     * absent — a dead door on the record of every closed post.
     */
    public function testALinkToAClosedStationWidensTheRestingFilterAndOpensIt(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $closed] = $this->aClosedStation();

        $url = $this->section($area).'?open='.$closed->getUuidString();

        self::assertContains('Station 03', $this->listed($url), 'The closed card the link named is drawn.');
        self::assertStringContainsString('open>', $this->body($url));
    }

    /** And the filter row says so, rather than showing a set it disagrees with. */
    public function testTheWidenedFilterIsHonestAboutWhatItIsShowing(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $closed] = $this->aClosedStation();

        $body = $this->body($this->section($area).'?open='.$closed->getUuidString());

        self::assertMatchesRegularExpression(
            '#<span class="i-ddval">all</span>#',
            $body,
            'The activity chip reads "all", so the controls and the rows agree.',
        );
    }

    /**
     * BUT A FILTER THE READER CHOSE IS NEVER OVERRULED. Somebody who asked
     * for the active ones and then followed a link to a closed post gets the
     * answer they asked for; the address is theirs.
     */
    public function testAnExplicitActivityFilterIsNotWidened(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $closed] = $this->aClosedStation();

        $listed = $this->listed($this->section($area).'?active=yes&open='.$closed->getUuidString());

        self::assertNotContains('Station 03', $listed, 'They asked for the active ones.');
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Three posts, the third closed — the row the resting filter hides.
     *
     * @return array{0: AreaOfInterest, 1: Station}
     */
    private function aClosedStation(): array
    {
        $area = $this->anArea();
        $stations = static::getContainer()->get('test_public.area.stations');
        \assert($stations instanceof StationService);

        $closed = null;
        for ($n = 1; $n <= 3; ++$n) {
            $station = $stations->add($area, \sprintf('Station %02d', $n), -29.75 + ($n / 100), -3.2, \sprintf('ST-%02d', $n));
            if (3 === $n) {
                $closed = $station;
                $station->setActive(false);
            }
        }
        $this->em->flush();

        \assert($closed instanceof Station);

        return [$area, $closed];
    }

    /** @return array{0: AreaOfInterest, 1: Station} the area, and the ninth station by name */
    private function nineStations(): array
    {
        $area = $this->anArea();
        $stations = static::getContainer()->get('test_public.area.stations');
        \assert($stations instanceof StationService);

        self::assertSame(8, StationRegister::PER_PAGE, 'This suite is about the row after the last of a page.');

        $ninth = null;
        for ($n = 1; $n <= self::STATIONS; ++$n) {
            $station = $stations->add($area, \sprintf('Station %02d', $n), -29.75 + ($n / 100), -3.2, \sprintf('ST-%02d', $n));
            if (self::STATIONS === $n) {
                $ninth = $station;
            }
        }
        $this->em->flush();

        self::assertInstanceOf(Station::class, $ninth);

        return [$area, $ninth];
    }

    private function section(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/stations';
    }

    /**
     * THE REGISTER'S ROWS ALONE. The point picker's plate names EVERY post in
     * the area — deliberately, so a new point cannot be put on top of one —
     * so "this row is not on this page" is a question for the register and
     * not for the page.
     *
     * @return list<string>
     */
    private function listed(string $url): array
    {
        preg_match_all('#<a class="zc-nm"[^>]*>([^<]+)</a>#', $this->body($url), $found);

        return array_map(trim(...), $found[1]);
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }
}
