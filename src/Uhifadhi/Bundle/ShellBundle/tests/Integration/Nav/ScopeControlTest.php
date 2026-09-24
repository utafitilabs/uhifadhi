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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Nav;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\Service\Scopes;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\FixtureScopeSource;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * HOW WIDE THE PAGE IS LOOKING — the shell's control, and the module's one
 * argument.
 *
 * RULED: the scope control is the SHELL's. Every organization-level surface
 * the seam contributes gets the same one, a module states none of its own,
 * and no module sheet restates it — so "which slice am I looking at" is
 * asked the same way on every page in the product.
 *
 * THE SHELL KNOWS NO AREAS. It holds no domain and no authorization service,
 * so what the control offers arrives from the host, already narrowed to what
 * this account may open. A scope somebody may not see is simply not in the
 * list; there is no disabled option, because a disabled option is a list of
 * the things they are not allowed to look at.
 */
final class ScopeControlTest extends ContractTestCase
{
    private function scopes(): Scopes
    {
        $scopes = $this->service('shell.scopes');
        \assert($scopes instanceof Scopes);

        return $scopes;
    }

    public function testTheControlOffersWhatTheHostSaysThisViewerMaySee(): void
    {
        FixtureScopeSource::$scopes = [
            Scope::organization(),
            Scope::area('area-1', 'Ngorongoro'),
            Scope::area('area-2', 'Pololeti Game Reserve'),
        ];

        $html = $this->render('@Shell/_scope_control.html.twig', [
            'scopes' => $this->scopes()->available(),
            'current' => $this->scopes()->current(),
        ]);
        $crawler = new Crawler($html);

        self::assertSame(
            ['Organization — all areas', 'Ngorongoro', 'Pololeti Game Reserve'],
            $crawler->filter('.ov-ctl option')->each(static fn (Crawler $n): string => trim($n->text())),
        );
        self::assertSame('Scope', trim($crawler->filter('.ov-ctl .k')->text()));
    }

    /**
     * IT WORKS WITH NO SCRIPT. The control is a real GET form: it submits on
     * Enter and the page reloads at the new address, and the script only adds
     * the click that would otherwise be a keystroke.
     */
    public function testTheControlIsARealFormAndNotAScriptedOne(): void
    {
        FixtureScopeSource::$scopes = [Scope::organization(), Scope::area('area-1', 'Ngorongoro')];

        $crawler = new Crawler($this->render('@Shell/_scope_control.html.twig', [
            'scopes' => $this->scopes()->available(),
            'current' => $this->scopes()->current(),
        ]));

        $form = $crawler->filter('form.ov-ctl');
        self::assertCount(1, $form);
        self::assertSame('get', $form->attr('method'));
        self::assertSame(Scopes::PARAMETER, $crawler->filter('.ov-ctl select')->attr('name'));
    }

    /**
     * ONE SLICE IS NO CHOICE. Somebody scoped to one area gets that area and
     * no control at all — a dropdown with one row is a control that does
     * nothing and reads as one that is broken.
     */
    public function testAViewerWithOneSliceIsOfferedNoControl(): void
    {
        FixtureScopeSource::$scopes = [Scope::area('area-1', 'Ngorongoro')];

        $html = $this->render('@Shell/_scope_control.html.twig', [
            'scopes' => $this->scopes()->available(),
            'current' => $this->scopes()->current(),
        ]);

        self::assertSame('', trim($html));
        self::assertSame('area-1', $this->scopes()->current()?->areaUuid, 'and the page is still drawn at their area');
    }

    /** With nothing offered at all there is no scope, which is a state and not an empty organization. */
    public function testNothingOfferedIsNoScope(): void
    {
        FixtureScopeSource::$scopes = [];

        self::assertNull($this->scopes()->current());
    }

    /** The first slice is the one a page opens on. */
    public function testThePageOpensOnTheFirstSliceOffered(): void
    {
        FixtureScopeSource::$scopes = [Scope::organization(), Scope::area('area-1', 'Ngorongoro')];

        self::assertTrue($this->scopes()->current()?->isOrganization());
    }

    /** The organization and an area are told apart by what they name, never by a label. */
    public function testAScopeIsComparedByWhatItNames(): void
    {
        self::assertTrue(Scope::area('a', 'Ngorongoro')->is(Scope::area('a', 'renamed since')));
        self::assertFalse(Scope::area('a', 'Ngorongoro')->is(Scope::organization()));
        self::assertTrue(Scope::organization('all areas')->is(Scope::organization('every area')));
    }
}
