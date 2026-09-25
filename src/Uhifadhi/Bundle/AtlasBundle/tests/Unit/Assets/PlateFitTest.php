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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A PLATE FILLS ITS FRAME WITH WHAT IT IS ABOUT.
 *
 * TWO THINGS HAD TO BE TRUE AND NEITHER WAS. The plate must know what it is
 * about — that is the subject, stated in PHP and carried in the payload — and
 * it must be able to zoom finely enough to reach the frame: measured on the
 * zones tab, an area fitted at whole zoom levels drew its boundary at half
 * the plate's height, because one level further in overflowed the card and
 * Leaflet's default step is a factor of two.
 *
 * SO THE PLATE ZOOMS IN QUARTER LEVELS, and the +/- controls step by the
 * same amount — a control that moved by a whole level on a map that fits by
 * a quarter is two different ideas of a zoom.
 *
 * A TEXT CHECK OVER THE SHIPPED ASSET, and that is its limit: it catches the
 * options being dropped, the two falling out of step, or the settle path
 * being unpicked. WHAT IT CANNOT SAY is whether a given page's plate ends up
 * inset by the padding — that is a measurement of a rendered card, and the
 * two defects it would have caught were both of that kind: a boundary
 * overflowing its plate on the zones tab, and a zone clipped at the bottom
 * of its record. Both came from a fit taken against a frame the card had not
 * finished laying out, so what is asserted here is that the plate takes the
 * fit AGAIN when the frame is final, and never with an animation in flight.
 */
#[CoversNothing]
final class PlateFitTest extends TestCase
{
    public function testThePlateZoomsInFractionsSoAFitCanReachTheFrame(): void
    {
        $controller = self::controller();

        self::assertMatchesRegularExpression(
            '/const ZOOM_SNAP = 0\.25;/',
            $controller,
            'Whole zoom levels cannot fill a frame: one is too far in and the next is half the size.',
        );
        self::assertStringContainsString('zoomSnap: ZOOM_SNAP,', $controller);
        self::assertStringContainsString('zoomDelta: ZOOM_SNAP,', $controller);
    }

    /** The padding between the subject and the plate's edge is stated once. */
    public function testThePaddingIsOneNamedValue(): void
    {
        $controller = self::controller();

        self::assertMatchesRegularExpression('/const FIT_PADDING = \[26, 26\];/', $controller);
        self::assertStringContainsString('padding: FIT_PADDING', $controller);
        self::assertSame(
            1,
            preg_match_all('/padding: FIT_PADDING/', $controller),
            'One fit, one padding — a second copy is how two plates come to be inset differently.',
        );
    }

    /**
     * AND THE FIT IS TAKEN AGAIN once the card has finished being laid out.
     * The first fit happens while the filter row and the legend are still
     * taking their height, so the frame it measured is not the frame it ends
     * up in — which is the other half of why the boundary overflowed.
     */
    public function testThePlateSettlesIntoTheFrameItEndsUpIn(): void
    {
        $controller = self::controller();

        self::assertStringContainsString('invalidateSize', $controller);
        self::assertStringContainsString('requestAnimationFrame(this.settle)', $controller);
        self::assertStringContainsString('new ResizeObserver(', $controller);
        self::assertStringContainsString('this.frameWatch?.disconnect();', $controller);

        // AND LEAFLET'S OWN ANSWER IS THE LAST WORD: it fires `resize` once
        // it has measured again, and the fit taken there is taken against
        // the size the frame really ended up at.
        self::assertStringContainsString("this.map?.on('resize', this.onMapResize);", $controller);
        self::assertStringContainsString("this.map?.off('resize', this.onMapResize);", $controller);
    }

    /**
     * A FIT MEASURES THE FRAME FIRST, on every path into it.
     *
     * Leaflet frames against the size it last measured, and it measures when
     * the map is created; everything the card does afterwards — a two-column
     * grid narrowing it, a legend taking its height — leaves that stale. The
     * hooks that told the plate to look again were each a guess about WHEN
     * the page settles, and on a zone's record none of them was right: the
     * plate framed 765 pixels as though they were 1141. Asking at the moment
     * of fitting cannot be out of date.
     */
    public function testEveryFitMeasuresTheFrameBeforeItFrames(): void
    {
        $controller = self::controller();

        self::assertMatchesRegularExpression(
            '/refit\(\) \{.*?invalidateSize\(\{ animate: false, pan: false \}\).*?fitBounds\(/s',
            $controller,
            'A fit that trusts an older measurement frames a card that no longer exists.',
        );
        // AND IT CANNOT RECURSE: measuring fires `resize`, which refits.
        self::assertStringContainsString('if (!this.shouldFit || this.fitting) {', $controller);
    }

    /**
     * NO FIT IS ANIMATED. An eased fit is still moving when the next one is
     * asked for, and Leaflet answers the second from where the first was
     * going rather than from the frame as it now is — which is how a zone's
     * record came to be framed for the size its card had before the page
     * finished, and its subject to hang seventeen pixels below the plate.
     */
    public function testNoFitIsAnimated(): void
    {
        $controller = self::controller();

        self::assertSame(
            3,
            preg_match_all('/animate: false,?\n?\s*\}\)/', $controller),
            'Every way of arriving somewhere — fitting an extent, centring on a point, bringing a picked pin into view — arrives at once.',
        );
    }

    private static function controller(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/map_plate_controller.js');
    }
}
