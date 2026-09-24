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

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;

/**
 * WHERE A POSTING IS MADE, AND THE DOOR TO IT.
 *
 * THE DEFECT THESE PIN. Both screens that mention posting said where it
 * happens and then left the reader to find the page — the owner could not,
 * which is the whole report. A screen that names a page owes a door to it.
 *
 * AND THE CARD OPENS IN THE BROWSER. Opening a station used to be a
 * NAVIGATION — `?open=<uuid>`, a round trip and a repaint to reveal markup
 * the page could have carried — so the register is a native disclosure now.
 * The query still decides what arrives open, because every deep link into a
 * station depends on it.
 */
#[CoversNothing]
final class PostingDoorTest extends WebTestCase
{
    /**
     * A POST WITH NOBODY AT IT IS A NORMAL STATE, and the empty state says so
     * — and now says where to change it, at the same address the header's own
     * control opens, with this station already open.
     */
    public function testAnEmptyPostOffersTheWayToPostSomebody(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->anEmptyPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Nobody is stationed here', $body);
        self::assertStringContainsString(
            '/areas/'.$area->getUuidString().'/configure/stations?open='.$station->getUuidString(),
            $body,
            'The empty state opens the configure page on this very station.',
        );
        self::assertStringContainsString('Station somebody', $body);
    }

    /**
     * AND IT IS ABSENT, NOT DISABLED, for somebody who may READ the area and
     * not write to it. Posting costs `assignments.manage`, not the
     * `stations.read` that opens the configure page, so a reader who may look
     * gets the page and not the door — a greyed control is a list of what
     * somebody is not trusted with, and an offer that refuses is worse than no
     * offer.
     */
    public function testTheDoorIsAbsentForSomebodyWhoMayReadButNotWrite(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();
        [$area, $station] = $this->anEmptyPost();

        $body = $this->body($this->record($area, $station));

        self::assertStringContainsString('Nobody is stationed here', $body, 'The page still reads.');
        self::assertStringNotContainsString('Station somebody', $body);
    }

    /** And the fragment states the fact rather than lecturing about it. */
    public function testTheEmptyStateStatesTheFactWithoutTheEssay(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->anEmptyPost();

        self::assertStringNotContainsString(
            'somebody is stationed from the area',
            $this->body($this->record($area, $station)),
        );
    }

    /**
     * A CLOSED CARD IS A DISCLOSURE, AND THE WHOLE HEAD IS THE CONTROL. It
     * used to carry a chevron that navigated, which read as decoration.
     */
    public function testAClosedCardIsASummaryThatSaysWhatIsInside(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aPostToConfigure();

        $body = $this->body($this->section($area));

        self::assertMatchesRegularExpression('#<details class="zcard[^"]*"\s*>#', $body, 'A card nobody named is closed.');
        self::assertStringContainsString('<summary class="zc-hd">', $body);
        self::assertStringContainsString('station somebody inside', $body, 'The closed summary says what is in there.');
    }

    /**
     * THE SERVER STILL DECIDES WHAT ARRIVES OPEN, which is what keeps every
     * deep link into a station working: the record's "Edit the station", the
     * empty state's new door, and the redirect after a form posts.
     */
    public function testTheAddressStillDecidesWhichCardArrivesOpen(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aPostToConfigure();

        $body = $this->body($this->section($area).'?open='.$station->getUuidString());

        self::assertStringContainsString('open>', $body, 'The named card is rendered open.');
        self::assertStringNotContainsString('station somebody inside', $body, 'An open card is not inviting you inside it.');
    }

    /**
     * AND THE BODY IS ALWAYS THERE, which is the point: the browser opens the
     * card without asking the server for the markup it would need.
     */
    public function testTheFormsInsideAreOnThePageWhetherOrNotItIsOpen(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $station] = $this->aPostToConfigure();

        $body = $this->body($this->section($area));

        self::assertStringContainsString('Station someone', $body, 'The assignment control is in the closed card, ready to be revealed.');
        self::assertStringContainsString('/stations/'.$station->getUuidString().'/rename', $body);
    }

    // ---------------------------------------------------------------- fixtures

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function anEmptyPost(): array
    {
        $area = $this->anArea();
        $station = $this->stations()->add($area, 'Ridge Outpost', -29.75, -3.2, 'ST-08');

        return [$area, $station];
    }

    /** @return array{0: AreaOfInterest, 1: Station} */
    private function aPostToConfigure(): array
    {
        return $this->anEmptyPost();
    }

    private function record(AreaOfInterest $area, Station $station): string
    {
        return '/areas/'.$area->getUuidString().'/stations/'.$station->getUuidString();
    }

    private function section(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/stations';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function stations(): StationService
    {
        $stations = static::getContainer()->get('area.stations');
        \assert($stations instanceof StationService);

        return $stations;
    }
}
