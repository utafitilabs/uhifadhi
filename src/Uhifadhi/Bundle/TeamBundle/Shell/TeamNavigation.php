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

namespace Uhifadhi\Bundle\TeamBundle\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentSectionController;
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamPostingsController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamRolesController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamSectionController;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentQuery;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPalette;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE ONE ROW THIS BUNDLE PUTS IN THE SIDEBAR.
 *
 * Team is the platform-wide row the shell's navigation contract is documented to expect
 * from a module: "the rare platform-wide row that belongs to nobody's area". It
 * is deliberately not the other thing a module can be — a per-area capability
 * registered with the registry through `ModuleProviderInterface` — because that
 * contract is per-area by construction (the registry's ledger is an area-by-module
 * table) and an installation's people are not an area's. A team that had to be
 * switched on per area would be a roster that existed four times.
 *
 * TWO ROWS, AND THE SECOND ONE IS NOT A SCREEN INSIDE THE FIRST. /team and
 * /team/positions are one place in the product, so the matrix lights the Team
 * row rather than adding a third; anything below that is the page's own
 * business, not the sidebar's. Departments is different in kind and the drawing
 * says so — Departments and Team sit side by side under Organization, in that
 * order, because a department is an org-wide fact the roster reads rather than
 * a corner of the roster. It has its own top-level address for the same
 * reason.
 *
 * ONE SECTION, THOUGH. Both rows are Organization, and a module contributing
 * two sections for two screens would be a module deciding the shape of somebody
 * else's sidebar.
 *
 * GATING IS THIS CLASS'S JOB, not the shell's — the shell holds no
 * authorization service and asks nothing about the viewer. So the row is
 * ABSENT, never hidden, for anybody without `team.manage`, which is the exact
 * permission the screens behind it are gated on. A row that offered a door
 * closing in somebody's face would be worse than no row.
 *
 * ROUTE-TOLERANT. The addresses are mounted by the APPLICATION (the recipe's
 * config/routes/team.yaml, which an installation may edit or delete), so
 * generating one can fail — and a sidebar that took every page down because
 * somebody unmounted a route would be the worst possible way to learn it. No
 * route, no row.
 *
 * BUILT PER CALL, NEVER CACHED, and nothing is done in the constructor: the
 * shell reads its sources live on every render precisely so a permission
 * revoked this morning is gone from the sidebar this morning.
 */
final readonly class TeamNavigation implements NavigationSourceInterface
{
    /** The heading the row files under, as the design draws it. */
    public const string SECTION = NavGroup::ORGANIZATION;

    /**
     * Between an installation's own Observatory rows and its System ones. A
     * declared position rather than a hope about container compilation order,
     * which is what the field is for.
     */
    public const int POSITION = 20;

    /** The roster: this bundle's front door, and the row's destination. */
    public const string ROUTE = 'team_index';

    /** The org chart's home — org-wide, and addressed as such. */
    public const string DEPARTMENTS_ROUTE = 'team_departments';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
        private DepartmentRepository $departments,
        private DepartmentPalette $palette,
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

        if (!$this->authorization->isGranted((string) Grant::of(TeamConcerns::DIRECTORY, Verb::Manage))) {
            return;
        }

        /*
         * ROW BY ROW, because unmounting is per-address. An installation that
         * kept the roster and dropped the departments screen must lose one row
         * rather than both — and a section with nothing left in it is not a
         * section, so an empty list yields nothing at all.
         */
        $items = array_values(array_filter([
            $this->departmentsRow(),
            $this->teamRow(),
        ]));

        if ([] === $items) {
            return;
        }

        yield new NavSection(self::SECTION, $items, position: self::POSITION);
    }

    /**
     * DEPARTMENTS, WITH THE REGISTER'S OWN PICKER UNDER IT.
     *
     * THE REGISTER HAS NO PICKER COLUMN, so this is how a department is
     * chosen: the organization's own first, then each area's under its name,
     * and an entry points at the register with that card FOCUSED — the same
     * one value the page marks, so the lit row in the tree and the marked
     * card on the page cannot disagree.
     *
     * DRILLED ONLY WHERE IT CAN BE SEEN. An installation with forty
     * departments would otherwise pay for forty rows on every page in the
     * product, folded away — the same rule the areas tree follows for its
     * modules.
     */
    /**
     * DEPARTMENTS IN THE SIDEBAR — the row, and the SECTION SUBTREE under it.
     *
     * A SECTION WEARS THE AREA IDIOM, and an area's row opens into its screens.
     * So does this one: Overview, the register with its departments hanging off
     * it, and Modules — the same three the tab strip carries, in the same
     * order, because the tree and the strip are two readings of one list and a
     * reader who learns one has learnt the other.
     *
     * IT OPENS FOR THE WHOLE SECTION, not just for the register. Standing on
     * Modules and seeing the tree collapse would say the section had one
     * screen; the row is expanded wherever you are inside it.
     */
    private function departmentsRow(): ?NavItem
    {
        $row = $this->row('Departments', self::DEPARTMENTS_ROUTE, 'shell:building-2', $this->viewerIsInDepartments());
        if (null === $row) {
            return null;
        }

        // THE TREE OPENS ON A DEPARTMENT'S OWN PAGES TOO — its record and its
        // configure page are places inside the section, and a reader standing
        // on one sees the path that led there, as they do for a person.
        if (!$this->viewerIsInDepartments()) {
            return $row;
        }

        $groups = [];
        $categories = $this->palette->indexes();
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            $area = $department->getArea();
            // KEYED BY THE AREA ITSELF. Doctrine hands back one object per row
            // per request, so object identity groups correctly even before an
            // area has been given its published identifier.
            $key = null === $area ? '' : 'area:'.($area->getUuidString() ?? (string) spl_object_id($area));
            $groups[$key] ??= [
                'label' => null === $area ? 'Org-wide' : (string) $area->getName(),
                'rows' => [],
            ];

            $uuid = (string) $department->getUuidString();
            $url = $row->url.'?'.http_build_query([DepartmentQuery::FOCUS => $uuid]).'#d-'.$uuid;

            /*
             * THE DOT WEARS THE DEPARTMENT'S OWN HUE, and it is the same hue
             * the department wears everywhere else: the row hands the shell a
             * CATEGORY TOKEN rather than a colour, because the palette turns
             * over with the theme and again on imagery. A department the
             * palette does not know keeps the shell's default.
             */
            $category = $categories[$uuid] ?? null;

            $groups[$key]['rows'][] = new NavItem(
                label: (string) $department->getName(),
                url: $url,
                current: $this->viewerIsFocusedOn($uuid),
                swatch: null === $category ? null : DepartmentPalette::token($category),
            );
        }

        // ORG-WIDE FIRST, then the areas by name — the register's own order,
        // because the tree and the page are two readings of one list.
        $org = $groups[''] ?? null;
        unset($groups['']);
        uasort($groups, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $children = [];
        foreach (array_filter([$org, ...array_values($groups)]) as $group) {
            $children[] = new NavItem(
                label: $group['label'],
                url: null,
                children: $group['rows'],
            );
        }

        // THE SCREENS ARE LIT BY ROUTE, NOT BY ADDRESS. `/departments` is a
        // PREFIX of `/departments/overview`, so the register's own row lit on
        // every screen in the section and the tree answered "where am I" with
        // two rows at once. A screen is the one whose route the request
        // matched; nothing else is.
        $onTheRegister = self::DEPARTMENTS_ROUTE === $this->routeHere();

        $register = new NavItem(
            label: 'Departments',
            url: $row->url,
            // The register IS all departments, the way the area row is the
            // area: it stays lit while none of the cards under it is.
            current: $onTheRegister && !self::litAnywhere($children),
            children: $children,
        );

        $screens = array_values(array_filter([
            $this->screen('Overview', DepartmentSectionController::OVERVIEW),
            $register,
            $this->screen('Modules', DepartmentSectionController::MODULES),
        ]));

        return new NavItem(
            label: $row->label,
            url: $row->url,
            icon: $row->icon,
            // THE SECTION'S ROW IS LIT ANYWHERE INSIDE THE SECTION, and the
            // child says which screen. Two marks on one path is not two
            // answers to "where am I": it is the path. The invariant the shell
            // enforces is one lit row among SIBLINGS, and that still holds —
            // exactly one screen is lit, and exactly one card under it.
            current: true,
            children: $screens,
            // A SECTION'S CHILDREN ARE ITS OWN SCREENS, not places inside it.
            // There is no place rung between a section and the screens its
            // own tab strip carries, and drawing one gives a reader two kinds
            // of row for one kind of thing — the register's departments are
            // the rung below these, and they are the ones that are places.
            screens: true,
        );
    }

    /**
     * TEAM IN THE SIDEBAR — the row, and the SECTION SUBTREE under it.
     *
     * A SECTION WEARS THE AREA IDIOM, and an area's row opens into its
     * screens. So does this one: the five the tab strip carries, in the same
     * order, because the tree and the strip are two readings of one list and a
     * reader who learns one has learnt the other.
     *
     * IT OPENS FOR THE WHOLE SECTION, not just for the register. Standing on
     * Roles and seeing the tree collapse would say the section had one screen.
     */
    private function teamRow(): ?NavItem
    {
        $row = $this->row('Team', self::ROUTE, 'shell:users');
        if (null === $row) {
            return null;
        }

        if (!$this->viewerIsInTeam()) {
            return $row;
        }

        $screens = array_values(array_filter([
            $this->screen('Overview', TeamSectionController::OVERVIEW),
            // A PERSON'S RECORD AND ITS CONFIGURE PAGE ARE THE PEOPLE SCREEN'S,
            // and a position's are the register's: the tree opens the path to
            // the screen a record belongs to, or the viewer stands nowhere.
            $this->screen('People', TeamController::PEOPLE, ['team_member', 'team_member_configure']),
            $this->screen('Positions', PositionController::REGISTER, ['team_position_show', 'team_position_configure']),
            $this->screen('Assignments', TeamPostingsController::POSTINGS),
            $this->screen('Roles', TeamRolesController::ROLES),
        ]));

        if ([] === $screens) {
            return $row;
        }

        return new NavItem(
            label: $row->label,
            url: $row->url,
            icon: $row->icon,
            // THE SECTION'S ROW IS LIT ANYWHERE INSIDE THE SECTION, and the
            // child says which screen. Two marks on one path is not two
            // answers to "where am I": it is the path.
            current: true,
            children: $screens,
            // A SECTION'S CHILDREN ARE ITS OWN SCREENS, not places inside it.
            // There is no place rung between a section and the screens its
            // own tab strip carries, and drawing one gives a reader two kinds
            // of row for one kind of thing — the register's departments are
            // the rung below these, and they are the ones that are places.
            screens: true,
        );
    }

    /**
     * WHETHER THE VIEWER IS ANYWHERE IN THE TEAM SECTION — read off the
     * surface marker the section's routes carry, which is the same reading the
     * shell's frame makes to draw the tab strip. A person's own page and the
     * screen that adds somebody carry no marker and are not screens of the
     * section, so the tree stays folded there, exactly as the strip is absent.
     */
    private function viewerIsInTeam(): bool
    {
        return TeamSectionTabs::SURFACE === $this->requests->getCurrentRequest()?->attributes->get(ModuleFrameService::MODULE_ROUTE_ATTRIBUTE);
    }

    /** One screen of the section, or nothing where its address is not mounted. */
    /**
     * @param list<string> $within the routes of the pages that belong to this screen — a record, its configure page
     */
    private function screen(string $label, string $route, array $within = []): ?NavItem
    {
        try {
            $url = $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }

        return new NavItem(label: $label, url: $url, current: \in_array($this->routeHere(), [$route, ...$within], true));
    }

    /**
     * WHETHER THE VIEWER IS ON A DEPARTMENTS SCREEN — and `/departments` is a
     * PREFIX OF SOMEBODY ELSE'S ADDRESS, which is the whole reason this is not
     * a path comparison.
     *
     * Performance lives at `/departments/performance` and is a place of its
     * own with its own row; matched by prefix, the Departments row lit there
     * too, and the sidebar answered "where am I" with two rows in one section.
     * A screen belongs to this section when it carries the section's surface
     * marker, or when the route the request matched is one of this bundle's
     * own department addresses — which a department's RECORD page is, though
     * it carries no marker and draws no strip.
     */
    private function viewerIsInDepartments(): bool
    {
        return $this->viewerIsInTheSection() || str_starts_with($this->routeHere(), 'team_department');
    }

    /** The route the request matched, or '' outside a request. */
    private function routeHere(): string
    {
        $route = $this->requests->getCurrentRequest()?->attributes->get('_route');

        return \is_string($route) ? $route : '';
    }

    /**
     * WHETHER THE VIEWER IS ANYWHERE IN THE DEPARTMENTS SECTION — read off the
     * surface marker the section's routes carry, which is the same reading the
     * shell's frame makes to draw the tab strip. Reading the marker rather
     * than listing route names means a screen added to the section opens the
     * tree without this class being told about it.
     */
    private function viewerIsInTheSection(): bool
    {
        $declared = $this->requests->getCurrentRequest()?->attributes->get(ModuleFrameService::MODULE_ROUTE_ATTRIBUTE);

        return DepartmentSectionTabs::SURFACE === $declared;
    }

    /** @param list<NavItem> $rows */
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
     * Whether this department is where the reader is: the register drawn
     * with its row marked, or the department's own record or configure page.
     */
    private function viewerIsFocusedOn(string $uuid): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }
        if ($uuid === $request->query->get(DepartmentQuery::FOCUS)) {
            return true;
        }

        return str_starts_with($this->routeHere(), 'team_department_') && $uuid === $request->attributes->get('uuid');
    }

    /**
     * ONE ROW, OR NOTHING WHERE ITS ADDRESS IS GONE.
     *
     * ROUTE-TOLERANT, as the class comment says: the addresses are mounted by
     * the APPLICATION, so generating one can fail, and a sidebar that took every
     * page down because somebody unmounted a route would be the worst possible
     * way to learn it.
     */
    private function row(string $label, string $route, string $icon, ?bool $current = null): ?NavItem
    {
        try {
            $url = $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }

        return new NavItem(
            label: $label,
            url: $url,
            icon: $icon,
            // A ROW THAT KNOWS ITS OWN SCREENS SAYS SO ITSELF. The path
            // comparison below is the default and is right for a row whose
            // address nobody else nests under; a row whose prefix another
            // place shares answers by route instead.
            current: $current ?? $this->viewerIsHere($url),
        );
    }

    /**
     * WHETHER THE VIEWER IS ON THIS ROW'S SCREEN, or on one underneath it.
     *
     * Compared as PATHS rather than route names, because the addresses belong to
     * the application: it may mount this bundle under a prefix, and a list of
     * route names typed out here would go stale the first time a screen was
     * added. The generated url carries the base url when the installation lives
     * in a subdirectory, so the request's is put back on before comparing.
     */
    private function viewerIsHere(string $url): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $here = $request->getBaseUrl().$request->getPathInfo();

        return $here === $url || str_starts_with($here, rtrim($url, '/').'/');
    }
}
