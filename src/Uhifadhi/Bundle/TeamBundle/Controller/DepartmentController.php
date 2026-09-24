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

namespace Uhifadhi\Bundle\TeamBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalDirectionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalStateEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\MissingScopeChangeReasonException;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentQuery;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentRow;
use Uhifadhi\Bundle\TeamBundle\Performance\DepartmentsBand;
use Uhifadhi\Bundle\TeamBundle\Performance\PeriodKind;
use Uhifadhi\Bundle\TeamBundle\Performance\RequiredPeriod;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPalette;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentService;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionTabs;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Contracts\Kpi\CurrentPeriodInterface;
use Uhifadhi\Contracts\Performance\PerformanceScope;

/**
 * THE ORG CHART'S HOME — the area-aware department manager, the per-department
 * lens each row opens to, and the writes that shape them.
 *
 * A DEPARTMENT CARRIES A SCOPE NOW, and the manager is built around it. Every
 * department is either AREA-LEVEL — confined to one area, named in the scope
 * column and grouped under that area's heading — or ORG-LEVEL, spanning every
 * area. Area-level come first, grouped by their area's name; org-level after.
 * The scope is derived from the nullable area on the entity ({@see Department}),
 * never stored twice, so the manager only ever reads it.
 *
 * THE AREA IS REACHED THROUGH THE CONTRACT, NEVER AN AREA PACKAGE. The pickers
 * (create, and confine-to-area) enumerate the installation's areas by asking the
 * ORM for the entity the platform's {@see AreaInterface} resolves to — the class
 * whichever area package (AreaBundle) named in
 * `doctrine.orm.resolve_target_entities`. This bundle points at an area exactly
 * as it points at a person, and requires neither package to do it.
 *
 * A DEPARTMENT GRANTS NOTHING DIRECTLY, scope or no scope. Filing a position
 * into a department changes where it is READ; confining a department to an area
 * changes where its people's authority reaches; neither is itself a grant.
 * Capability arrives through a position's permissions, composed one screen over.
 *
 * departments.read AND departments.configure ARE AREA-SCOPED, so the pair on
 * each route here — read for the register and the record, configure for every
 * write — is the coarse gate,
 * and the controller REFINES it against the escalation ruling: an area-X admin
 * (a departments.configure holder confined to one area) may create, rename and deactivate
 * area-level departments in X, but may NOT mint an org-level department, change
 * any department's scope, or reach an org-level or other-area department — each
 * of those widens power past their own boundary. {@see AreaAuthority} computes
 * the boundary; the write methods refuse anything past it (a 403). Tiers and
 * org-level holders are unbounded and pass every guard.
 *
 * CHANGING A SCOPE IS AUDITED, both directions. Confining an org-wide department
 * to one area, or promoting an area-level one to org-wide, goes through
 * {@see Department::changeScopeTo()} — the one door that refuses a change with no
 * reason and appends a {@see \Uhifadhi\Bundle\TeamBundle\Entity\DepartmentScopeChange}. The
 * controller only supplies who and why; the entity records the transition.
 *
 * A DEPARTMENT DEACTIVATES, IT NEVER DELETES (the standing fleet rule). Winding
 * one down flips its active flag; the register draws it greyed, the pickers drop
 * it, and its scope history and filed positions are untouched. The footprint —
 * how many positions and people it touches — is stated on the act and INFORMS,
 * never guards; {@see reactivate()} brings it back.
 *
 * WHICH MODULES A DEPARTMENT LEADS WITH IS A LENS, NOT A GRANT.
 * {@see toggleModule()} attaches a module from the registry's catalogue or takes
 * it back off, and that is the whole of its effect: the attached modules lead the
 * department's overview, and the figures on its performance tab are those
 * modules' KPIs rolled up through the attachment ({@see DepartmentPerformance}).
 * No permission moves and no row becomes unreachable, which is why — unlike a
 * scope change — it takes no reason and leaves no audit line.
 *
 * WHAT IS DELIBERATELY NOT HERE YET. A department's WIDGET BOARD — the drawn
 * arrangement of its lens into movable plates — is the canonical detail page's
 * follow-up; the tabs render the composed page instead.
 */
final readonly class DepartmentController
{
    public const string CSRF_ID = 'team_department';

    /**
     * THE SURFACE MARKER — the route default that tells the shell this page is
     * inside the Departments section, so the frame draws the section's strip,
     * its header and its one Configure action. It is a route DEFAULT and not a
     * path segment, so no address changes to gain a frame.
     *
     * It names no module: nothing in the registry answers for "departments",
     * and the registry's gate only closes a declared route that ALSO names an
     * area — which none of this section's do.
     */
    public const array SURFACE = ['_uhifadhi_module' => DepartmentSectionTabs::SURFACE];

    /** The register — the section's second tab, and the list every row opens from. */
    public const string REGISTER = 'team_departments';

    /** A department's own page: inside the section, and not one of its screens. */
    public const string RECORD = 'team_department_show';

    public function __construct(
        private Environment $twig,
        private DepartmentRepository $departments,
        private PositionRepository $positions,
        private DepartmentMembership $membership,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private DepartmentService $departmentWrites,
        private DepartmentGoalRepository $goals,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private AreaAuthority $authority,
        private ModuleCatalogue $catalogue,
        private DepartmentPerformance $performance,
        private PerformanceTopics $topics,
        private DepartmentsBand $band,
        private DepartmentPalette $palette,
        /**
         * WHAT PERIOD IT IS NOW, from whoever publishes one — rather
         * than the wall clock this read used to ask, which made the
         * answer depend on the day the page happened to be opened and
         * could not be pinned at a month boundary by any test.
         *
         * OPTIONAL IN THE CONTAINER, REQUIRED AT THE SCREEN. This
         * bundle's MODEL needs no calendar; its performance pages do.
         * A kernel taking the entities and not the pages must boot —
         * see {@see RequiredPeriod}.
         */
        private ?CurrentPeriodInterface $periods,
    ) {
    }

    /**
     * THE ADDRESS IS `/departments` AND NOT `/team/departments`, which the old
     * application's URL space and the drawn sidebar agree on: Departments is a
     * sibling of Team under Organization, not a screen inside the roster.
     */
    #[Route('/departments', name: self::REGISTER, defaults: self::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function index(Request $request): Response
    {
        $departments = $this->departments->findAllOrdered();
        $query = DepartmentQuery::from($request);
        $areas = $this->areas();

        // ONE TABLE (ruled 2026-09-22). Every row's facts are computed once,
        // the dropdowns count the whole set, the filter and the sort read
        // the rows — so the counts, the table and the address never disagree.
        $rows = $this->rows($departments);
        $listed = $query->order(array_values(array_filter($rows, $query->matches(...))));

        // THE BAND READS THE PAGE'S OWN SCOPE AND WINDOW. A reader who
        // narrowed the register to one area is asking about that area,
        // and a band that answered for the organization would be the
        // page contradicting its own filter.
        $bandScope = $this->bandScope($query, $areas);
        $periodKind = PeriodKind::fromRequest($request->query->getString('period'));
        $bandPeriod = $periodKind->period(RequiredPeriod::of($this->periods)->now());

        return new Response($this->twig->render('@Team/departments/index.html.twig', [
            'rows' => $listed,
            'total' => \count($rows),
            'query' => $query,
            'areas' => $areas,
            'departments' => $departments,
            'placementOptions' => $this->placementOptions($rows, $areas),
            'moduleOptions' => $this->moduleOptions($rows),
            'goalsOptions' => [
                ['value' => DepartmentQuery::GOALS_SOME, 'label' => 'Has goals', 'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => $r->goals > 0))],
                ['value' => DepartmentQuery::GOALS_NONE, 'label' => 'None set', 'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => 0 === $r->goals))],
            ],
            'seatsOptions' => [
                ['value' => DepartmentQuery::SEATS_VACANT, 'label' => 'Vacancies', 'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => $r->vacant > 0))],
                ['value' => DepartmentQuery::SEATS_FILLED, 'label' => 'All filled', 'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => 0 === $r->vacant))],
            ],
            'columns' => [
                'name' => 'Department', 'modules' => 'Modules', 'positions' => 'Positions', 'seats' => 'Seats', 'goals' => 'Goals',
            ],
            /*
             * WHAT THE DEPARTMENTS DID, over the scope and window the
             * page is showing — the modules' own figures through the
             * performance seam, so a figure here and the same figure on
             * the Performance page cannot disagree.
             */
            'bandScope' => $bandScope->label,
            'periodKinds' => PeriodKind::labels(),
            'periodKind' => $periodKind->value,
            'periodUrls' => $this->periodUrls($query),
            'band' => $this->band->build(
                $this->topics->forScope($bandScope, $bandPeriod),
                $bandScope,
                $bandPeriod,
                \count($areas),
            ),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * THE REGISTER'S ROWS — every department's facts, computed once.
     *
     * @param list<Department> $departments
     *
     * @return list<DepartmentRow>
     */
    private function rows(array $departments): array
    {
        $cats = $this->palette->indexes();
        $holders = $this->holders();
        $rows = [];
        foreach ($departments as $department) {
            $uuid = $department->getUuidString() ?? '';
            // A DEPARTMENT'S POSITIONS ARE THE ONES ITS MEMBERS HOLD, and the
            // headcount is reached through them — a department holds nobody
            // directly, and a count that pretended otherwise would be the
            // first place this page lied about the model.
            $positions = $this->membership->positionsIn($department);
            $seats = 0;
            foreach ($positions as $position) {
                if ($position->hasUnlimitedSeats()) {
                    $seats = null;
                    break;
                }
                $seats += (int) $position->getSeatCount();
            }
            $modules = $names = $slugs = [];
            foreach ($department->getModules() as $module) {
                $names[] = (string) $module->getName();
                $slugs[] = (string) $module->getSlug();
            }

            $rows[] = new DepartmentRow(
                department: $department,
                uuid: $uuid,
                name: (string) $department->getName(),
                area: $department->getArea(),
                kind: $department->getKind()?->getName(),
                modules: $names,
                slugs: $slugs,
                positions: \count($positions),
                filled: $this->users->countActiveHoldingAnyPosition($positions),
                seats: [] === $positions ? 0 : $seats,
                goals: \count($this->goals->findForDepartment($department)),
                active: $department->isActive(),
                category: $cats[$uuid] ?? null,
                positionRows: array_map(static fn (Position $position): array => [
                    'uuid' => (string) $position->getUuidString(),
                    'name' => (string) $position->getName(),
                    'filled' => $holders[(string) $position->getUuidString()] ?? 0,
                    'seats' => $position->getSeatCount(),
                ], $positions),
            );
        }

        return $rows;
    }

    /**
     * WHERE A DEPARTMENT IS PLACED — org-wide, or one of the areas — with
     * how many each answer would leave, counted against the whole register.
     *
     * @param list<DepartmentRow> $rows
     * @param list<AreaInterface> $areas
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function placementOptions(array $rows, array $areas): array
    {
        $options = [[
            'value' => DepartmentQuery::ORG,
            'label' => 'Org-wide',
            'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => null === $r->area)),
        ]];
        foreach ($areas as $area) {
            $uuid = (string) $area->getUuidString();
            $options[] = [
                'value' => $uuid,
                'label' => (string) $area->getName(),
                'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => $r->placementKey() === $uuid)),
            ];
        }

        return $options;
    }

    /**
     * THE MODULES ANY DEPARTMENT ATTACHES, and "none" for the ones that
     * attach nothing — a nought is drawn, because an option that vanished
     * when it emptied could not be told from one that never existed.
     *
     * @param list<DepartmentRow> $rows
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function moduleOptions(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row->slugs as $i => $slug) {
                $seen[$slug] ??= ['value' => $slug, 'label' => $row->modules[$i], 'count' => 0];
                ++$seen[$slug]['count'];
            }
        }
        ksort($seen);
        $options = array_values($seen);
        $options[] = [
            'value' => DepartmentQuery::NO_MODULE,
            'label' => 'None attached',
            'count' => \count(array_filter($rows, static fn (DepartmentRow $r): bool => [] === $r->slugs)),
        ];

        return $options;
    }

    /**
     * WHERE EACH SEGMENT OF THE PERIOD GROUP GOES, carrying the
     * register's own filter with it: changing the window is not
     * changing which departments are listed.
     *
     * @return array<string, string>
     */
    private function periodUrls(DepartmentQuery $query): array
    {
        $urls = [];
        foreach (PeriodKind::cases() as $kind) {
            $urls[$kind->value] = $this->router->generate(self::REGISTER, $query->with('period', $kind->value));
        }

        return $urls;
    }

    /**
     * WHOSE FIGURES THE BAND SHOWS: the organization's, or the one area
     * the register has been narrowed to.
     *
     * @param list<AreaInterface> $areas
     */
    private function bandScope(DepartmentQuery $query, array $areas): PerformanceScope
    {
        foreach ($areas as $area) {
            if (null !== $query->placement && $query->placement === $area->getUuidString()) {
                return PerformanceScope::area($query->placement, (string) $area->getName());
            }
        }

        return PerformanceScope::organization();
    }

    /**
     * THE LENS — a department's own page, area-aware and openable from every row.
     *
     * It carries the department's real facts: its scope, its area when it has
     * one, its code, the positions filed under it, and the modules it attaches —
     * which lead its overview and are where every figure on its performance tab
     * comes from. A department with nothing attached leads with nothing, and the
     * page says so rather than inventing a card.
     *
     * The modules OFFERED are the registry's catalogue: the rows in the table
     * that a registered provider still answers for. A module nobody installed is
     * not offered, because attaching it would point at code this deployment does
     * not have.
     */
    #[Route('/departments/{uuid}', name: self::RECORD, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function show(string $uuid): Response
    {
        $department = $this->department($uuid);
        $positions = $owned = $this->membership->positionsIn($department);

        // THE TWO HALVES OF THE ATTACHMENT CONTROL, split here rather than in
        // Twig: the modules this department leads with, and the ones it could.
        $attached = $department->getModules()->toArray();
        $detached = [];
        foreach ($this->catalogue->all() as $module) {
            if (!$department->hasModule($module)) {
                $detached[] = $module;
            }
        }

        return new Response($this->twig->render('@Team/departments/show.html.twig', [
            'department' => $department,
            'mark' => $this->mark((string) $department->getName()),
            'positions' => $positions,
            'headcount' => $this->users->countActiveHoldingAnyPosition($owned),
            'holders' => $this->holders(),
            'attached' => $attached,
            'detached' => $detached,
            'kpis' => $this->performance->kpisFor($department),
            // WHAT IT SAID IT WOULD DO, and how each one reads today. The
            // state is derived from the figure that answers it, so a page
            // and a rail can never disagree about whether a goal was met.
            'goals' => $this->goals->findForDepartment($department),
            'goalStates' => $this->goalStates($department),
            // THE DEFAULT WINDOW IS THIS MONTH, resolved here rather than in
            // the template: a form's default is the server's answer, and a
            // date printed in a template is a date in the server's zone.
            'goalWindow' => [
                'opens' => new \DateTimeImmutable('first day of this month')->format('Y-m-d'),
                'closes' => new \DateTimeImmutable('last day of this month')->format('Y-m-d'),
            ],
            // HOW MANY DEPARTMENTS EACH MODULE SERVES, so a card can say "shared"
            // truthfully. One query for the page; a per-card count is how two rows
            // come to disagree about one module.
            'sharing' => $this->departments->countByModule(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    public const string CONFIGURE = 'team_department_configure';

    /**
     * WHERE A DEPARTMENT IS CHANGED — its name, its scope, whether it is
     * active. The register is one table and a table row carries one door,
     * so the three operations that used to sit in a card's footer live
     * here, the same shape a person and a position already have: a record,
     * and a configure page behind it.
     */
    #[Route('/departments/{uuid}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function configure(string $uuid): Response
    {
        $department = $this->department($uuid);
        $positions = $this->membership->positionsIn($department);

        return new Response($this->twig->render('@Team/departments/record_configure.html.twig', [
            'department' => $department,
            'mark' => $this->mark((string) $department->getName()),
            'positions' => $positions,
            'headcount' => $this->users->countActiveHoldingAnyPosition($positions),
            'areas' => $this->areas(),
            'returnTo' => $this->router->generate(self::CONFIGURE, ['uuid' => $uuid]),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * ATTACH OR DETACH A MODULE — the lens control, one door for both directions
     * because it is one decision seen from two sides.
     *
     * IT GRANTS NOTHING AND HIDES NOTHING. Attaching makes the module lead this
     * department's view and puts its KPIs on the performance tab; it moves no
     * permission and fences off no row, so a module two departments attach is one
     * module listed first for both. That is why there is no reason field and no
     * audit line here, unlike {@see changeScope()}: nothing about who may do what
     * has moved.
     *
     * §5.6: it is department management, so an area administrator reaches the
     * departments their authority reaches and no others.
     */
    #[Route('/departments/{uuid}/modules/{slug}/toggle', name: 'team_department_module_toggle', requirements: ['uuid' => Requirement::UUID, 'slug' => '[a-z0-9-]+'], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function toggleModule(Request $request, string $uuid, string $slug): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        // A slug this deployment does not have is not a module to attach: either
        // nothing ever declared it, or its bundle is gone and the tile would
        // point at code nobody has.
        $module = $this->catalogue->find($slug)
            ?? throw new NotFoundHttpException('No such module on this installation.');

        if ($department->hasModule($module)) {
            $this->departmentWrites->detach($department, $module);

            return $this->toLens($request, $department, \sprintf(
                '%s no longer leads for %s. The module and every row in it are untouched — this was emphasis, not access.',
                (string) $module->getName(),
                (string) $department->getName(),
            ));
        }

        $this->departmentWrites->attach($department, $module);

        return $this->toLens($request, $department, \sprintf(
            '%s leads for %s now, and its figures roll up on this department’s performance tab. It grants nobody anything and hides nothing — another department attaching it reads the same rows.',
            (string) $module->getName(),
            (string) $department->getName(),
        ));
    }

    /**
     * CREATE — the scope question is asked first, area-first by default because
     * it is the narrow, correctable choice ({@see Department::changeScopeTo()}
     * widens without warning and confines with a ripple). An org-wide department
     * takes only a name; an area-level one takes the area the picker enumerated.
     */
    #[Route('/departments', name: 'team_department_create', methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A department needs a name.', 'error');
        }

        // AREA-LEVEL unless org-wide was chosen. An empty or missing scope is
        // treated as area-first only when an area is actually named; a scope of
        // "area" with no area is a mis-post and falls back to org rather than
        // erroring, because a department with a name is worth keeping.
        $area = null;
        if ('org' !== $request->request->get('scope')) {
            $areaUuid = trim((string) $request->request->get('area'));
            if ('' !== $areaUuid) {
                $area = $this->areaByUuid($areaUuid);
                if (null === $area) {
                    return $this->back($request, 'That area is not one this installation has.', 'error');
                }
            }
        }

        // §5.6: minting an ORG-LEVEL department is unbounded; an AREA-LEVEL one
        // must land in an area the administrator's authority reaches. An area-X
        // admin creating an org department, or a department in another area, is
        // escalation.
        $this->assertMayCreate($area);

        try {
            $department = $this->departmentWrites->create($name, $area);
        } catch (NameNotUniqueException) {
            // UNIQUE WITHIN ITS SCOPE. Two org-wide departments of one name, or
            // two in the same area, would be the same department entered twice. A
            // name may still repeat FROM ONE AREA TO ANOTHER — two areas may each
            // run an Anti-Poaching unit — the way a position name repeats across
            // departments.
            return $this->back($request, null === $area
                ? \sprintf('There is already an organization-wide department called “%s”. A name may repeat from one area to another, but the organization-wide ones each stand alone.', $name)
                : \sprintf('%s already has a department called “%s”. Another area may share the name, but not this one twice.', (string) $area->getName(), $name),
                'error');
        }

        return $this->back($request, null === $department->getArea()
            ? \sprintf('“%s” exists, organization-wide. It owns no positions yet, and it grants nobody anything — a department is where work is filed, never what permits it.', $name)
            : \sprintf('“%s” exists, confined to %s. It owns no positions yet, and it grants nobody anything.', $name, (string) $department->getArea()->getName()));
    }

    #[Route('/departments/{uuid}/rename', name: 'team_department_rename', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function rename(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A department needs a name.', 'error');
        }

        $was = (string) $department->getName();

        try {
            $this->departmentWrites->rename($department, $name);
        } catch (NameNotUniqueException) {
            return $this->back($request, \sprintf('There is already a department called “%s” in that scope.', $name), 'error');
        }

        return $this->back($request, \sprintf('“%s” is now “%s”. Every position it owns is named after it, so they all read differently now.', $was, $name));
    }

    /**
     * CHANGE A DEPARTMENT'S SCOPE — confine to an area, or promote to org-wide,
     * with a reason recorded to the audit trail on the transition.
     *
     * An area is confined-to; its absence is a promotion. Either way the reason
     * is required — {@see Department::changeScopeTo()} refuses a blank one before
     * the area moves — and the transition is appended as a
     * {@see \Uhifadhi\Bundle\TeamBundle\Entity\DepartmentScopeChange}. Confining an org-wide
     * department re-scopes everything under it; promoting only widens. The actor
     * is the signed-in administrator, resolved from the token.
     */
    #[Route('/departments/{uuid}/scope', name: 'team_department_scope', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function changeScope(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);

        // §5.6: changing a scope is an UNBOUNDED act — promoting to org grants
        // org-wide authority, confining or moving re-scopes people. An area-X
        // admin may not; only a tier or an org-level holder may.
        if (!$this->authority->isUnbounded()) {
            throw new AccessDeniedException('Changing a department’s scope is an organization-wide act; an area administrator may not.');
        }

        $reason = trim((string) $request->request->get('reason'));
        $areaUuid = trim((string) $request->request->get('area'));

        $newArea = null;
        if ('' !== $areaUuid) {
            $newArea = $this->areaByUuid($areaUuid);
            if (null === $newArea) {
                return $this->back($request, 'That area is not one this installation has.', 'error');
            }
        }

        $wasOrg = $department->isOrgLevel();
        // Captured BEFORE the change, for the §5.7 privilege notice: a scope
        // change now moves the authority of everyone filed under the department.
        $people = $this->footprint($department)['people'];

        try {
            $this->departmentWrites->changeScope($department, $newArea, $this->signedIn(), $reason);
        } catch (MissingScopeChangeReasonException) {
            return $this->back($request, 'A scope change needs a reason — it is recorded to the audit trail.', 'error');
        } catch (NameNotUniqueException) {
            // Confining into an area that already runs a department of this name
            // would collapse two into one. Refuse, and name the clash.
            return $this->back($request, \sprintf(
                '%s already has a department called “%s”. Confine it under a different name, or rename one first.',
                $newArea?->getName() ?? 'That area',
                (string) $department->getName(),
            ), 'error');
        }

        // §5.7: scope is authority now, so the notice INFORMS what the change did
        // to it — a promotion is a privilege GAIN, a demotion is authority LOST
        // outside the new area. It informs; it never guards (the change is done).
        if (null === $newArea) {
            return $this->back($request, \sprintf(
                '“%s” is org-wide now — it spans every area. %s The change is recorded with your reason.',
                (string) $department->getName(),
                0 === $people
                    ? 'It has no people yet, so nothing gained authority.'
                    : \sprintf('Its %d %s now hold their permissions in EVERY area — a real widening of authority.',
                        $people, 1 === $people ? 'person' : 'people'),
            ));
        }

        return $this->back($request, \sprintf(
            '“%s” is confined to %s now%s. %s The change is recorded with your reason.',
            (string) $department->getName(),
            (string) $newArea->getName(),
            $wasOrg ? ', and everything filed under it moves with it' : '',
            0 === $people
                ? 'It has no people yet, so no authority moved.'
                : \sprintf('Its %d %s now hold their permissions in %s ONLY — authority they had elsewhere is lost.',
                    $people, 1 === $people ? 'person' : 'people', (string) $newArea->getName()),
        ));
    }

    /**
     * DEACTIVATE — wind a department down without deleting it (the standing
     * fleet rule). The footprint ("N positions hold this") is stated in the
     * flash that confirms the act; it INFORMS, never guards, so the write goes
     * through regardless of how much is filed under the department. Its scope
     * history stays on the ledger and its positions keep their filing — a
     * deactivated department is hidden from the pickers, greyed in the register,
     * and one click from coming back.
     */
    /**
     * DECLARE A GOAL, ON THE DEPARTMENT'S OWN PAGE.
     *
     * The target only means anything beside the figures it is judged by, so
     * this is where it is declared — never on a screen of its own, which
     * would be a second place to look for one.
     *
     * NOTHING FOLLOWS FROM IT. A goal is a commitment and a measurement: no
     * permission, no filing and no access, which is why the gate is the
     * same department-management one every other write here carries.
     */
    #[Route('/departments/{uuid}/goals', name: 'team_department_goal_declare', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function declareGoal(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        $direction = GoalDirectionEnum::tryFrom((string) $request->request->get('direction'))
            ?? GoalDirectionEnum::AtLeast;

        $owner = null;
        $ownerUuid = trim((string) $request->request->get('owner'));
        if ('' !== $ownerUuid) {
            $owner = $this->positions->findOneBy(['uuid' => $ownerUuid]);
        }

        try {
            $goal = $this->departmentWrites->declareGoal(
                $department,
                (string) $request->request->get('statement'),
                (float) $request->request->get('target'),
                (string) $request->request->get('unit'),
                $direction,
                self::dayOf($request, 'opensAt'),
                self::dayOf($request, 'closesAt'),
                trim((string) $request->request->get('kpiRef')),
                $owner,
            );
        } catch (\InvalidArgumentException $refused) {
            return $this->toLens($request, $department, $refused->getMessage(), 'error');
        }

        return $this->toLens($request, $department, \sprintf(
            '“%s” is declared, %s %s%s by %s. It grants nobody anything — it is what this department said it would do, and the figure that answers it is whatever the modules publish.',
            $goal->getStatement(),
            $goal->getDirection()->label(),
            self::plainly($goal->getTarget()),
            '' === $goal->getUnit() ? '' : ' '.$goal->getUnit(),
            $goal->getClosesAt()?->format('j M Y') ?? 'the close of the period',
        ));
    }

    /**
     * WITHDRAW ONE, ENTIRELY. A goal is not an audit record — it is a
     * commitment somebody made and may unmake — and one left greyed on the
     * page would be read as a miss.
     */
    #[Route('/departments/{uuid}/goals/{goal}/withdraw', name: 'team_department_goal_withdraw', requirements: ['uuid' => Requirement::UUID, 'goal' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function withdrawGoal(Request $request, string $uuid, string $goal): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        $declared = $this->goals->findOneBy(['uuid' => $goal]);
        if (null === $declared || $declared->getDepartment()?->getId() !== $department->getId()) {
            throw new NotFoundHttpException('That goal is not one this department declared.');
        }

        $statement = $declared->getStatement();
        $this->departmentWrites->withdrawGoal($declared);

        return $this->toLens($request, $department, \sprintf('“%s” is withdrawn. Nothing else changed.', $statement));
    }

    /** A day off the form, at its own start, or today when nothing was sent. */
    private static function dayOf(Request $request, string $field): \DateTimeImmutable
    {
        $day = trim((string) $request->request->get($field));

        try {
            return new \DateTimeImmutable('' === $day ? 'today' : $day)->setTime(0, 0);
        } catch (\Exception) {
            return new \DateTimeImmutable('today')->setTime(0, 0);
        }
    }

    /** A target without its trailing noughts: 60 rather than 60.00. */
    private static function plainly(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    #[Route('/departments/{uuid}/deactivate', name: 'team_department_deactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function deactivate(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        $footprint = $this->footprint($department);
        $this->departmentWrites->deactivate($department);

        return $this->back($request, \sprintf(
            '“%s” is deactivated — hidden from the pickers and greyed in the register, %s. Nothing is deleted: its history stays, and it is one click from coming back.',
            (string) $department->getName(),
            0 === $footprint['positions']
                ? 'and nothing was filed under it'
                : \sprintf('%d position%s (%d %s) stay filed under it and keep what they grant',
                    $footprint['positions'], 1 === $footprint['positions'] ? '' : 's',
                    $footprint['people'], 1 === $footprint['people'] ? 'person' : 'people'),
        ));
    }

    /**
     * REACTIVATE — bring a wound-down department back. No footprint: widening
     * availability strands nothing, the same way promoting a scope does not.
     */
    #[Route('/departments/{uuid}/reactivate', name: 'team_department_reactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function reactivate(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($department);

        $this->departmentWrites->reactivate($department);

        return $this->back($request, \sprintf('“%s” is active again — back in the pickers and the register.', (string) $department->getName()));
    }

    /**
     * THE INSTALLATION'S AREAS, for the create picker and the confine-to picker —
     * enumerated through the contract, never an area package.
     *
     * The concrete class is whatever the platform's {@see AreaInterface} was
     * resolved to (AreaBundle, or the host's own), read off the
     * association this bundle already declares on {@see Department::$area}. So the
     * picker knows the installation's areas without this bundle ever naming the
     * class that holds them.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }

    private function areaByUuid(string $uuid): ?AreaInterface
    {
        foreach ($this->areas() as $area) {
            if ($area->getUuidString() === $uuid) {
                return $area;
            }
        }

        return null;
    }

    /**
     * HOW MANY ACTIVE PEOPLE EACH POSITION REACHES — the number beside a row.
     *
     * @return array<string, int>
     */
    private function holders(): array
    {
        $holders = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $holders[$position->getUuidString() ?? ''] = $this->users->countActiveHoldingAnyPosition([$position]);
        }

        return $holders;
    }

    /**
     * WHAT DEACTIVATING THIS DEPARTMENT TOUCHES — the positions its members
     * hold and the active people who hold them. Informs the confirming flash; it is
     * never a gate.
     *
     * @return array{positions: int, people: int}
     */
    private function footprint(Department $department): array
    {
        $positions = $this->membership->positionsIn($department);

        return [
            'positions' => \count($positions),
            'people' => $this->users->countActiveHoldingAnyPosition($positions),
        ];
    }

    /**
     * HOW EACH OF A DEPARTMENT'S GOALS READS TODAY, keyed by its uuid.
     *
     * THE FIGURE COMES FROM WHATEVER THE MODULES PUBLISH, matched to the
     * goal by the key it named. No module publishing it is not a miss and
     * not a nought: the goal reads "no figure yet", which is the state the
     * design draws as a solid hollow ring.
     *
     * @return array<string, GoalStateEnum>
     */
    private function goalStates(Department $department): array
    {
        $published = [];
        foreach ($this->performance->kpisFor($department) as $kpi) {
            $published[$kpi->moduleSlug.'.'.$kpi->key] = $kpi->value;
        }

        $now = RequiredPeriod::of($this->periods)->now();
        $states = [];
        foreach ($this->goals->findForDepartment($department) as $goal) {
            $ref = $goal->getKpiRef();
            $states[(string) $goal->getUuidString()] = $goal->stateFor(
                null === $ref ? null : ($published[$ref] ?? null),
                $now,
            );
        }

        return $states;
    }

    private function mark(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $words) {
            return '—';
        }
        if (\count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 2));
    }

    private function department(string $uuid): Department
    {
        return (Uuid::isValid($uuid) ? $this->departments->findOneByUuid(Uuid::fromString($uuid)) : null)
            ?? throw new NotFoundHttpException('No such department on this installation.');
    }

    /**
     * REFUSE AN ESCALATION on an existing department (§5.6). A tier or org-level
     * administrator is unbounded and may manage anything; a bounded (area-X)
     * administrator may manage only AREA-LEVEL departments in their own area —
     * never an org-level one, never another area's.
     */
    private function assertMayManage(Department $department): void
    {
        if ($this->authority->isUnbounded()) {
            return;
        }

        if ($department->isAreaLevel() && $this->authority->covers($department->getArea())) {
            return;
        }

        throw new AccessDeniedException('An area administrator may manage only the area-level departments in their own area.');
    }

    /**
     * REFUSE AN ESCALATION at creation (§5.6). Minting an org-level department
     * (null area) is unbounded; an area-level one must land in an area the
     * administrator's authority reaches.
     */
    private function assertMayCreate(?AreaInterface $area): void
    {
        if ($this->authority->isUnbounded()) {
            return;
        }

        if (null !== $area && $this->authority->covers($area)) {
            return;
        }

        throw new AccessDeniedException('An area administrator may create area-level departments in their own area only — not an organization-wide one, and not in another area.');
    }

    private function signedIn(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function back(Request $request, string $message, string $kind = 'success'): RedirectResponse
    {
        $this->flash($request, $message, $kind);

        return new RedirectResponse($this->returnTo($request) ?? $this->router->generate('team_departments'));
    }

    /**
     * WHERE A WRITE GOES BACK TO, when the door was not the register.
     *
     * The same write is offered on the organization's register and in an
     * area's configure section, and a person is returned to the page they
     * pressed the button on — otherwise editing one area's department throws
     * the reader out to the organization.
     *
     * ONLY A PATH ON THIS SITE. The value is posted, so it is the visitor's;
     * anything but a single leading slash — an absolute URL, a
     * protocol-relative `//host`, a backslash — is refused and the register
     * answers instead. There is no open redirect to be had here.
     */
    private function returnTo(Request $request): ?string
    {
        $to = trim((string) $request->request->get('_return'));

        if ('' === $to || !str_starts_with($to, '/') || str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
            return null;
        }

        return $to;
    }

    /**
     * BACK TO THE DEPARTMENT, not to the register — a write made ON the lens
     * returns to the lens, so the result of pressing a chip is the page that
     * changed rather than the list it was reached from.
     */
    private function toLens(Request $request, Department $department, string $message, string $kind = 'success'): RedirectResponse
    {
        $this->flash($request, $message, $kind);

        return new RedirectResponse($this->returnTo($request) ?? $this->router->generate('team_department_show', [
            'uuid' => (string) $department->getUuidString(),
        ]));
    }

    private function flash(Request $request, string $message, string $kind): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }
    }
}
