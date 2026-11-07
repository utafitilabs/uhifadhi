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
 * THE LANDING FOLLOWS THE LIBRARY — the whole round trip, through the real
 * routes.
 *
 * A preset library is not ported until the SURFACE ITSELF draws what was adopted
 * in it. The areas library had every part of that except the last one: a person
 * could preview all five layouts and apply one, and `/areas` went on drawing the
 * wall of workspaces, because the adoption lived in the browser's own store and
 * no server ever read it.
 *
 * So this file applies a preset the way a person does — the library page, its
 * token, its POST — and then asks the REGISTER what it draws. The five layouts
 * are told apart by the composition each one owns: the card gallery, the
 * operational table, the map and its dock, the three attention bands, the hero.
 */
final class AreaLandingPresetTest extends WebTestCase
{
    use HouseRegisterAssertions;

    /** The markup only one of the five layouts ever emits. */
    private const array SIGNATURE = [
        'wall' => 'ax-cards',
        'register' => 'ax-reg',
        'map' => 'ax-docklist',
        'attention' => 'ax-tri',
        'flagship' => 'ax-hero',
    ];

    /** WHERE EVERYBODY STARTS: nobody has adopted anything, so the landing is the wall. */
    public function testTheLandingDrawsTheShippedWallUntilSomethingIsAdopted(): void
    {
        $this->arrange();

        $landing = $this->body('/areas');

        self::assertStringContainsString(self::SIGNATURE['wall'], $landing);
        self::assertStringNotContainsString(self::SIGNATURE['register'], $landing);
    }

    /**
     * THE SWITCH, END TO END: adopt "The register" in the library and the landing
     * is the register — the table, not the wall.
     */
    public function testAdoptingTheRegisterMakesItTheLanding(): void
    {
        $this->arrange();

        $this->adopt('register');

        $landing = $this->body('/areas');
        self::assertStringContainsString(self::SIGNATURE['register'], $landing);
        self::assertStringNotContainsString(self::SIGNATURE['wall'], $landing);
    }

    /**
     * THE REGISTER IS A HOUSE REGISTER: the state chip with its counts and the
     * search above, the house table, the foot paged in the browser.
     */
    public function testTheRegisterLayoutWearsTheHouseBarAndFoot(): void
    {
        $this->arrange();
        $this->anArea('Southern Conservation Reserve');
        $this->adopt('register');

        $landing = $this->body('/areas');
        $register = '[data-controller~="uhifadhi--area-bundle--area-register"]';

        self::assertHouseBar($landing, $register, 1);
        self::assertHouseTable($landing, $register);
        self::assertHouseFoot($landing, $register, '2 areas');
        $chips = self::chips($landing, $register);
        self::assertSame('all · 2', $chips[0]['chip']);
        self::assertSame(['All', 'Live', 'With alerts', 'Awaiting setup'], array_column($chips[0]['options'], 'label'));
        self::assertSame(['2', '1', '1', '1'], array_column($chips[0]['options'], 'count'));
        self::assertStringNotContainsString('class="subnav"', $landing, 'The pills are the chip now.');
    }

    /** And every other layout is the same trip. */
    public function testEveryLayoutTheLibraryOffersBecomesTheLanding(): void
    {
        $this->arrange();

        foreach (self::SIGNATURE as $key => $signature) {
            $this->adopt($key);

            self::assertStringContainsString($signature, $this->body('/areas'), $key.' was adopted but the landing does not draw it');
        }
    }

    /** The landing says which layout it is wearing, in the layout's own words. */
    public function testTheLandingWearsTheAdoptedLayoutsOwnSubtitle(): void
    {
        $this->arrange();

        $this->adopt('attention');

        self::assertStringContainsString('grouped by what needs the operator', $this->body('/areas'));
    }

    /** The library marks the adopted layout as the active one, read off the server. */
    public function testTheLibraryMarksTheAdoptedLayoutActive(): void
    {
        $this->arrange();

        $this->adopt('flagship');

        $library = $this->body('/areas/widgets');
        self::assertMatchesRegularExpression('/data-preset-id="flagship"[^>]*aria-pressed="true"/', $library);
        self::assertSame(1, substr_count($library, 'w-presetflag-active'), 'exactly one card wears Active');
    }

    /** Reset drops the adoption and the landing is the shipped wall again. */
    public function testResetPutsTheShippedWallBack(): void
    {
        $this->arrange();
        $this->adopt('map');

        $this->post('/areas/widgets/reset');

        self::assertStringContainsString(self::SIGNATURE['wall'], $this->body('/areas'));
    }

    /** A layout this surface does not ship is refused rather than stored. */
    public function testALayoutTheSurfaceDoesNotShipIsRefused(): void
    {
        $this->arrange();

        $this->post('/areas/widgets/preset/gallery');

        self::assertSame(422, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString(self::SIGNATURE['wall'], $this->body('/areas'));
    }

    /** An adoption is a write, so it carries a token. */
    public function testAnAdoptionWithoutATokenIsRefused(): void
    {
        $this->arrange();

        $this->browser()->request('POST', '/areas/widgets/preset/register');

        self::assertContains($this->browser()->getResponse()->getStatusCode(), [401, 403]);
        self::assertStringContainsString(self::SIGNATURE['wall'], $this->body('/areas'));
    }

    // ---- the arrangement ---------------------------------------------------

    /**
     * AN ATTENTION BOARD IS BOUNDED. Nine things asking for attention draw the
     * latest six and one line saying so with the door to the rest — on the
     * Attention board and on The flagship alike. A board that grew with its
     * list deformed every card beside it.
     */
    public function testAnAttentionBoardIsBoundedWhateverTheQueue(): void
    {
        $this->boot(self::ALL_AREA_PERMISSIONS, 9);
        $this->signInAsPerson();
        $this->aLiveArea();

        foreach (['attention', 'flagship'] as $layout) {
            $this->adopt($layout);
            $landing = $this->body('/areas');

            self::assertSame(6, substr_count($landing, 'class="ao-att '), $layout.' draws the latest six');
            self::assertStringContainsString('6 of 9 &middot; all on the area', $landing, $layout.' says how many wait');
        }
    }

    /**
     * ONE LIVE AREA AND A PERSON TO KEEP A LAYOUT FOR. The flagship and the
     * attention board only have something to draw for a live area, and a widget
     * preference is a record about somebody — so the token holds the
     * installation's own account entity, exactly as it does in a real one.
     */
    private function arrange(): void
    {
        $this->boot();
        $this->signInAsPerson();
        $this->aLiveArea();
    }

    /** Adopt a layout the way a person does: the library page, its token, its POST. */
    private function adopt(string $key): void
    {
        $this->post('/areas/widgets/preset/'.$key);
    }

    private function post(string $path): void
    {
        $this->browser()->request('POST', $path, ['_token' => $this->tokenFromTheLibrary()]);
    }

    private function tokenFromTheLibrary(): string
    {
        return (string) $this->browser()->request('GET', '/areas/widgets')
            ->filter('input[name="_token"]')->first()->attr('value');
    }

    private function body(string $path): string
    {
        $this->browser()->request('GET', $path);

        return (string) $this->browser()->getResponse()->getContent();
    }
}
