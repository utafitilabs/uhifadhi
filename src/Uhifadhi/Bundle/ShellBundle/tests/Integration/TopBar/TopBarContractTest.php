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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\TopBar;

use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\HostKernel;
use Uhifadhi\Contracts\Shell\UserBadge;
use Uhifadhi\Contracts\Shell\UserBadgeSourceInterface;

/**
 * SPEC 7 — THE TOP BAR.
 *
 * Frame chrome, one definition for every page: the row across the top of the
 * main column carries the alerts bell, the theme toggle and the viewer's card.
 * A module writes none of it — it arrives because the frame is the frame, the
 * same claim the sidebar and the page frame make.
 *
 * Two of the three are the shell's own and render on any installation: the
 * toggle (wired, see FurnitureBehaviourTest) and the bell (a placeholder). The
 * third is a CONTRACT, mirrored on the nav's: the shell owns that the card is an
 * avatar, a name and a quiet context line, and owns none of who that is —
 * content arrives through {@see UserBadgeSourceInterface}, already composed, and
 * the shell draws whatever it is handed.
 */
final class TopBarContractTest extends ContractTestCase
{
    private const string PAGE = '@fixtures/body_only_page.html.twig';

    /**
     * THE BAR IS ON EVERY PAGE, BODY-ONLY INCLUDED. A module that fills nothing
     * but its body still gets the top bar, because it is furniture, not content.
     */
    public function testTheFrameRendersTheTopBarOnAPageThatFillsOnlyItsBody(): void
    {
        $crawler = $this->crawl(self::PAGE);

        self::assertCount(1, $crawler->filter('header.topbar'), 'The top bar comes with the frame.');
        self::assertCount(1, $crawler->filter('header.topbar span.tb-right'));
    }

    /**
     * THE TOGGLE AND THE BELL ARE THE SHELL'S OWN, so they render with no host
     * data at all — no viewer, no team, nothing aliased for the badge.
     */
    public function testTheThemeToggleAndTheBellRenderWithoutAnyHostData(): void
    {
        $crawler = $this->crawl(self::PAGE);

        // The wired toggle: a real button, addressed by the shell's controller.
        $toggle = $crawler->filter('header.topbar button.tb-icon');
        self::assertCount(1, $toggle);
        self::assertSame('uhifadhi--shell-bundle--theme#toggle', $toggle->attr('data-action'));

        // The bell: present, and honestly inert.
        self::assertCount(1, $crawler->filter('header.topbar .tb-icon.off'));
    }

    /**
     * THE BELL IS A PLACEHOLDER, AND HONESTLY ONE. Alerts has no backend, so the
     * bell renders in the platform's settled treatment for a planned thing —
     * dimmed, not a control, titled — and carries NO count. A badge with a
     * number nothing produces is the dead furniture this shell exists to end.
     */
    public function testTheBellIsAnInertPlaceholderWithNoCount(): void
    {
        $crawler = $this->crawl(self::PAGE);

        $bell = $crawler->filter('header.topbar .tb-icon.off');
        self::assertCount(1, $bell);
        self::assertSame('span', $bell->nodeName(), 'A placeholder is not a control: it is a span, not a button.');
        self::assertSame('Alerts — planned', $bell->attr('title'), 'It says it is coming, as the nav row does.');
        self::assertCount(0, $crawler->filter('header.topbar .badge'), 'No count until a real number backs it.');
        self::assertNull($bell->attr('data-action'), 'A placeholder is wired to nothing.');
    }

    /**
     * THE CARD IS DRAWN FROM WHAT THE CONTRACT COMPOSED — avatar initials, the name,
     * and the org·role line — exactly as handed over, the slugless way the nav
     * renders its rows.
     */
    public function testTheViewerCardIsRenderedFromTheContract(): void
    {
        HostKernel::$userBadge = new UserBadge('N. Kileo', 'NK', 'UCA · operator');

        $crawler = $this->crawl(self::PAGE);

        $card = $crawler->filter('header.topbar span.user');
        self::assertCount(1, $card);
        self::assertSame('NK', trim($card->filter('.avatar')->text()));
        self::assertSame('N. Kileo', trim($card->filter('.uinfo b')->text()));
        self::assertSame('UCA · operator', trim($card->filter('.uinfo em')->text()));
    }

    /**
     * A MINIMAL BADGE HAS A NAME AND NO CONTEXT LINE — the fallback a bare host
     * gets by handing over only a name. The card draws the name and the derived
     * initials and renders NO empty context element, the frame's rule for every
     * optional region.
     */
    public function testABadgeWithoutAContextLineRendersNoContextElement(): void
    {
        HostKernel::$userBadge = UserBadge::fromName('N. Kileo');

        $crawler = $this->crawl(self::PAGE);

        self::assertSame('NK', trim($crawler->filter('header.topbar .avatar')->text()));
        self::assertSame('N. Kileo', trim($crawler->filter('header.topbar .uinfo b')->text()));
        self::assertCount(0, $crawler->filter('header.topbar .uinfo em'), 'No org/role line, and so no empty <em>.');
    }

    /**
     * NO VIEWER, NO CARD — and still a whole top bar. A fresh installation, a
     * sign-in page, an anonymous request: the registry names nobody, and the shell
     * draws the bar with its toggle and bell and no user pill, rather than an
     * empty one or an error.
     */
    public function testWithNoViewerThereIsNoCardButStillATopBar(): void
    {
        HostKernel::$userBadge = null;

        $crawler = $this->crawl(self::PAGE);

        self::assertCount(1, $crawler->filter('header.topbar'));
        self::assertCount(1, $crawler->filter('header.topbar button.tb-icon'), 'The toggle is the shell\'s own and stays.');
        self::assertCount(0, $crawler->filter('header.topbar span.user'), 'No viewer to name means no card, not an empty one.');
    }

    /**
     * THE BADGE IS READ LIVE, per render — signing out takes the card with it on
     * the next request, the same-day promise the whole contract layer makes.
     */
    public function testTheCardIsReadLiveSoItVanishesTheSameRequestTheViewerGoes(): void
    {
        HostKernel::$userBadge = new UserBadge('N. Kileo', 'NK', 'UCA · operator');
        self::assertStringContainsString('N. Kileo', $this->render(self::PAGE));

        HostKernel::$userBadge = null;
        self::assertStringNotContainsString('N. Kileo', $this->render(self::PAGE));
    }

    /**
     * THE CONTRACT IS A REAL CONTRACT. The shell can be driven to a named top bar by a
     * host that is four lines of fixture with no account class in sight — which
     * is the claim, that the card is data the shell is handed, not an entity it
     * reaches for.
     */
    public function testTheContractNeedsNoAccountClassToNameTheViewer(): void
    {
        self::assertTrue(interface_exists(UserBadgeSourceInterface::class));

        // The contract traffics in a plain value object, not a UserInterface:
        // badge() hands back a UserBadge or null, and the shell draws it without
        // ever seeing the account model. The registry lives in the contracts package now,
        // so an implementing module depends only on contracts — never on the
        // shell — which is why team can implement it with shell in require-dev.
        $badge = new \ReflectionMethod(UserBadgeSourceInterface::class, 'badge');
        self::assertSame('?'.UserBadge::class, (string) $badge->getReturnType(), 'The registry hands over a composed card, never an account.');
    }
}
