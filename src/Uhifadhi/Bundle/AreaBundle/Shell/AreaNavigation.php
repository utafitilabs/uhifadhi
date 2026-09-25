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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneRecordController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaComposition;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Model\AreaTab;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\AreaNavChildrenInterface;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE AREAS SECTION OF THE SIDEBAR — the register, and under it every area with
 * its own screens.
 *
 * This is the LOCATION tree the shell's nav is shaped around: an area is the
 * axis the whole product is filed under, so it is the one thing in the sidebar
 * that unfolds. Each area's children are {@see AreaShellSource::screensOf()} —
 * the SAME list the tab strip is drawn from, which is why a screen cannot appear
 * in one and not the other.
 *
 * GATING IS THIS CLASS'S JOB, not the shell's — the shell holds no authorization
 * service and asks nothing about the viewer. A viewer without `areas.read` gets
 * no section at all, rather than a section whose rows all close in their face.
 *
 * ROUTE-TOLERANT. The addresses are mounted by the APPLICATION, so generating
 * one can fail, and a sidebar that took every page down because somebody
 * unmounted a route would be the worst possible way to learn it. No route, no
 * row.
 *
 * BUILT PER CALL, NEVER CACHED, and nothing is done in the constructor: the
 * shell reads its sources live on every render precisely so an area created this
 * morning is in the sidebar this morning.
 */
final readonly class AreaNavigation implements NavigationSourceInterface
{
    /** The heading the rows file under, as the design draws it. */
    public const string SECTION = NavGroup::OBSERVATORY;

    /** Above the organization rows TeamBundle contributes at 20. */
    public const int POSITION = 10;

    /** The register: this bundle's front door, and the section's own row. */
    public const string ROUTE = 'area_index';

    public function __construct(
        private RouterInterface $urls,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
        private AreaOfInterestRepository $areas,
        private AreaShellSource $screens,
        private AreaComposition $composition,
        private ModuleFrameService $frame,
        private ZoneSetService $set,
        /**
         * WHAT OTHER BUNDLES HANG UNDER THIS AREA'S SCREENS. Departments are
         * the first: the area's Departments row unfolds to them, and this
         * bundle may not name a department.
         *
         * @var iterable<AreaNavChildrenInterface>
         */
        private iterable $contributors = [],
    ) {
    }

    public function sections(): iterable
    {
        /*
         * NO TOKEN, NO QUESTION. A page can render outside any firewall — an
         * error page, a console-rendered template — and asking the authorization
         * checker there throws rather than answering false. A viewer nobody can
         * identify holds nothing, which is the same answer without the 500.
         */
        if (null === $this->tokens->getToken()) {
            return;
        }

        if (!$this->authorization->isGranted('areas.read')) {
            return;
        }

        try {
            $register = $this->urls->generate(self::ROUTE);
        } catch (RouteNotFoundException) {
            return;
        }

        $rows = [];
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            // AN AREA IS A DOOR OF ITS OWN, asked of that area: somebody
            // placed at one area holds `areas.read` there and nowhere else,
            // and a row for another area would open onto a refusal.
            if (!$this->authorization->isGranted('areas.read', $area)) {
                continue;
            }

            $url = $this->screenUrl($area->getUuidString());
            if (null === $url) {
                continue;
            }

            /*
             * THE AREA'S OWN SCREENS, UNFOLDED — and read from the same method
             * the tab strip reads, so the branch and the strip cannot disagree.
             * Another area's zones are reachable without visiting that area
             * first, which is the whole reason the tree unfolds at all.
             *
             * AND THE MODULES SCREEN UNFOLDS ONE LEVEL FURTHER, but only for the
             * area actually being viewed: its own attached modules become the
             * rows beneath it, so from a module page the sidebar shows exactly
             * where you are — Northern Reserve › Modules › the module you are in, lit.
             * Drilling every area would mean a module query per area on every
             * render for rows that are folded away anyway, so the deeper branch
             * is built only where it can be seen.
             */
            $hereArea = $this->viewerIsHere($url);
            $children = [];
            foreach ($this->screens->screensOf($area) as $tab) {
                if ($hereArea && $this->isModulesScreen($tab->url)) {
                    $children[] = $this->modulesNode($area, $tab);

                    continue;
                }

                /*
                 * AND THE ZONES SCREEN UNFOLDS TO THE ZONES THEMSELVES — the
                 * picker for the zones pages, which is why those pages have
                 * no picker column of their own and keep the band's full
                 * width. Only the area being viewed drills, for the reason
                 * the modules branch does: rows nobody can see cost a query
                 * per area on every render.
                 */
                if ($hereArea && self::isZonesScreen($tab)) {
                    $children[] = $this->zonesNode($area, $tab);

                    continue;
                }

                /*
                 * AND A SCREEN ANOTHER BUNDLE UNFOLDS — Departments, whose
                 * rungs are contributed because this bundle may not name
                 * one. Only the area being viewed drills, for the reason
                 * the two branches above do.
                 */
                $contributed = $hereArea ? $this->contributedUnder($area, $tab) : [];
                $children[] = [] === $contributed
                    ? new NavItem(label: $tab->label, url: $tab->url, current: $tab->current)
                    : self::branch($tab, $contributed);
            }

            $rows[] = new NavItem(
                label: (string) $area->getName(),
                url: $url,
                /*
                 * NO ICON ON A PLACE ROW. The design gives the section row
                 * the mark and gives an area its NAME, in the place rung's
                 * own weight; a glyph repeated down the branch reads as a
                 * second kind of thing rather than as the same thing twice.
                 */
                /*
                 * THE PLACE THE VIEWER IS IN, MARKED WHATEVER IS LIT BELOW IT.
                 * The place rung is drawn quieter than the accent, so it says
                 * "you are inside here" next to the one accented row rather than
                 * competing with it, and it says nothing from outside the area.
                 */
                current: $hereArea,
                // Unfolded only for the area being viewed: an installation with
                // eight areas would otherwise open with forty rows.
                children: $children,
            );
        }

        yield new NavSection(self::SECTION, [
            new NavItem(
                label: 'Areas',
                url: $register,
                // THE HOUSE'S MAP MARK, as the design draws it — lucide
                // `map`, the same glyph the areas register and every map
                // plate are read under.
                icon: 'shell:map',
                current: $this->viewerIsExactly($register),
                children: $rows,
            ),
        ], position: self::POSITION);
    }

    /**
     * THE "MODULES" SCREEN, WITH THE AREA'S OWN MODULES HANGING FROM IT.
     *
     * The parent yields the light to the module leaf you are actually on: on a
     * module's page the leaf is lit and "Modules" is only the open branch above
     * it, exactly as the design draws it (the tab is `par`, the module is `on`).
     * On the modules index itself no leaf is lit, so the tab keeps its own
     * current. Either way the branch is unfolded while you are inside the modules
     * space and folded — but still in the document — while you are not.
     *
     * The module row keeps `par` treatment while one of its own screens is lit,
     * exactly as the design draws it: the module is the legible ancestor and the
     * screen under it carries the accent.
     *
     * Each module row draws the shell's identity dot, jade by default. Carrying a
     * PER-MODULE hue waits on the shell publishing a NavItem tone passthrough (it
     * has the field on main but not in a release yet); when it ships, this hands
     * `$link->slug` through and a module colours its own `.mdot.<slug>`.
     */
    private function modulesNode(AreaOfInterest $area, AreaTab $tab): NavItem
    {
        $modules = [];
        foreach ($this->composition->moduleLinksFor($area) as $link) {
            /*
             * AND THE MODULE UNFOLDS TO ITS OWN DATA PLACES — the sidebar's
             * fourth level, and the SAME list the strip under the module's head
             * is drawn from. A module declares its tabs once; the branch and the
             * strip cannot disagree, because there is only one declaration.
             *
             * Only the module being viewed unfolds: drilling every module of
             * every area would build rows that are folded away anyway, and the
             * point of the level is to show where you are, not what exists.
             */
            $here = null !== $link->url && $this->viewerIsHere($link->url);

            /*
             * A MODULE'S CONFIGURE PAGE IS INSIDE THE MODULE, so the module row
             * stays the OPEN ANCESTOR with its data places under it — and takes
             * no accent, because a configure page is none of those places and
             * the accent is what says "this is the row you are on".
             */
            $configuring = $here && $this->screens->isConfiguring($this->here() ?? '/');

            $screens = [];
            foreach ($here ? $this->frame->tabsOf($link->slug, (string) $area->getUuidString()) : [] as $screen) {
                $screens[] = new NavItem(label: $screen->label, url: $screen->url, current: $screen->current);
            }

            $modules[] = new NavItem(
                label: $link->title,
                url: $link->url,
                current: $here && !self::litAnywhere($screens) && !$configuring,
                children: $screens,
            );
        }

        $litBelow = self::litAnywhere($modules);

        return new NavItem(
            label: $tab->label,
            url: $tab->url,
            current: $tab->current && !$litBelow,
            children: $modules,
        );
    }

    /**
     * THE "ZONES" SCREEN, WITH THE AREA'S OWN ZONES HANGING FROM IT.
     *
     * THE HUE IS A VALUE, NOT A CLASS, AND THAT IS THE WHOLE REASON
     * {@see NavItem::$swatch} EXISTS. A module's colour is fixed and can be
     * one rule in that module's stylesheet; a zone's comes from the palette
     * by its position in its area's set, so there is no class to declare and
     * no sheet that could know how many zones an installation will have.
     * These rows read the colour from the same walk over the same ordered set
     * the plate, the key and the cards read it from — one palette, one order,
     * four surfaces that cannot disagree.
     *
     * THE ZONE ROW CARRIES THE LIGHT AND "ZONES" IS THE BRANCH ABOVE IT, the
     * way a module's own screen lights and the module stays the legible
     * ancestor. The Zones row IS all zones, so it keeps its own light on the
     * tab itself.
     */
    private function zonesNode(AreaOfInterest $area, AreaTab $tab): NavItem
    {
        $zones = [];
        foreach ($this->set->view($area)->rows as $row) {
            $url = $this->urls->generate(ZoneRecordController::ROUTE, [
                'uuid' => (string) $area->getUuidString(),
                'zone' => $row->uuid,
            ]);

            $zones[] = new NavItem(
                label: $row->name,
                url: $url,
                current: $this->viewerIsExactly($url),
                // THE ZONE'S CATEGORY, as the shell's own dot takes one — a
                // position the register's order gave it, resolved by the
                // palette, never a colour this bundle picked.
                swatch: \sprintf('var(--cat-%d)', $row->cat),
            );
        }

        $litBelow = self::litAnywhere($zones);

        return new NavItem(
            label: $tab->label,
            url: $tab->url,
            current: $tab->current && !$litBelow,
            children: $zones,
        );
    }

    /**
     * THE RUNGS ANOTHER BUNDLE HANGS UNDER THIS SCREEN.
     *
     * Matched by URL rather than by route name, because that is what a tab
     * carries — and generating the contributor's screen route is also how a
     * contribution to a screen this installation does not serve lands
     * nowhere instead of throwing.
     *
     * @return list<NavItem>
     */
    private function contributedUnder(AreaOfInterest $area, AreaTab $tab): array
    {
        $rows = [];
        foreach ($this->contributors as $contributor) {
            $screen = $contributor->screenRoute();
            if (null === $this->urls->getRouteCollection()->get($screen)) {
                continue;
            }

            if ($this->urls->generate($screen, ['uuid' => (string) $area->getUuidString()]) !== $tab->url) {
                continue;
            }

            foreach ($contributor->childrenFor((string) $area->getUuidString(), (string) $area->getName()) as $child) {
                $rows[] = new NavItem(
                    label: $child->label,
                    url: $child->url,
                    current: $child->current,
                    /*
                     * THE HOST RESOLVES THE CATEGORY. A contributor says
                     * "the third one in my order"; what the third one looks
                     * like is the palette's, it turns over with the theme,
                     * and a module that handed over a hex would be right in
                     * one theme and wrong in the other.
                     */
                    swatch: null === $child->cat ? null : \sprintf('var(--cat-%d)', $child->cat),
                );
            }
        }

        return $rows;
    }

    /**
     * A SCREEN WITH RUNGS UNDER IT, lit the way every branch in this tree is:
     * only the deepest row carries the light, and the branch opens when
     * anything inside it is lit.
     *
     * @param list<NavItem> $children
     */
    private static function branch(AreaTab $tab, array $children): NavItem
    {
        $litBelow = self::litAnywhere($children);

        return new NavItem(
            label: $tab->label,
            url: $tab->url,
            current: $tab->current && !$litBelow,
            children: $children,
        );
    }

    /** The area's Zones screen, recognised by the route its tab points at. */
    private static function isZonesScreen(AreaTab $tab): bool
    {
        return 'Zones' === $tab->label;
    }

    /**
     * IS ANY ROW IN THIS BRANCH LIT — at any depth, not merely one rung down.
     *
     * An ancestor is an ancestor however many rungs below the light sits: a
     * module's own screen is three rungs under the area's `Modules` screen, and
     * a check that looked one level down would let every rung between them
     * accent itself as well. Only the deepest row carries the light.
     *
     * @param list<NavItem> $rows
     */
    private static function litAnywhere(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->current || self::litAnywhere($row->children)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The modules screen, recognised by the URL SPACE it owns — every area-scoped
     * module page lives under `/areas/{uuid}/modules`, the one contract they all
     * share — rather than by a route name this class would have to keep in step.
     * The same test {@see AreaShellSource::whereWeAre} lights the tab from.
     */
    private function isModulesScreen(?string $url): bool
    {
        if (null === $url) {
            return false;
        }

        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) && str_ends_with(rtrim($path, '/'), '/modules');
    }

    private function screenUrl(?string $uuid): ?string
    {
        if (null === $uuid) {
            return null;
        }

        try {
            return $this->urls->generate('area_show', ['uuid' => $uuid]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * WHETHER THE VIEWER IS ON THIS ROW'S SCREEN, or on one underneath it.
     *
     * Compared as PATHS rather than route names, because the addresses belong to
     * the application: it may mount this bundle under a prefix, and a list of
     * route names typed out here would go stale the first time a screen was
     * added.
     */
    private function viewerIsHere(string $url): bool
    {
        $here = $this->here();

        return null !== $here && ($here === $url || str_starts_with($here, rtrim($url, '/').'/'));
    }

    /** The register lights only on the register itself — never on an area beneath it. */
    private function viewerIsExactly(string $url): bool
    {
        return $this->here() === $url;
    }

    private function here(): ?string
    {
        $request = $this->requests->getCurrentRequest();

        return null === $request ? null : $request->getBaseUrl().$request->getPathInfo();
    }
}
