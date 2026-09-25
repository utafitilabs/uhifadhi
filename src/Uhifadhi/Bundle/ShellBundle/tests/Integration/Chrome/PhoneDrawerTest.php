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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Chrome;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * THE FRAME ON A PHONE — ruled 2026-09-25.
 *
 * At 390px the sidebar was pushed off the screen and nothing brought it back:
 * the top bar had no opener, so the menu could not be reached at all. Below the
 * phone breakpoint the sidebar is now a drawer — hidden on load, the full tree
 * sliding in from the left over a scrim, the brand and a close mark at its
 * head — and the top bar carries the opener at its left.
 *
 * WHAT IS PINNED HERE is what a test can see without a browser: the markup,
 * its ARIA and its Stimulus wiring; the controller's source, read as text the
 * way the rest of this directory reads it; and the sheet's rules, whose
 * values are the incidents slide-over's (DesignsProjects/uhifadhi-web/
 * incidents-report-options.css, section B). The tap itself, the slide and the
 * focus are a browser's, and are render-verified at 390px.
 *
 * @see https://stimulus.hotwired.dev/reference/actions "Sometimes a controller
 *      needs to listen for events dispatched on the global window or document
 *      objects." — and the key filter `keydown.esc`.
 * @see https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/ "Escape: Closes
 *      the dialog." "When a dialog opens, focus moves to an element inside the
 *      dialog." "When a dialog closes, focus returns to the element that
 *      invoked the dialog". "The element that serves as the dialog container
 *      has a role of dialog." "The dialog container element has aria-modal set
 *      to true."
 */
final class PhoneDrawerTest extends ContractTestCase
{
    /** The breakpoint, stated once for the sheet and once for the controller. */
    private const string PHONE = '(max-width: 900px)';

    /** The motion the slide-over already settled, reused verbatim. */
    private const string SLIDE = 'transform .3s cubic-bezier(.32, .72, 0, 1)';

    private const string FADE = 'opacity .28s ease';

    public function testTheSidebarControllerSpansTheOpenerTheScrimAndTheAside(): void
    {
        $shell = $this->page()->filter('div.shell');

        self::assertCount(1, $shell);
        self::assertSame(self::id(), $shell->attr('data-controller'), 'The opener sits in the top bar and the scrim beside the aside; only a controller on .shell reaches all three.');
        self::assertStringContainsString('keydown.esc@document->'.self::id().'#close', (string) $shell->attr('data-action'), 'Escape closes, wherever focus is when it is pressed.');

        $side = $this->page()->filter('div.shell > aside.side');
        self::assertCount(1, $side);
        self::assertSame('side', $side->attr('id'));
        self::assertSame('side', $side->attr(self::target()));
        self::assertSame('click->'.self::id().'#follow', $side->attr('data-action'), 'Following a link in the drawer closes it.');
        self::assertNull($side->attr('data-controller'), 'The sidebar controller moved to .shell; a second instance on the aside would own nothing.');
    }

    public function testTheOpenerIsTheFirstThingInTheTopBarAndSaysWhatItControls(): void
    {
        $opener = $this->page()->filter('header.topbar > .tb-right > button.side-open');

        self::assertCount(1, $opener);
        self::assertStringContainsString('side-open', (string) $this->page()->filter('header.topbar > .tb-right > *')->first()->attr('class'), 'The opener sits at the bar\'s left, before the organization\'s name.');
        self::assertSame('button', $opener->attr('type'));
        self::assertSame(self::id().'#open', $opener->attr('data-action'));
        self::assertSame('opener', $opener->attr(self::target()));
        self::assertSame('side', $opener->attr('aria-controls'));
        self::assertSame('false', $opener->attr('aria-expanded'), 'The drawer is closed on every load: open is derived per tap, never remembered.');
        self::assertSame('Open menu', $opener->attr('aria-label'));
        self::assertCount(1, $opener->filter('svg'), 'lucide menu, through ux_icon(\'shell:menu\').');
        self::assertStringContainsString('tb-icon', (string) $opener->attr('class'), 'It is a top-bar icon like the toggle beside it.');
    }

    public function testTheDrawerHeadCarriesTheBrandAndACloseMark(): void
    {
        $head = $this->page()->filter('aside.side > .side-top');

        self::assertCount(1, $head->filter('a.brand'));
        $close = $head->filter('button.side-close');
        self::assertCount(1, $close);
        self::assertSame('button', $close->attr('type'));
        self::assertSame(self::id().'#close', $close->attr('data-action'));
        self::assertSame('closer', $close->attr(self::target()));
        self::assertSame('Close menu', $close->attr('aria-label'));
        self::assertCount(1, $close->filter('svg'), 'lucide x, through ux_icon(\'shell:x\').');

        self::assertCount(1, $head->filter('button.collapse-btn'), 'The rail\'s button stays; the sheet hides it where there is a drawer.');
    }

    public function testTheScrimSitsBesideTheAsideAndClosesOnATap(): void
    {
        $scrim = $this->page()->filter('div.shell > div.side-scrim');

        self::assertCount(1, $scrim);
        self::assertSame('click->'.self::id().'#close', $scrim->attr('data-action'));
        self::assertSame('true', $scrim->attr('aria-hidden'), 'A pointer affordance; the keyboard closes with Escape and the close mark.');

        $main = $this->page()->filter('div.shell > main.main');
        self::assertSame('main', $main->attr(self::target()), 'The page behind is made inert while the drawer is out.');
    }

    /**
     * THE DIALOG ROLE IS THE OPEN STATE'S, NOT THE MARKUP'S. On a desktop the
     * aside is a sidebar, and announcing it as a modal dialog there would be
     * false; the controller sets the role while the drawer is out.
     */
    public function testTheAsideIsNotADialogUntilItIsOpened(): void
    {
        $side = $this->page()->filter('aside.side');

        self::assertNull($side->attr('role'));
        self::assertNull($side->attr('aria-modal'));
        self::assertStringNotContainsString('drawer-open', (string) $this->page()->filter('div.shell')->attr('class'));
    }

    public function testTheControllerOpensAsAModalDialogAndClosesBackToTheOpener(): void
    {
        $js = self::controller();

        self::assertMatchesRegularExpression("/static targets = \\[[^\\]]*'side'[^\\]]*'opener'[^\\]]*'closer'[^\\]]*'main'[^\\]]*\\]/", $js);
        foreach (['open()', 'close(', 'follow(event)', 'toggle()'] as $method) {
            self::assertStringContainsString($method, $js, \sprintf('The controller answers %s.', $method));
        }

        self::assertStringContainsString("setAttribute('role', 'dialog')", $js);
        self::assertStringContainsString("setAttribute('aria-modal', 'true')", $js);
        self::assertStringContainsString("removeAttribute('role')", $js, 'Closed, the aside is a sidebar again.');
        self::assertStringContainsString("'aria-expanded'", $js);
        self::assertStringContainsString('inert', $js, 'The page behind cannot take focus while the drawer is out.');
        self::assertStringContainsString('this.closerTarget.focus()', $js, 'On open, focus moves into the drawer.');
        self::assertStringContainsString('this.openerTarget.focus()', $js, 'On close, focus returns to the opener.');
        self::assertStringContainsString("closest('a[href]')", $js, 'Following a link closes the drawer.');
        self::assertStringContainsString('drawer-open', $js);
    }

    /**
     * OPEN IS DERIVED, NEVER REMEMBERED — the sidebar ruling. The one key the
     * controller writes is the rail's, and it writes nothing else.
     */
    public function testTheDrawerIsNeverRemembered(): void
    {
        $js = self::controller();

        preg_match_all('/localStorage\.setItem\(\s*\'([^\']+)\'/', $js, $writes);
        self::assertSame(['shell-sidebar'], array_values(array_unique($writes[1])), 'Only the desktop rail is remembered.');
        self::assertStringNotContainsString('sessionStorage', $js);
    }

    /**
     * THE CONTROLLER AND THE SHEET AGREE ON WHERE THE PHONE STARTS. The
     * controller closes the drawer when the viewport grows past it, or a page
     * rotated to landscape keeps an inert main column behind no drawer.
     */
    public function testTheControllerAndTheSheetShareOneBreakpoint(): void
    {
        self::assertStringContainsString("'".self::PHONE."'", self::controller());
        self::assertStringContainsString('matchMedia', self::controller());
        self::assertStringContainsString('@media '.self::PHONE.' {', $this->stylesheet());
    }

    public function testBelowTheBreakpointTheSidebarIsTheSlideOversDrawer(): void
    {
        $phone = $this->phoneBlock();

        self::assertStringContainsString('position: fixed', $phone);
        self::assertStringContainsString('transform: translateX(-100%)', $phone, 'Hidden off the left edge on load.');
        self::assertStringContainsString(self::SLIDE, $phone, 'The slide-over\'s slide.');
        self::assertStringContainsString('.shell.drawer-open .side', $phone);
        self::assertStringContainsString('box-shadow: var(--lift)', $phone);
        self::assertStringContainsString('linear-gradient(180deg, var(--p1), var(--p2))', $phone, 'The slide-over\'s ground.');
        self::assertStringContainsString('visibility: hidden', $phone, 'A closed drawer is out of the tab order and the accessibility tree.');

        self::assertStringContainsString('background: var(--scrim)', $phone);
        self::assertStringContainsString('backdrop-filter: blur(2px)', $phone);
        self::assertStringContainsString(self::FADE, $phone, 'The slide-over\'s fade.');
        self::assertStringContainsString('.shell.drawer-open .side-scrim', $phone);

        self::assertStringContainsString('.side-open { display: grid; }', $phone, 'The opener exists where the drawer does.');
        self::assertStringContainsString('.side .collapse-btn { display: none; }', $phone, 'A drawer has no rail to collapse to.');
        self::assertStringContainsString('.side-close { display: grid; }', $phone);
    }

    public function testAboveTheBreakpointTheOpenerTheScrimAndTheCloseMarkAreNotDrawn(): void
    {
        $outside = str_replace($this->phoneBlock(), '', $this->bare());

        self::assertMatchesRegularExpression('/\.side-open\s*\{[^}]*display:\s*none/', $outside);
        self::assertMatchesRegularExpression('/\.side-close\s*\{[^}]*display:\s*none/', $outside);
        self::assertMatchesRegularExpression('/\.side-scrim\s*\{[^}]*display:\s*none/', $outside);
    }

    /**
     * THE RAIL IS A DESKTOP STATE. A remembered rail must never reach the
     * drawer — the drawer shows the full tree — so every rule that draws the
     * rail lives inside the desktop query and none outside it.
     */
    public function testEveryRailRuleIsScopedToTheDesktop(): void
    {
        $css = $this->bare();

        preg_match_all('/@media \(min-width: 901px\) \{(.*?)\n\}/s', $css, $desktop);
        self::assertNotEmpty($desktop[0], 'The rail lives in a desktop query.');

        $rest = str_replace($desktop[0], '', $css);
        self::assertDoesNotMatchRegularExpression('/(\.side\.rail|html\.shell-rail)[^{}]*\{/', $rest, 'A rail rule outside the desktop query reaches the drawer.');

        self::assertStringContainsString('html.shell-rail .side', implode("\n", $desktop[1]));
    }

    public function testTheDrawerStopsMovingWhereMotionIsRefused(): void
    {
        self::assertMatchesRegularExpression(
            '/@media '.preg_quote(self::PHONE, '/').' and \(prefers-reduced-motion: reduce\) \{[^@]*\.side,\s*\.side-scrim \{ transition: none; \}/s',
            $this->bare(),
        );
    }

    private function page(): Crawler
    {
        return $this->crawl('@fixtures/bare_shell_page.html.twig');
    }

    private static function id(): string
    {
        return ShellBundle::CONTROLLER_PREFIX.'sidebar';
    }

    private static function target(): string
    {
        return 'data-'.self::id().'-target';
    }

    /** The sheet with its comments out, so prose never satisfies a rule. */
    private function bare(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
    }

    private function phoneBlock(): string
    {
        if (1 !== preg_match('/@media '.preg_quote(self::PHONE, '/').' \{.*?\n\}/s', $this->bare(), $found)) {
            self::fail('One phone block draws the drawer.');
        }

        return $found[0];
    }

    private static function controller(): string
    {
        $js = file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/sidebar_controller.js');
        self::assertIsString($js);

        return $js;
    }
}
