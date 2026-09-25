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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * ONE CONTROL MOVES A ROW WITHIN AN ORDERED LIST — ruled 2026-09-25.
 *
 * The ranks ladder and an area's running modules each had a controller of
 * their own for the same job, and neither said where a dragged row would
 * land. The shell's `reorder` controller replaces both: a pointer drag on the
 * grip that lifts the row and opens an empty slot where it will land, up and
 * down carets on every row, the arrow keys on the focused grip, live
 * renumbering and a polite live line.
 *
 * WHAT IS PINNED HERE is what a test can see without a browser: the
 * controller's source, read as text; the sheet's rules, whose values are the
 * design's (DesignsProjects/uhifadhi-web/reorder.css); and the two manifests
 * that hand the controller to an installation. The drag itself is a
 * browser's, and is render-verified on /team/configure/ranks and
 * /areas/{uuid}/configure/modules.
 *
 * @see https://stimulus.hotwired.dev/reference/targets "Define a method
 *      `[name]TargetConnected` or `[name]TargetDisconnected` in the controller"
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Element/setPointerCapture
 *      "used to designate a specific element as the capture target of future
 *      pointer events"
 * @see https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Attributes/aria-live
 *      polite: "updates to the region should be presented at the next graceful
 *      opportunity"
 */
final class ReorderControllerTest extends TestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--reorder';

    /** The gap's motion: the slide-over's curve, in the design's .18s. */
    private const string MOTION = '.18s cubic-bezier(.32, .72, 0, 1)';

    public function testTheControllerNamesItsTargetsAndItsOptionalWrite(): void
    {
        $js = self::controller();

        self::assertMatchesRegularExpression("/static targets = \\['row', 'grip', 'up', 'down', 'number', 'status'\\];/", $js);
        self::assertStringContainsString('static values = { url: String, token: String, first: { type: Number, default: 1 } };', $js, 'the number the first movable row wears: 1, or 2 below a pinned row');
    }

    public function testTheCaretsAndTheArrowKeysMoveOneStep(): void
    {
        $js = self::controller();

        self::assertStringContainsString('up(event) {', $js);
        self::assertStringContainsString('down(event) {', $js);
        self::assertStringContainsString('this.step(event, -1)', $js);
        self::assertStringContainsString('this.step(event, 1)', $js);
        // A caret step slides the two rows past each other (FLIP), lifted, on
        // the settle's curve; where motion is refused it swaps in place.
        self::assertStringContainsString('this.slide([row, other], before)', $js);
        self::assertStringContainsString("classList.add(i === 0 ? 'reorder-stepping' : 'reorder-passing')", $js);
        self::assertStringContainsString('if (!this.still())', $js);
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css');
        self::assertStringContainsString('.reorder-stepping, .reorder-passing { transition: transform .18s cubic-bezier(.32, .72, 0, 1); }', $css);
        self::assertStringContainsString('.reorder-stepping { position: relative; z-index: 1; box-shadow: var(--lift);', $css);
        self::assertStringContainsString('.before(row)', $js, 'up puts the row before the one above it');
        self::assertStringContainsString('.after(row)', $js, 'down puts it after the one below');
        self::assertStringContainsString('.focus(', $js, 'focus stays on the control that moved the row');
    }

    public function testTheEndsAreDisabledAndTheNumbersRepaintedFromThePlaces(): void
    {
        $js = self::controller();

        self::assertMatchesRegularExpression('/paint\(\) \{.*?\.disabled = index === 0.*?\.disabled = index === last/s', $js);
        self::assertMatchesRegularExpression('/paint\(\) \{.*?textContent = String\(index \+ this\.firstValue\)/s', $js);
    }

    public function testThePointerDragLiftsTheRowAndOpensASlotWhereItWillLand(): void
    {
        $js = self::controller();

        foreach (['grab(event) {', 'move(event) {', 'drop(event) {', 'cancel(event) {'] as $method) {
            self::assertStringContainsString($method, $js);
        }
        self::assertStringContainsString('setPointerCapture(event.pointerId)', $js, 'the grip keeps the pointer, mouse or touch, until it is released');
        self::assertStringContainsString("touchAction = 'none'", $js, 'a touch on the grip drags the row instead of scrolling the page');
        self::assertStringContainsString("'reorder-lifted'", $js);
        self::assertStringContainsString("'reorder-slot'", $js);
        self::assertStringContainsString("'reorder-gap'", $js);
        self::assertStringContainsString("'--reorder-h'", $js, 'the slot is the dragged row\'s own height');
        self::assertStringContainsString("'closing'", $js, 'the slot it leaves closes behind it');
        self::assertStringContainsString("'reorder-settling'", $js, 'on release the row settles into the slot');
        self::assertStringContainsString("'TR'", $js, 'a table row keeps its cells\' widths while it is lifted');
        self::assertStringContainsString('colSpan', $js, 'and its slot is one cell across the table');
    }

    public function testReducedMotionIsAskedOnceAndHonoured(): void
    {
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", self::controller());
    }

    public function testEveryMoveIsAnnouncedPolitely(): void
    {
        $js = self::controller();

        self::assertStringContainsString('moved to position', $js);
        self::assertStringContainsString('this.statusTarget.textContent', $js);
        self::assertStringContainsString('dataset.reorderName', $js);
    }

    /**
     * THE ORDER IS THE PAGE'S TO SEND. A form reads it from its own field
     * order and sends nothing until Save; a list that saves as it moves
     * names a URL, and the rows' keys are posted as `order[]`, one write
     * after another so a later order is never overtaken by an earlier one.
     */
    public function testTheOrderIsPostedOnlyWhereTheListNamesAnAddress(): void
    {
        $js = self::controller();

        self::assertStringContainsString("body.append('order[]', ", $js);
        self::assertStringContainsString('dataset.reorderKey', $js);
        self::assertStringContainsString("body.append('_token', this.tokenValue)", $js);
        self::assertMatchesRegularExpression('/send\(\) \{\s*if \(!this\.hasUrlValue \|\| this\.urlValue === \'\'\) \{\s*return;/', $js);
        self::assertStringContainsString('this.sending = this.sending', $js, 'one write after another');
    }

    public function testNothingIsRemembered(): void
    {
        $js = self::controller();

        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
    }

    public function testTheSheetDrawsTheCaretsTheGapAndTheLiftedRow(): void
    {
        $css = self::sheet();

        self::assertMatchesRegularExpression('/\.reorder \{[^}]*grid-template-rows: 17px 17px;[^}]*width: 18px;/', $css, 'up over down, 34px together: the control height');
        self::assertMatchesRegularExpression('/\.reorder button \{[^}]*width: 18px; height: 17px;/', $css);
        self::assertMatchesRegularExpression('/\.reorder button svg \{ width: 12px; height: 12px; \}/', $css);
        self::assertMatchesRegularExpression('/\.reorder button:disabled \{[^}]*opacity: \.3;/', $css);
        self::assertStringContainsString('.reorder button:focus-visible', $css);

        self::assertMatchesRegularExpression('/\.reorder-gap \{[^}]*height: var\(--reorder-h\);[^}]*border: 1px dashed var\(--ln2\);[^}]*border-radius: 8px;/s', $css);
        self::assertStringContainsString('animation: reorder-open '.self::MOTION, $css);
        self::assertStringContainsString('animation: reorder-close '.self::MOTION.' forwards', $css);
        self::assertStringContainsString('@keyframes reorder-open', $css);
        self::assertStringContainsString('@keyframes reorder-close', $css);
        self::assertStringContainsString('tr.reorder-slot > td { padding: 0; border: 0; }', $css);
        self::assertMatchesRegularExpression('/\.reorder-slot \{[^}]*--reorder-h: 45px;/', $css, 'the ladder row\'s height until the controller states the dragged row\'s own');

        self::assertMatchesRegularExpression('/\.reorder-lifted \{[^}]*box-shadow: var\(--lift\);/s', $css, 'the overlay\'s shadow');
        self::assertMatchesRegularExpression('/\.reorder-lifted \{[^}]*border-radius: 8px;/s', $css);
        self::assertStringContainsString('linear-gradient(180deg, var(--p1), var(--p2))', $css);
        self::assertStringContainsString('.reorder-settling { transition: transform '.self::MOTION.'; }', $css);
    }

    public function testTheGapStopsMovingWhereMotionIsRefused(): void
    {
        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\.reorder-gap,\s*\.reorder-slot\.closing \.reorder-gap \{ animation: none; \}\s*\.reorder-settling \{ transition: none; \}/s',
            self::sheet(),
        );
    }

    /** Both manifests hand the controller over, under the name the markup uses. */
    public function testTheControllerIsDeclaredInBothManifests(): void
    {
        $bundle = self::manifest(\dirname(__DIR__, 3).'/assets/package.json');
        self::assertSame('controllers/reorder_controller.js', $bundle['reorder']['main'] ?? null);
        self::assertSame(self::NAME, $bundle['reorder']['name'] ?? null);
        self::assertTrue($bundle['reorder']['enabled'] ?? null);

        $root = self::manifest(\dirname(__DIR__, 7).'/assets/package.json');
        self::assertSame('../src/Uhifadhi/Bundle/ShellBundle/assets/controllers/reorder_controller.js', $root['reorder']['main'] ?? null);
        self::assertSame(self::NAME, $root['reorder']['name'] ?? null);
        self::assertTrue($root['reorder']['enabled'] ?? null);
    }

    public function testTheUpCaretIsLucidesChevronUp(): void
    {
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m18 15l-6-6l-6 6"/></svg>',
            trim((string) file_get_contents(\dirname(__DIR__, 3).'/assets/icons/shell/chevron-up.svg')),
        );
    }

    private static function controller(): string
    {
        $js = file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/reorder_controller.js');
        self::assertIsString($js, 'the shell ships assets/controllers/reorder_controller.js');

        return $js;
    }

    /** The shell's sheet with its comments out, so prose never satisfies a rule. */
    private static function sheet(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(\dirname(__DIR__, 3).'/public/shell.css'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function manifest(string $path): array
    {
        $package = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertIsArray($package['symfony'] ?? null);
        $controllers = $package['symfony']['controllers'] ?? null;
        self::assertIsArray($controllers);

        /** @var array<string, array<string, mixed>> $controllers */
        return $controllers;
    }
}
