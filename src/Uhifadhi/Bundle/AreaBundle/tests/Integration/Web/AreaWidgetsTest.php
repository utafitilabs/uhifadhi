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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;

/**
 * THE AREAS-INDEX WIDGET LIBRARY, RENDERED — the five whole-page layouts the
 * landing ships, previewable inline and adopt-only, from the same real rows the
 * register reads.
 *
 * What is asserted is what a person would see and what the design forbids: the
 * five presets by their real names, each layout rendered from real data, the
 * preview mechanic wired, and self-containment — no A–E letters, no link out to
 * the design scratchboard.
 */
final class AreaWidgetsTest extends WebTestCase
{
    private function body(string $path): string
    {
        $this->browser()->request('GET', $path);

        return (string) $this->browser()->getResponse()->getContent();
    }

    public function testTheLibraryNamesTheFivePresetsByTheirRealNames(): void
    {
        $this->boot();
        $this->anArea();

        $body = $this->body('/areas/widgets');

        foreach (['Wall of workspaces', 'The register', 'Map of the network', 'Attention board', 'The flagship'] as $name) {
            self::assertStringContainsString($name, $body);
        }
    }

    /**
     * THE PRESETS ARE ADOPT-ONLY AND WIRED. The strip is the preset controller's;
     * each card is a preview button carrying its layout key; the sticky bar rests
     * on the shipped default.
     */
    public function testThePresetStripIsWiredToThePresetController(): void
    {
        $this->boot();
        $this->anArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('data-controller="uhifadhi--area-bundle--area-presets"', $body);
        self::assertStringContainsString('data-view="wall"', $body);
        self::assertStringContainsString('data-view="flagship"', $body);
        // Wall is the shipped default: its card is active on first render.
        self::assertStringContainsString('w-preset-active', $body);
        self::assertStringContainsString('w-previewbar', $body);
    }

    /**
     * THE DEFAULT LAYOUT OPENS VISIBLE, THE REST HIDDEN — so a viewer with no
     * scripting still sees the wall, and the controller reveals the others.
     */
    public function testTheDefaultLayoutOpensVisibleAndTheRestAreHidden(): void
    {
        $this->boot();
        $this->anArea();

        $body = $this->body('/areas/widgets');

        self::assertMatchesRegularExpression('/data-view="wall"(?![^>]*hidden)/', $body);
        self::assertMatchesRegularExpression('/data-view="register"[^>]*hidden/', $body);
    }

    /**
     * SELF-CONTAINED. The five layouts are embedded here; nothing links out to the
     * design scratchboard the presets were graduated from, and no A–E letter
     * survives from that workspace.
     */
    public function testTheLibraryIsSelfContainedWithNoScratchboardLinksOrLetters(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertStringNotContainsString('presets/', $body);
        self::assertStringNotContainsString('.html', $body);
        // No "Option A"/"A ·"/letter labels from the scratchboard.
        self::assertDoesNotMatchRegularExpression('/\bOption [A-E]\b/', $body);
        self::assertDoesNotMatchRegularExpression('/\b[A-E] &middot;/', $body);
    }

    /**
     * THE REGISTER LAYOUT'S OPERATIONAL COLUMNS ARE THE MODULES' NOW-TILES, not a
     * science table and not a figure the area page names. The columns are whatever
     * labels the now-tile contribution handed back.
     */
    public function testTheRegisterLayoutDrawsTheOperationalColumnsFromTheContributions(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('ax-reg', $body);
        self::assertStringContainsString('patrols this wk', $body);
        self::assertStringContainsString('team on duty', $body);
        // The header is sortable, wired to the register controller.
        self::assertStringContainsString('data-sortcol="modules"', $body);
    }

    /**
     * THE MAP LAYOUT IS THE PLATFORM'S REAL MAP — the atlas's plate, each area
     * travelling as GeoJSON for it to draw. The dock beside it lists the same
     * rows.
     */
    public function testTheMapLayoutReusesTheRealMapPlateAndDocksTheList(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('data-controller="uhifadhi--atlas-bundle--map-plate"', $body);
        self::assertStringContainsString('MultiPolygon', $body);
        self::assertStringContainsString('ax-docklist', $body);
    }

    /**
     * THE ATTENTION BOARD GROUPS AREAS BY WHAT NEEDS THE OPERATOR, and prints an
     * area's actual attention items — the same contribution the overview reads.
     */
    public function testTheAttentionBoardGroupsAndListsTheItems(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('Needs attention', $body);
        self::assertStringContainsString('Running steady', $body);
        self::assertStringContainsString('Awaiting setup', $body);
        // The item's headline, from the fake attention contributor.
        self::assertStringContainsString('A patrol has stopped pinging', $body);
    }

    /** THE FLAGSHIP FEATURES THE LIVE AREA with its factband and KPIs. */
    public function testTheFlagshipFeaturesTheLiveArea(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('ax-hero', $body);
        // The factband is the area's own facts (the area page's), not a module's.
        self::assertStringContainsString('Extent', $body);
    }

    /**
     * THE CARD FACE IS A REAL ESRI SNIPPET WITH THE OUTLINE OVER IT — a plain
     * `<img>` to the keyless World Imagery export for the boundary bbox, with the
     * real gazetted outline drawn on top.
     */
    public function testTheCardFaceIsAnEsriImageWithTheOutlineOverlaid(): void
    {
        $this->boot();
        $this->anArea();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('<img class="ax-sat"', $body);
        self::assertStringContainsString('World_Imagery/MapServer/export', $body);
        // The boundary outline is drawn over the snippet.
        self::assertStringContainsString('ax-outline', $body);
        self::assertStringContainsString('<path d="M', $body);
    }

    /**
     * THE FLAGSHIP'S FACE IS THE SAME ATLAS THUMBNAIL as a card's: the
     * snippet as an image and the outline over it, not a background written
     * on the page.
     */
    public function testTheFlagshipFaceIsTheAtlasThumbnail(): void
    {
        $this->boot();
        $this->aLiveArea();

        $body = $this->body('/areas/widgets');

        self::assertMatchesRegularExpression('/<div class="ax-hero-map">\s*<img class="ax-sat" src="[^"]*World_Imagery[^"]*"[^>]*><svg class="ax-outline"/', $body);
        self::assertDoesNotMatchRegularExpression('/class="ax-hero-map[^"]*" style=/', $body);
    }

    /** A boundary-less area gets the neutral ground — no satellite snippet. */
    public function testABoundarylessAreaHasNoSatelliteSnippet(): void
    {
        $this->boot();
        $area = new AreaOfInterest()->setName('Unmapped Reserve');
        $this->em->persist($area);
        $this->em->flush();

        $body = $this->body('/areas/widgets');

        self::assertStringContainsString('Unmapped Reserve', $body);
        self::assertStringNotContainsString('World_Imagery', $body);
    }

    /** THE LIBRARY IS REFUSED to somebody without areas.read — refused, not emptied. */
    public function testTheLibraryIsRefusedWithoutAreaView(): void
    {
        $this->boot([]);
        $this->anArea();

        $this->browser()->request('GET', '/areas/widgets');

        self::assertContains($this->browser()->getResponse()->getStatusCode(), [401, 403]);
    }
}
