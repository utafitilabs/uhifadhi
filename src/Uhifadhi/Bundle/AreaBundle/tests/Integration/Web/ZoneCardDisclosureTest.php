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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;

/**
 * A ZONE CARD OPENS IN THE BROWSER — the twin of the stations register's.
 *
 * THE DEFECT THIS PINS. Opening a zone was a NAVIGATION: `?open=<uuid>`, a
 * round trip and a repaint to reveal markup the page could have carried, and
 * the reload is what a reader notices. It is a native `<details>` now, so a
 * click costs nothing, the keyboard and the screen reader are right for
 * free, and no script ships.
 *
 * THE SERVER STILL DECIDES WHAT ARRIVES OPEN, which is what keeps every deep
 * link into a zone working.
 *
 * AND THE RENAME DISCLOSURE MOVED INTO THE BODY. It sat in the card header,
 * which as a `<summary>` would have made it a `<details>` inside a
 * `<summary>` — invalid markup whose one certain behaviour is that clicking
 * Rename toggles the whole card. An edit belongs in the open card anyway,
 * which is where the stations twin keeps its own.
 */
#[CoversNothing]
final class ZoneCardDisclosureTest extends WebTestCase
{
    /** A card nobody named is a closed disclosure, and the head is the control. */
    public function testAClosedCardIsASummary(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithTwoZones();

        $body = $this->body($this->section($area));

        self::assertMatchesRegularExpression('#<details class="zcard[^"]*"\s*>#', $body, 'A card nobody named is closed.');
        self::assertStringContainsString('<summary class="zc-hd">', $body);
    }

    /** `?open=` renders the card it names open, so a deep link still lands. */
    public function testTheAddressStillDecidesWhichCardArrivesOpen(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->anAreaWithTwoZones();

        $body = $this->body($this->section($area).'?open='.$zone->getUuidString());

        self::assertStringContainsString('open>', $body, 'The named card is rendered open.');
        self::assertStringContainsString('zcard on focusline', $body, 'And marked as the one the address is about.');
    }

    /**
     * THE BODY IS ALWAYS ON THE PAGE, which is the point: the browser reveals
     * it without asking the server for markup it could have had already.
     */
    public function testTheBodyIsOnThePageWhetherOrNotItIsOpen(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->anAreaWithTwoZones();

        $body = $this->body($this->section($area));

        self::assertStringContainsString('/zones/'.$zone->getUuidString().'/rename', $body, 'The rename form is there, closed.');
        self::assertStringContainsString('Remove the zone', $body);
    }

    /**
     * AND RENAME IS NOT INSIDE THE SUMMARY. A `<details>` nested in a
     * `<summary>` is invalid, and the one thing it reliably does is toggle
     * the card instead of opening the field.
     */
    public function testTheRenameDisclosureIsNotNestedInTheCardsSummary(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->anAreaWithTwoZones();

        $body = $this->body($this->section($area));
        $summary = substr($body, (int) strpos($body, '<summary class="zc-hd">'));
        $summary = substr($summary, 0, (int) strpos($summary, '</summary>'));

        self::assertStringNotContainsString('<details', $summary, 'Nothing that opens may live inside the thing that opens.');
    }

    // ---------------------------------------------------------------- fixtures

    /** @return array{0: AreaOfInterest, 1: Zone} the area, and the zone a link names */
    private function anAreaWithTwoZones(): array
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $named = $this->aZone($area, 'Eastern Sector', self::AN_EAST_HALF);

        return [$area, $named];
    }

    private function section(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/zones';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }
}
