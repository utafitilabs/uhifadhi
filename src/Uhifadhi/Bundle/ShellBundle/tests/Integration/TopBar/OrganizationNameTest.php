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
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\NamedHostKernel;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Shell\UserBadge;

/**
 * THE ORGANIZATION'S NAME IN THE CHROME (ruled 2026-09-24, option C3).
 *
 * The chrome says UHIFADHI — the product — and never said whose installation
 * this is. It does now, in the one slot with the measure for it: the top bar's
 * left half, as the brand's accent rule, the organization's full name, a
 * hairline and the short name. The mark and the wordmark in the sidebar head
 * are untouched, and the name is stated in exactly one slot.
 *
 * WHAT IS DRAWN IS THE SETTING, READ THROUGH THE SETTINGS SECTION — the same
 * OrganizationIdentity Settings › Organization prints, so the bar and that
 * screen can never disagree about what this installation is called.
 */
final class OrganizationNameTest extends ContractTestCase
{
    private const string PAGE = '@fixtures/body_only_page.html.twig';

    protected static function getKernelClass(): string
    {
        return NamedHostKernel::class;
    }

    /**
     * THE LOCKUP: the rule, the full name, the short name. One phrase in the
     * bar's left half, on every page in the product.
     */
    public function testTheBarCarriesTheOrganizationsFullNameAndItsShortName(): void
    {
        $crawler = $this->crawl(self::PAGE);

        $lockup = $crawler->filter('header.topbar .tb-left');
        self::assertCount(1, $lockup, 'The organization is named once, in the bar.');
        self::assertCount(1, $lockup->filter('.tb-rule'), "The brand's accent rule opens the lockup.");
        self::assertSame('Uhifadhi Conservation Authority', trim($lockup->filter('b')->text()));
        self::assertSame('UCA', trim($lockup->filter('.tb-short')->text()));
    }

    /**
     * THE SIDEBAR HEAD IS UNTOUCHED — the mark and the UHIFADHI wordmark stay
     * exactly as they were. The name is stated in ONE slot, which is the whole
     * reason the other five placements were closed.
     */
    public function testTheSidebarHeadKeepsTheProductsWordmark(): void
    {
        $crawler = $this->crawl(self::PAGE);

        self::assertSame('UHIFADHI', trim($crawler->filter('.side .brand b')->text()));
        self::assertCount(0, $crawler->filter('.side .tb-short'), 'The organization is not said twice.');
    }

    /**
     * A NAME THAT DOES NOT FIT TRUNCATES WITH AN ELLIPSIS AND CARRIES THE WHOLE
     * OF ITSELF AS ITS TITLE. The clipping is the sheet's — a bounded measure
     * and text-overflow — so the markup's part of the rule is the title
     * attribute, and it is asserted here.
     */
    public function testALongNameIsBoundedAndCarriesTheFullNameAsItsTitle(): void
    {
        $long = 'Kilimani Crater and Olkeju Highlands Wildlife Conservation Authority';
        self::assertGreaterThanOrEqual(60, \strlen($long), 'The fixture is a name that does not fit.');

        NamedHostKernel::$organization = new OrganizationIdentity($long, 'KCOHCA');

        $name = $this->crawl(self::PAGE)->filter('header.topbar .tb-left b');
        self::assertSame($long, trim($name->text()), 'The whole name is in the document; the sheet does the clipping.');
        self::assertSame($long, $name->attr('title'), 'And the whole of it is readable on hover.');

        $sheet = $this->stylesheet();
        self::assertMatchesRegularExpression(
            '/\.tb-left b \{[^}]*text-overflow: ellipsis/',
            $sheet,
            'The name that does not fit ends in an ellipsis.',
        );
        self::assertMatchesRegularExpression(
            '/\.tb-left b \{[^}]*max-width: min\(38vw, 370px\)/',
            $sheet,
            "The measure the design fixed: the bar's left half and no more.",
        );
    }

    /**
     * THE SHORT NAME IS THE NAME'S INITIALS UNTIL SOMEBODY SETS ONE. The
     * initials are a starting guess and nothing more — a national parks
     * authority calls itself TANAPA, not TNP — so they are what the field is
     * prefilled with, never what it is pinned to.
     */
    public function testTheShortNameFallsBackToTheNamesInitials(): void
    {
        NamedHostKernel::$organization = new OrganizationIdentity('Uhifadhi Conservation Authority');

        self::assertSame('UCA', trim($this->crawl(self::PAGE)->filter('header.topbar .tb-short')->text()));
    }

    /**
     * THE CHIP'S SECOND LINE OPENS WITH THE SHORT NAME: the installation, then
     * the seat. The seat is the badge contract's half — the shell composes no
     * identity — and the organization is the shell's, because the bar already
     * reads it.
     */
    public function testTheViewerChipsSecondLineOpensWithTheShortName(): void
    {
        NamedHostKernel::$userBadge = UserBadge::fromName('N. Kileo', 'operator');

        $crawler = $this->crawl(self::PAGE);

        self::assertSame('UCA · operator', trim($crawler->filter('header.topbar .uinfo em')->text()));
    }

    /**
     * THE BROWSER TITLE ENDS WITH THE ORGANIZATION — "<page> — <Organization>",
     * which is what a bookmark, a tab strip and a printed page carry. The
     * wordmark is what an installation nobody has named falls back to.
     */
    public function testThePageTitleEndsWithTheOrganization(): void
    {
        $title = $this->crawl('@fixtures/bare_document_page.html.twig')->filter('title');

        self::assertStringEndsWith('Uhifadhi Conservation Authority', trim($title->text()));
    }
}
