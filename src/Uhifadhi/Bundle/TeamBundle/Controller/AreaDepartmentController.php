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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentMark;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentQuery;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPalette;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * ONE AREA'S DEPARTMENTS — the tab that reads them, and the section that
 * changes them.
 *
 * WHY THESE ROUTES ARE TEAM'S. A department already knows which area it
 * belongs to: `Department::$area` is the published {@see AreaInterface},
 * resolved by the installation. So the bundle that owns departments can
 * answer "the ones that read this area" without the area bundle learning
 * what a department is, and the area's tab strip picks the route up the way
 * it picks up any other — by name, tolerantly, so an installation without
 * this bundle simply has a shorter strip. No new seam buys anything here.
 *
 * THE TAB READS AND THE SECTION WRITES, which is the same split every area
 * surface makes. The tab's cards are the organization register's cards —
 * the same partial — because two registers of one thing are two registers
 * that drift.
 *
 * THE AREA'S OWN COME FIRST, then the org-wide ones it inherits. On the
 * organization's register the order is the other way about, and both are
 * read outwards from where the reader is standing: there, from the
 * organization into its areas; here, from this place into what it shares.
 */
final readonly class AreaDepartmentController
{
    public const string TAB = 'area_departments';
    public const string SECTION = 'area_departments_configure';

    public function __construct(
        private Environment $twig,
        private DepartmentRepository $departments,
        private PositionRepository $positions,
        private DepartmentMembership $membership,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private DepartmentPerformance $performance,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
        private ModuleCatalogue $catalogue,
        private DepartmentPalette $palette,
    ) {
    }

    #[Route('/areas/{uuid}/departments', name: self::TAB, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('departments.read')]
    public function tab(Request $request, string $uuid): Response
    {
        $area = $this->areaByUuid($uuid);
        $query = DepartmentQuery::from($request);

        $own = $query->matching($this->departments->findForArea($area));
        $inherited = $query->matching($this->departments->findOrgLevelOrdered());
        $reading = $this->reading([...$own, ...$inherited]);

        return new Response($this->twig->render('@Team/departments/area_tab.html.twig', [
            'area' => $area,
            'query' => $query,
            'groups' => [
                [
                    'key' => 'own',
                    'label' => \sprintf('%s’s own', $area->getName()),
                    'note' => 'Area-level · this area only',
                    'departments' => $own,
                ],
                [
                    'key' => 'org',
                    'label' => 'Org-wide · read this area too',
                    'note' => 'Belong to the organization · every area reads them',
                    'departments' => $inherited,
                ],
            ],
            'homeHref' => $this->tolerant('area_index'),
            'areaHref' => $this->tolerant('area_show', ['uuid' => $area->getUuidString()]),
            /* Which card is open is a place, so it is a query a link carries. */
            'openDepartment' => '' === trim($request->query->getString('open')) ? null : trim($request->query->getString('open')),
            'ownCount' => \count($this->departments->findForArea($area)),
            'orgCount' => \count($this->departments->findOrgLevelOrdered()),
            ...$reading,
            // THE BAND'S SECOND FACT IS ABOUT THIS AREA'S OWN DEPARTMENTS.
            // An org-wide department's positions belong to the
            // organization, and counting them here would make every area
            // report the same number as its own.
            ...$this->staffing($own),
        ]));
    }

    /**
     * THE DEPARTMENTS SECTION OF THE AREA'S CONFIGURE PAGE — the only place
     * this area's departments are added and edited.
     *
     * READING IS GATED ON `departments.read`, the pair the departments register
     * itself reads under — this is a departments page that happens to be reached
     * through an area, and it must not gate on a concern the ground package
     * declares, because that concern is absent when the ground package is; each WRITE
     * is the register's own route and carries the register's own gate, so
     * nothing is permitted here that is not permitted there.
     */
    #[Route('/areas/{uuid}/configure/departments', name: self::SECTION, requirements: ['uuid' => Requirement::UUID], methods: ['GET'], priority: 1)]
    #[IsGranted('departments.read')]
    public function configure(string $uuid): Response
    {
        $area = $this->areaByUuid($uuid);
        $own = $this->departments->findForArea($area);
        $inherited = $this->departments->findOrgLevelOrdered();

        return new Response($this->twig->render('@Team/departments/area_configure.html.twig', [
            'area' => $area,
            'own' => $own,
            'inherited' => $inherited,
            'attachable' => $this->catalogue->all(),
            'homeHref' => $this->tolerant('area_index'),
            'areaHref' => $this->tolerant('area_show', ['uuid' => $area->getUuidString()]),
            'csrfToken' => $this->csrf->getToken(DepartmentController::CSRF_ID)->getValue(),
            ...$this->reading([...$own, ...$inherited]),
        ]));
    }

    /**
     * EVERYTHING THE CARDS READ, for one page — the positions a department
     * sees, who holds them and what the modules publish.
     *
     * A DEPARTMENT'S POSITIONS ARE THE ONES ITS MEMBERS HOLD. A position
     * belongs to nobody, so there is nothing filed under a department and the
     * placement answers instead.
     *
     * @param list<Department> $departments
     *
     * @return array{owned: array<string, list<\Uhifadhi\Bundle\TeamBundle\Entity\Position>>, headcount: array<string, int>, holders: array<string, int>, figures: array<string, list<\Uhifadhi\Contracts\Kpi\DepartmentKpi>>, marks: array<string, string>, cats: array<string, int>}
     */
    private function reading(array $departments): array
    {
        $owned = [];
        foreach ($departments as $department) {
            $owned[$department->getUuidString() ?? ''] = $this->membership->positionsIn($department);
        }

        $holders = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $holders[$position->getUuidString() ?? ''] = $this->users->countActiveHoldingAnyPosition([$position]);
        }

        $headcount = [];
        $figures = [];
        $marks = [];
        foreach ($departments as $department) {
            $key = $department->getUuidString() ?? '';
            $mine = $owned[$key] ?? [];
            $headcount[$key] = $this->users->countActiveHoldingAnyPosition($mine);
            $figures[$key] = $this->performance->kpisFor($department);
            $marks[$key] = DepartmentMark::of((string) $department->getName());
        }

        return [
            'owned' => $owned,
            'headcount' => $headcount,
            'holders' => $holders,
            'figures' => $figures,
            'marks' => $marks,
            // A DEPARTMENT NAMES A CATEGORY AND NEVER A COLOUR, and it is the
            // same category on every surface that marks one.
            'cats' => $this->palette->indexes(),
        ];
    }

    /**
     * HOW MANY PEOPLE WORK IN THESE DEPARTMENTS, AND HOW MANY POSITIONS THEY
     * HOLD BETWEEN THEM.
     *
     * IT NO LONGER READS "M OF N FILLED", and that is the ruling rather than
     * a simplification. A department's positions are DERIVED from the people
     * placed in it — a position is on the list because somebody there holds
     * it — so every derived position is held by construction and "M of N"
     * could only ever print "N of N". A figure that cannot vary is a figure
     * that says nothing, and a reader who has learnt it means something
     * elsewhere would read it as meaning something here.
     *
     * SO THE TWO NUMBERS ARE TWO FACTS: how many people, and how many
     * distinct positions. They are deliberately not a ratio — a person holds
     * one position and a position is held by many, so neither divides into
     * the other.
     *
     * EACH IS COUNTED ONCE ACROSS THE WHOLE SET. Somebody placed in two of
     * these departments is one person, and a position held in two of them is
     * one position; summing per department and adding the totals would count
     * both twice, which is the bug the previous reckoning of this cell
     * existed to avoid.
     *
     * @param list<Department> $departments
     *
     * @return array{people: int, positionCount: int}
     */
    private function staffing(array $departments): array
    {
        $people = [];
        $positions = [];

        foreach ($departments as $department) {
            foreach ($this->membership->membersOf($department) as $member) {
                $people[(int) $member->getId()] = true;

                $position = $member->getPosition();
                if (null !== $position) {
                    $positions[(int) $position->getId()] = true;
                }
            }
        }

        return ['people' => \count($people), 'positionCount' => \count($positions)];
    }

    /**
     * A LINK ONLY IF THE INSTALLATION HAS THAT ROUTE. Areas come from
     * another bundle, which an installation may not have mounted; a crumb
     * is not worth a 500, so it degrades to plain text.
     *
     * @param array<string, string|null> $parameters
     */
    private function tolerant(string $name, array $parameters = []): ?string
    {
        if (null === $this->router->getRouteCollection()->get($name)) {
            return null;
        }

        return $this->router->generate($name, array_filter($parameters, static fn (?string $v): bool => null !== $v));
    }

    /**
     * THE AREA, THROUGH THE PUBLISHED CONTRACT. This bundle never names the
     * class that holds areas: it reads the association it already declares
     * on {@see Department::$area}, which is whatever the installation
     * resolved the contract to.
     */
    private function areaByUuid(string $uuid): AreaInterface
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var AreaInterface|null $area */
        $area = $this->entityManager->getRepository($class)->findOneBy(['uuid' => $uuid]);

        if (null === $area) {
            throw new NotFoundHttpException('No area of this installation has that identifier.');
        }

        return $area;
    }
}
