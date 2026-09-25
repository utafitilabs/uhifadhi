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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Shell\AreaShellSource;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;

/**
 * WHERE THE VIEWER IS, AND WHICH OF THE AREA'S SCREENS THEY MAY REACH.
 *
 * The shell owns that sibling screens are a strip with exactly one tab lit; it
 * owns not one tab's NAME. This is the answer to the shell's question, and it is
 * the ONE place the answer is decided — the strip above the page and the branch
 * in the sidebar both read it, so they cannot disagree.
 */
final class AreaShellSourceTest extends WebTestCase
{
    /** @param list<string> $grants */
    private function sourceAt(string $path, ?AreaOfInterest $area = null, array $grants = self::ALL_AREA_PERMISSIONS): AreaShellSource
    {
        // Boot ONCE. Re-booting drops and rebuilds the schema, which would
        // delete the very area a test just handed in.
        if (!isset($this->em)) {
            $this->boot($grants);
        }
        $area ??= $this->anArea();
        $path = str_replace('{uuid}', (string) $area->getUuidString(), $path);

        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $request = Request::create($path);
        // The route is what the strip lights from; the kernel's router resolves
        // it exactly as it would on a real request.
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');
        try {
            /** @var array<string, mixed> $matched */
            $matched = $router->match($path);
            $request->attributes->add($matched);
        } catch (\Throwable) {
            // A path this bundle does not serve: the source must cope, not throw.
        }
        $stack->push($request);

        /** @var AreaShellSource $source */
        $source = static::getContainer()->get('test_public.area.shell_source');

        return $source;
    }

    /** @return list<string> */
    private function labels(AreaShellSource $source): array
    {
        return array_map(static fn (AreaTab $t): string => $t->label, [...$source->tabs()]);
    }

    public function testAnAreasScreensAreItsTabsInTheDesignsOrder(): void
    {
        $source = $this->sourceAt('/areas/{uuid}');

        self::assertSame(['Overview', 'Zones', 'Stations'], $this->labels($source));
    }

    /**
     * A TAB IS A PLACE WHERE DATA LIVES, so what an area is SET UP WITH is not
     * one. All of it is behind the one Configure action, on the page the shell
     * owns; a strip that mixed places to look at with screens that change how
     * the area behaves would have stopped meaning anything.
     */
    public function testTheStripCarriesNoSettingsTab(): void
    {
        self::assertNotContains('Settings', $this->labels($this->sourceAt('/areas/{uuid}')));
    }

    public function testExactlyOneTabIsLitAndItIsTheScreenTheViewerIsOn(): void
    {
        $tabs = [...$this->sourceAt('/areas/{uuid}/zones')->tabs()];

        $lit = array_values(array_filter($tabs, static fn (AreaTab $t): bool => $t->current));
        self::assertCount(1, $lit);
        self::assertSame('Zones', $lit[0]->label);
    }

    /**
     * A TAB THE VIEWER MAY NOT HAVE IS ABSENT, NEVER GREYED OUT. A disabled tab
     * tells a ranger a screen exists and they are not trusted with it, which is
     * a worse product than not mentioning it.
     */
    public function testAGatedTabIsWithheldFromSomebodyWhoDoesNotHoldIt(): void
    {
        $source = $this->sourceAt('/areas/{uuid}', grants: ['areas.read']);

        self::assertNotContains('Modules', $this->labels($source));
    }

    /**
     * EVERY TAB ASKS WHAT ITS ROUTE ENFORCES. Zones and Stations are gated on
     * `zones.read` and `stations.read`, so somebody who may only reach the
     * area is offered neither — a tab drawn for them would open onto a
     * refusal.
     */
    public function testZonesAndStationsAreWithheldFromSomebodyWhoMayOnlyReachTheArea(): void
    {
        $labels = $this->labels($this->sourceAt('/areas/{uuid}', grants: ['areas.read']));

        self::assertSame(['Overview'], $labels);
    }

    /** And each is there for somebody who holds its own pair and nothing more. */
    public function testEachTabIsOfferedToWhoeverHoldsItsOwnPair(): void
    {
        self::assertSame(['Overview', 'Zones'], $this->labels($this->sourceAt('/areas/{uuid}', grants: ['areas.read', 'zones.read'])));
    }

    /**
     * THE STRIP AND THE TREE ANSWER THE SAME QUESTION DIFFERENTLY ON A CONFIGURE
     * PAGE, and both are right. The strip says nothing, because the section
     * strip stands in its place and a strip that lit none of its own tabs would
     * read as links to somewhere else. The TREE still lists the area's screens
     * and keeps the branch open, because you have not left the area.
     */
    public function testTheStripSaysNothingOnAConfigurePageButTheTreeStaysOpen(): void
    {
        $this->boot();
        $area = $this->anArea();
        $source = $this->sourceAt('/areas/{uuid}/configure', $area);

        self::assertSame([], $this->labels($source));

        $branch = $source->screensOf($area);
        self::assertSame(['Overview', 'Zones', 'Stations'], array_map(
            static fn (AreaTab $t): string => $t->label,
            $branch,
        ));
        self::assertSame([], array_values(array_filter(
            $branch,
            static fn (AreaTab $t): bool => $t->current,
        )));
    }

    /** Outside an area there is no strip at all — the register, a settings screen. */
    public function testThereIsNoStripOutsideAnArea(): void
    {
        $source = $this->sourceAt('/areas');

        self::assertSame([], $this->labels($source));
        self::assertNull($source->place());
    }

    /**
     * WE DO NOT KNOW WHERE WE ARE, SO WE DO NOT CLAIM TO. A strip that lights
     * nothing reads as links to somewhere else; the honest answer is no strip.
     */
    public function testAnUnknownScreenInsideAnAreaLightsNothingAndSoShowsNothing(): void
    {
        self::assertSame([], $this->labels($this->sourceAt('/areas/{uuid}/something-unmerged')));
    }

    /** The middle segment of the page title: "Zones — Northern Reserve — Uhifadhi". */
    public function testThePlaceIsTheAreasName(): void
    {
        $this->boot();
        $area = $this->anArea('Northern Conservation Reserve');

        self::assertSame('Northern Conservation Reserve', $this->sourceAt('/areas/{uuid}', $area)->place());
    }

    public function testAnUnknownAreaIsNotAPlace(): void
    {
        $this->boot();
        // A well-formed uuid that names nothing — a deleted area, a guessed url.
        $source = $this->sourceAt('/areas/0192f7a0-0000-7000-8000-000000000000');

        self::assertNull($source->place());
        self::assertSame([], $this->labels($source));
    }

    /** Every tab's url is real: the strip has no url-less form to grey out. */
    public function testEveryTabCarriesAUrlForTheAreaItIsIn(): void
    {
        $this->boot();
        $area = $this->anArea();
        $uuid = (string) $area->getUuidString();

        foreach ([...$this->sourceAt('/areas/{uuid}', $area)->tabs()] as $tab) {
            self::assertStringContainsString($uuid, $tab->url);
        }
    }
}
