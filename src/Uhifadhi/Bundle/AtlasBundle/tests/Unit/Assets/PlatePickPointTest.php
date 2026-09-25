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

use PHPUnit\Framework\TestCase;

/**
 * THE PLATE PICKS A POINT, AND THE PAGE EXTENDS NOTHING.
 *
 * A page states `AtlasMap::pickPoint(PointPick)` — the form a click writes
 * into at rest and the two inputs that hold the point — and the plate owns
 * the rest: the pin, drawn as the design's pin in the accent and draggable;
 * the click on the ground; the point a form already holds; bringing the pin
 * into view; and the caption's three states. A control anywhere on the page
 * arms the plate for another form by wearing `data-atlas-pick`, with a mode
 * and a name, and no JavaScript of its own.
 *
 * A text check over the shipped asset, like the swap verb's beside it: it
 * catches the seam being unpicked — the pin going back to a circle that
 * cannot be dragged, the arming heard only inside the plate, the write
 * rounding to a precision nobody stated.
 */
final class PlatePickPointTest extends TestCase
{
    public function testTheAttributesAControlArmsThePlateWithArePublished(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("const PICK = 'data-atlas-pick';", $js);
        self::assertStringContainsString("const PICK_MODE = 'data-atlas-pick-mode';", $js);
        self::assertStringContainsString("const PICK_NAME = 'data-atlas-pick-name';", $js);
        self::assertStringContainsString("const PICK_NOTE = 'data-atlas-pick-note';", $js);
    }

    /** The arming controls are somebody else's markup, so the click is heard from the document, and let go of. */
    public function testArmingIsDelegatedFromTheDocument(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("document.addEventListener('click', this.onPickClick);", $js);
        self::assertStringContainsString("document.removeEventListener('click', this.onPickClick);", $js);
        self::assertStringContainsString('event.target.closest(`[${PICK}]`)', $js);
    }

    /** The mode starts with the map, from what PHP stated, on every map the plate is handed. */
    public function testTheModeIsReadFromTheAtlasPayloadWhenTheMapConnects(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('this.startPick(atlas.pick);', $js);
        self::assertStringContainsString("this.map.on('click', (event) => this.pickAt(event.latlng.lat, event.latlng.lng));", $js);
    }

    /**
     * THE PIN IS A DRAGGABLE MARKER wearing the sheet's own drawing, not a
     * circle marker in a hex colour: Leaflet drags a Marker (`draggable`,
     * `dragend`) and never a CircleMarker, so "drag the pin" was a caption
     * nothing answered.
     */
    public function testThePinIsADraggableMarkerDrawnByTheSheet(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("className: 'atlas-pin'", $js);
        self::assertStringContainsString('draggable: true', $js);
        self::assertStringContainsString("this.pin.on('dragend'", $js);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}\b/', (string) preg_replace('~/\*.*?\*/~s', '', $js));

        $sheet = (string) file_get_contents(\dirname(__DIR__, 3).'/public/map.css');
        self::assertStringContainsString('.atlas-pin i', $sheet);
        self::assertStringContainsString('.map-legend .lay .sw.pin', $sheet);
        self::assertStringContainsString('.map-plate > .pickcap', $sheet);
    }

    /** The point is written into the stated inputs at the stated precision, and the form's note says so. */
    public function testThePointIsWrittenIntoTheStatedInputsAtTheStatedPrecision(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString('form.querySelector(`[name="${this.pick.latitude}"]`)', $js);
        self::assertStringContainsString('form.querySelector(`[name="${this.pick.longitude}"]`)', $js);
        self::assertStringContainsString('.toFixed(this.pick.precision)', $js);
        self::assertStringContainsString('form.querySelector(`[${PICK_NOTE}]`)', $js);
    }

    /** Adding writes straight through; moving proposes, and "Use this point" commits. */
    public function testAddingWritesThroughAndMovingWaitsForTheCommit(): void
    {
        $js = self::controllerJs();

        self::assertStringContainsString("if ('add' === this.picking.mode) {", $js);
        self::assertMatchesRegularExpression('/\n    usePoint\(event\) \{/', $js);
    }

    private static function controllerJs(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/map_plate_controller.js');
    }
}
