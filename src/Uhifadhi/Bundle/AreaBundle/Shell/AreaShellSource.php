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

namespace Uhifadhi\Bundle\AreaBundle\Shell;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\ShellBundle\Contract\AreaShellSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;

/**
 * WHICH AREA THE VIEWER IS IN, AND WHICH OF ITS SCREENS THEY MAY REACH.
 *
 * THE ONE PLACE THE ANSWER IS DECIDED. The tab list is read twice — as the strip
 * above the page, and as the branch under an area in the sidebar — and both
 * readings come from {@see self::screensOf()}, so they cannot disagree the way
 * two hand-kept copies eventually would.
 *
 * WHAT THIS DECIDES AND THE SHELL DOES NOT: which screens an area has, and which
 * of them this viewer may reach. Both are this bundle's model of an area, and
 * neither is a layout's business. A tab the viewer may not have is simply absent
 * from what this returns — never a greyed-out word, because a disabled
 * "Settings" tells a ranger that a settings screen exists and they are not
 * trusted with it.
 *
 * ROUTE-TOLERANT, and that is what lets ONE implementation cover screens this
 * bundle does not ship. The module grid is the registry's page, not this bundle's:
 * where an installation mounts it the tab appears, and where it does not the
 * strip is simply shorter. The alternative — every bundle claiming to know where
 * you are — is the disagreement the shell's alias exists to prevent.
 */
final class AreaShellSource implements AreaShellSourceInterface
{
    /**
     * The area's screens in the order the design draws them, each with the route
     * that serves it, the pair that route enforces, and whether the route asks
     * it of the area.
     *
     * EVERY SCREEN NAMES ITS PAIR, the first one included. A tab is a door,
     * and a door is drawn only for somebody who holds the pair the route
     * behind it enforces — a tab drawn on a weaker question is a click that
     * ends in a refusal.
     *
     * DEPARTMENTS IS ASKED WITHOUT THE AREA because its route is: departments
     * offer organization and department placements and never an area, so the
     * ground the strip is drawn under is not part of the question.
     *
     * SETTINGS IS NOT HERE, AND THAT IS THE RULE RATHER THAN AN OMISSION. A tab
     * is a place where DATA lives; what an area is set up with is configuration,
     * and all of it — the dashboard's composition and the area's own record —
     * is behind the one Configure action, on the page the shell owns. A strip
     * that mixed the two would be a strip that had stopped meaning anything.
     *
     * DEPARTMENTS IS HERE AND MAY NOT BE MOUNTED, which is what route-tolerance
     * is for: an installation that serves the screen gets the tab, and one that
     * does not gets a shorter strip rather than a broken page.
     *
     * @var list<array{string, string, string, bool}>
     */
    private const array SCREENS = [
        ['Overview', 'area_show', 'areas.read', true],
        ['Modules', 'area_modules', 'modules.read', true],
        ['Zones', 'area_zones', 'zones.read', true],
        ['Stations', 'area_stations', 'stations.read', true],
        ['Departments', 'area_departments', 'departments.read', false],
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly AreaOfInterestRepository $areas,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * THE SIBLING SCREENS, FOR THE STRIP — and nothing at all on a configure
     * page, because there the SECTION strip stands in the data tabs' place. A
     * strip's only job is to say which of these you are on, and a configure page
     * is none of them.
     *
     * The tree answers the same question differently and correctly: see
     * {@see screensOf()}, which keeps the branch open there.
     *
     * @return list<AreaTab>
     */
    public function tabs(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $area = $this->currentArea($request);

        if (null === $area || $this->isConfiguring($request?->getPathInfo() ?? '/')) {
            return [];
        }

        /*
         * THE STRIP IS DRAWN BY THE PLACES THEMSELVES, and this is where that
         * rule lives. The tree keeps every screen listed wherever you are
         * inside the area, because "you are here, on none of these" is an
         * answer; a strip that lit nothing would instead read as links to
         * somewhere else, so on a record or a form it says nothing at all.
         */
        $tabs = $this->screensOf($area);

        return $this->lights($tabs) ? $tabs : [];
    }

    /**
     * IS THIS REQUEST A CONFIGURE PAGE — the area's own, or one of its modules'?
     *
     * Recognised by the URL SPACE the frame owns rather than by a route-name
     * allowlist, for the reason the modules space is: the addresses belong to
     * the application, and a list of names typed out here goes stale the first
     * time one is added. `/areas/{uuid}/configure` and
     * `/areas/{uuid}/modules/{slug}/configure` are the two shapes, and anything
     * under either of them is still inside it.
     */
    public function isConfiguring(string $path): bool
    {
        return 1 === preg_match('#^/areas/[^/]++(?:/modules/[^/]++)?/configure(?:/|$)#', $path);
    }

    /**
     * The screens of ANY area, whether or not the viewer is in it — because the
     * sidebar lists every area and each one unfolds to its own screens, so
     * another area's zones are reachable without visiting the area first. Only
     * the area actually being viewed has one of them lit.
     *
     * @return list<AreaTab>
     */
    public function screensOf(AreaOfInterest $area): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');
        $uuid = ['uuid' => $area->getUuidString()];

        $current = $this->currentArea($request);
        $inThisArea = null !== $current && $current->getId() === $area->getId();

        $path = $request?->getPathInfo() ?? '/';

        /*
         * A CONFIGURE PAGE IS INSIDE THE PLACE IT CONFIGURES. The tree says
         * where you are, and on an area's configure page you are still in the
         * area — so its branch stays listed and stays open, with none of its
         * screens lit, because a configure page is not one of them. Folding the
         * branch there tells a person they have left the area, which they have
         * not; that is the difference between "we cannot say where you are" and
         * "you are here, on none of these".
         */
        $configuring = $inThisArea && $this->isConfiguring($path);

        $here = $inThisArea && !$configuring ? $this->whereWeAre(\is_string($route) ? $route : '', $path) : null;

        $tabs = [];
        foreach (self::SCREENS as [$label, $routeName, $pair, $ofTheArea]) {
            // ASKED WITH THE AREA THE STRIP IS DRAWN FOR wherever the route
            // asks it so. A tab is a door, and a door asks the question its
            // gate asks — the pair AND the ground. Asking without the area
            // would draw Modules for somebody placed at another area, who is
            // then refused on the click.
            if (!$this->authorization->isGranted($pair, $ofTheArea ? $area : null)) {
                continue;
            }
            $url = $this->url($routeName, $uuid);
            if (null === $url) {
                continue;
            }

            $tabs[] = new AreaTab(label: $label, url: $url, current: $label === $here);
        }

        /*
         * NOTHING LIT IS AN ANSWER, AND THE ROWS STAY.
         *
         * A page inside the area that is none of its screens — a configure
         * page, a station's record, a zone's — lights none of them, and that
         * is the truthful answer rather than a missing branch: you are here,
         * on none of these. The STRIP still says nothing, because
         * {@see tabs()} draws it only when one of these rows is lit; the TREE
         * keeps the branch open, which is where "where you are" is answered
         * — and it is what lets a record's own row light one rung further
         * down, under the screen it belongs to.
         *
         * Folding the branch instead told a person they had left the area,
         * which they had not.
         */
        return $tabs;
    }

    /**
     * WHICH OF THE AREA'S SCREENS THIS REQUEST IS ON, by name — or null if it is
     * on none of them.
     *
     * The modules space is recognised by the URL SPACE it owns rather than by a
     * route-name allowlist, and that is what keeps this bundle blind to the rest:
     * a module's own pages are its own bundle's routes, and this class must light
     * "Modules" for all of them without knowing one of their names. Every
     * area-scoped module page lives under `/areas/{uuid}/modules/`, which is the
     * one contract they all share.
     */
    private function whereWeAre(string $route, string $path): ?string
    {
        if (1 === preg_match('#^/areas/[^/]+/modules(?:/|$)#', $path)) {
            return 'Modules';
        }

        foreach ([
            'Zones' => 'area_zones',
            'Stations' => 'area_stations',
            'Departments' => 'area_departments',
            'Overview' => 'area_show',
        ] as $label => $name) {
            if ($route === $name) {
                return $label;
            }
        }

        return null;
    }

    /** @param list<AreaTab> $tabs */
    private function lights(array $tabs): bool
    {
        foreach ($tabs as $tab) {
            if ($tab->current) {
                return true;
            }
        }

        return false;
    }

    /**
     * The area's name, for the page title's middle segment. Null when the
     * request is not inside an area at all — the register, a sign-in page.
     */
    public function place(): ?string
    {
        return $this->currentArea($this->requestStack->getCurrentRequest())?->getName();
    }

    private function currentArea(?Request $request): ?AreaOfInterest
    {
        $uuid = $request?->attributes->get('uuid');
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->areas->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }

    /**
     * A URL for a route that may not be registered — a screen another package
     * serves, or a slice of the application that has not merged yet.
     *
     * @param array<string, string|null> $parameters
     */
    private function url(string $name, array $parameters = []): ?string
    {
        if (null === $this->router->getRouteCollection()->get($name)) {
            return null;
        }

        return $this->router->generate($name, array_filter(
            $parameters,
            static fn (?string $value): bool => null !== $value,
        ), UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
