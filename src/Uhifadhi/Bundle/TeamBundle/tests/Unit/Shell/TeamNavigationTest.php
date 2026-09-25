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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Shell;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\ShellBundle\Frame\Service\ModuleFrameService;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentSectionController;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPalette;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamNavigation;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * THE TWO ANSWERS THAT ARE HARD TO STAGE IN A BROWSER — an installation that
 * unmounted this bundle's routes, and a render with no security context at all.
 *
 * Both are the same kind of failure and it is the worst kind a nav source has:
 * an exception thrown while building the sidebar takes down EVERY page in the
 * installation, including the ones that had nothing to do with this bundle. So
 * they are asserted here rather than reasoned about.
 *
 * What a viewer actually sees is asserted where it belongs, against real markup
 * a browser received — see tests/Functional/SidebarRowTest.
 */
#[CoversClass(TeamNavigation::class)]
final class TeamNavigationTest extends TestCase
{
    /**
     * THE ADDRESSES BELONG TO THE APPLICATION. The recipe's
     * config/routes/team.yaml is the installation's file and it may edit or
     * delete it; when it does, this bundle loses its screens — and losing your
     * screens must not mean losing everybody's.
     */
    public function testAnInstallationThatUnmountedTheRoutesGetsNoRowRatherThanAnError(): void
    {
        $navigation = new TeamNavigation(
            $this->urlsThatCannotGenerate(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            new RequestStack(),
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        self::assertSame([], iterator_to_array($navigation->sections()));
    }

    /**
     * AND UNMOUNTING IS PER-ADDRESS, which is why the rows are generated one at
     * a time. An installation that kept the roster and dropped the departments
     * screen loses ONE row; the section it belongs to survives with what is
     * still reachable. The whole-section answer above is what happens when
     * nothing is left, not what happens when something is missing.
     */
    public function testUnmountingOneScreenTakesOneRowAndLeavesTheOther(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route): string => TeamNavigation::ROUTE === $route
                ? '/team'
                : throw new RouteNotFoundException(),
        );

        $navigation = new TeamNavigation(
            $urls,
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            new RequestStack(),
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        self::assertCount(1, $sections);

        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);
        self::assertSame(['Team'], array_map(static fn ($item): string => $item->label, $section->items));
    }

    /**
     * NO TOKEN, NO QUESTION. Asking the authorization checker with no token
     * throws rather than answering false, and a shell renders in places a
     * firewall does not reach.
     */
    public function testAViewerNobodyCanIdentifyGetsNoRowRatherThanAnError(): void
    {
        $navigation = new TeamNavigation(
            $this->urlsAnsweringTeam(),
            new TokenStorage(),
            $this->checkerThatRefusesToBeAsked(),
            new RequestStack(),
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        self::assertSame([], iterator_to_array($navigation->sections()));
    }

    /**
     * AND THE ROWS THEMSELVES ARE VALUES, not renderings — the section's
     * heading, its declared position and the ORDER of what is in it are part of
     * what this bundle promises the shell, so they are stated somewhere that
     * fails when they change.
     *
     * DEPARTMENTS COMES FIRST, and that is the drawing rather than a
     * preference: Departments sits above Team under Organization, because the
     * org chart is the thing the roster is read against.
     */
    public function testTheTwoRowsAreOneSectionAtTheDeclaredPosition(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/somewhere-else'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        self::assertCount(1, $sections);

        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);
        self::assertSame('Organization', $section->label);
        self::assertSame(20, $section->position);
        self::assertCount(2, $section->items);

        self::assertSame('Departments', $section->items[0]->label);
        self::assertSame('/departments', $section->items[0]->url);
        self::assertSame('shell:building-2', $section->items[0]->icon);
        self::assertFalse($section->items[0]->current);

        self::assertSame('Team', $section->items[1]->label);
        self::assertSame('/team', $section->items[1]->url);
        self::assertSame('shell:users', $section->items[1]->icon);
        self::assertFalse($section->items[1]->current);
    }

    /**
     * THE TWO ROWS CANNOT LIGHT EACH OTHER. This is the reason departments is
     * addressed at `/departments` rather than under the roster: the roster's
     * row answers "am I here" by path prefix, so a `/team/departments` would
     * have lit the Team row on the departments screen and the sidebar would
     * have answered "where am I" with two places at once.
     */
    public function testStandingOnDepartmentsLightsDepartmentsAndNotTheRoster(): void
    {
        $requests = new RequestStack();
        $requests->push(self::inTheSection('/departments', TeamNavigation::DEPARTMENTS_ROUTE));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);

        self::assertTrue($section->items[0]->current, 'Departments is not lit on the departments screen.');
        self::assertFalse($section->items[1]->current, 'The roster row is lit on a screen it does not lead to.');
    }

    /**
     * THE SECTION UNFOLDS INTO ITS SCREENS, and the register unfolds into its
     * departments. A section wears the area idiom, so its row opens the way an
     * area's does: the three screens the tab strip carries, in that order, and
     * the register's own picker hanging off the middle one — the page keeps no
     * picker column, so the sidebar is where a department is chosen.
     */
    public function testTheDepartmentsRowUnfoldsIntoTheSectionsScreens(): void
    {
        $requests = new RequestStack();
        $requests->push(self::inTheSection('/departments', TeamNavigation::DEPARTMENTS_ROUTE));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed(['Ecology' => null, 'Wetland Management' => 'Northern Reserve']),
            $this->paletteOver(['Ecology' => null, 'Wetland Management' => 'Northern Reserve']),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);

        $screens = $section->items[0]->children;
        self::assertSame(
            ['Overview', 'Departments', 'Modules'],
            array_map(static fn (NavItem $i): string => $i->label, $screens),
        );
        self::assertTrue($screens[1]->current, 'The register is the screen this request is on.');
        self::assertFalse($screens[0]->current, 'Overview is lit on a screen it does not lead to.');

        $groups = $screens[1]->children;
        self::assertSame(['Org-wide', 'Northern Reserve'], array_map(static fn (NavItem $i): string => $i->label, $groups));
        self::assertSame(['Ecology'], array_map(static fn (NavItem $i): string => $i->label, $groups[0]->children));
        self::assertSame(['Wetland Management'], array_map(static fn (NavItem $i): string => $i->label, $groups[1]->children));

        // The entry points at the register with that card focused and anchored.
        self::assertStringContainsString('focus=', (string) $groups[0]->children[0]->url);
        self::assertStringContainsString('#d-', (string) $groups[0]->children[0]->url);
    }

    /** A viewer who is not on the register does not pay for its subtree. */
    public function testTheSubtreeIsBuiltOnlyWhereItCanBeSeen(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/team'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsThatMustNotBeAsked(),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);
        self::assertSame([], $section->items[0]->children);
    }

    /**
     * A repository answering with departments, each named and either the
     * organization's or one area's.
     *
     * @param array<string, string|null> $named name to the area's name, or null for org-wide
     */
    private function departmentsNamed(array $named): DepartmentRepository
    {
        $departments = $this->cast($named);

        $repository = $this->createStub(DepartmentRepository::class);
        $repository->method('findAllActiveOrdered')->willReturn($departments);

        return $repository;
    }

    /**
     * `/departments` IS A PREFIX OF SOMEBODY ELSE'S ADDRESS, and the row does
     * not claim what is under it.
     *
     * Performance is a place of its own at `/departments/performance` with its
     * own row; matched by path prefix the Departments row lit there too, and a
     * sidebar that answers "where am I" with two rows in one section answers
     * it with neither. A screen is this section's when it carries the
     * section's marker or matched one of this bundle's own addresses.
     */
    public function testTheDepartmentsRowDoesNotClaimAnotherPlaceUnderItsPrefix(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/departments/performance'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        $lit = array_values(array_filter($sections[0]->items, static fn (NavItem $i): bool => $i->current));

        self::assertSame([], $lit, 'Neither of this bundle\'s rows is the place Performance is.');
    }

    /**
     * A PERSON'S RECORD LIGHTS THE PEOPLE SCREEN, and a position's record the
     * register: the tree opens the path to the screen a record belongs to,
     * rather than leaving the viewer standing nowhere in it.
     */
    public function testARecordPageLightsTheScreenItBelongsTo(): void
    {
        foreach (['team_member' => 'People', 'team_member_configure' => 'People', 'team_position_show' => 'Positions', 'team_position_configure' => 'Positions'] as $route => $screen) {
            $requests = new RequestStack();
            $request = Request::create('/team/0198f0b6-0000-7000-8000-000000000000');
            $request->attributes->set('_route', $route);
            $request->attributes->set(ModuleFrameService::MODULE_ROUTE_ATTRIBUTE, TeamSectionTabs::SURFACE);
            $requests->push($request);

            $navigation = new TeamNavigation(
                $this->urlsAnsweringByRoute(),
                $this->tokenStorageWithAToken(),
                $this->checkerAnswering(true),
                $requests,
                $this->departmentsNamed([]),
                $this->paletteOver([]),
                $this->settings(),
            );

            $team = null;
            foreach (iterator_to_array($navigation->sections()) as $section) {
                foreach ($section->items as $item) {
                    if ('Team' === $item->label) {
                        $team = $item;
                    }
                }
            }
            self::assertNotNull($team, 'The Team row is drawn.');
            $lit = array_values(array_filter($team->children, static fn (NavItem $i): bool => $i->current));
            self::assertCount(1, $lit, $route.' lights exactly one screen.');
            self::assertSame($screen, $lit[0]->label, $route.' belongs to '.$screen.'.');
        }
    }

    /** And a department's own record page, which carries no marker, still lights it. */
    public function testADepartmentsRecordPageLightsTheDepartmentsRow(): void
    {
        $requests = new RequestStack();
        $request = Request::create('/departments/0198f0b6-0000-7000-8000-000000000000');
        $request->attributes->set('_route', 'team_department_show');
        $requests->push($request);

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed([]),
            $this->paletteOver([]),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        self::assertTrue($sections[0]->items[0]->current);
    }

    /**
     * A DEPARTMENT IN THE TREE WEARS ITS OWN HUE, and it is the same hue it
     * wears everywhere else.
     *
     * THE ROW HANDS OVER A CATEGORY AND NEVER A COLOUR. The shell resolves the
     * index against the palette, which is the only way one department reads
     * the same in both themes; a hex here would be right in one of them.
     */
    public function testEachDepartmentsDotCarriesItsOwnCategoryAndNotTheAccent(): void
    {
        $named = ['Ecology' => null, 'Wetland Management' => 'Northern Reserve'];
        $requests = new RequestStack();
        $requests->push(self::inTheSection('/departments', TeamNavigation::DEPARTMENTS_ROUTE));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
            $this->departmentsNamed($named),
            $this->paletteOver($named),
            $this->settings(),
        );

        $sections = iterator_to_array($navigation->sections());
        $register = $sections[0]->items[0]->children[1];
        $rows = [];
        foreach ($register->children as $group) {
            foreach ($group->children as $row) {
                $rows[$row->label] = $row->swatch;
            }
        }

        self::assertSame(['Ecology' => 'var(--cat-1)', 'Wetland Management' => 'var(--cat-2)'], $rows);
    }

    /**
     * THE PALETTE OVER THE SAME LIST — a department's hue is its position in
     * the register's own order, so the stub answers the order the tree reads.
     *
     * @param array<string, string|null> $named
     */
    private function paletteOver(array $named): DepartmentPalette
    {
        $departments = $this->cast($named);

        $repository = $this->createStub(DepartmentRepository::class);
        $repository->method('findAllOrdered')->willReturn($departments);

        return new DepartmentPalette($repository);
    }

    /** A repository the navigation must not reach for at all. */
    private function departmentsThatMustNotBeAsked(): DepartmentRepository
    {
        $repository = $this->createMock(DepartmentRepository::class);
        $repository->expects(self::never())->method('findAllActiveOrdered');

        return $repository;
    }

    /**
     * ONE CAST FOR ONE TEST, whichever helper asks for it. The tree and the
     * palette read the same departments in an installation, and two stub lists
     * of look-alikes would agree about everything except their identity — which
     * is the one thing the hue is keyed on.
     *
     * @param array<string, string|null> $named
     *
     * @return list<Department>
     */
    private function cast(array $named): array
    {
        $key = implode('|', array_map(static fn (string $n, ?string $a): string => $n.':'.$a, array_keys($named), $named));
        $this->casts[$key] ??= array_map(self::aDepartment(...), array_keys($named), array_values($named));

        return $this->casts[$key];
    }

    /** @var array<string, list<Department>> */
    private array $casts = [];

    private static function aDepartment(string $name, ?string $area): Department
    {
        $department = new Department()->setName($name);
        $department->generateUuid();
        if (null !== $area) {
            $department->setArea(new HostArea()->setName($area));
        }

        return $department;
    }

    private function urlsAnsweringByRoute(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => match ($route) {
            TeamNavigation::ROUTE => '/team',
            DepartmentSectionController::OVERVIEW => '/departments/overview',
            DepartmentSectionController::MODULES => '/departments/modules',
            default => '/departments',
        });

        return $urls;
    }

    /**
     * A REQUEST INSIDE THE DEPARTMENTS SECTION, marked the way the router
     * marks one: the surface default the section's routes carry, and the route
     * that matched. The sidebar reads both — the marker to know it is inside
     * the section at all, the route to know WHICH screen — so a bare Request
     * would be testing a request the application never makes.
     */
    private static function inTheSection(string $path, string $route): Request
    {
        $request = Request::create($path);
        $request->attributes->set('_route', $route);
        $request->attributes->set(ModuleFrameService::MODULE_ROUTE_ATTRIBUTE, DepartmentSectionTabs::SURFACE);

        return $request;
    }

    private function urlsAnsweringTeam(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/team');

        return $urls;
    }

    private function urlsThatCannotGenerate(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willThrowException(new RouteNotFoundException());

        return $urls;
    }

    private function tokenStorageWithAToken(): TokenStorageInterface
    {
        $storage = new TokenStorage();
        $storage->setToken($this->createStub(TokenInterface::class));

        return $storage;
    }

    private function checkerAnswering(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function checkerThatRefusesToBeAsked(): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        return $checker;
    }

    /** The rules of an installation that never saved them: ranks on. */
    private function settings(): TeamSettingsService
    {
        return new TeamSettingsService($this->createStub(TeamSettingsRepository::class), $this->createStub(EntityManagerInterface::class));
    }
}
