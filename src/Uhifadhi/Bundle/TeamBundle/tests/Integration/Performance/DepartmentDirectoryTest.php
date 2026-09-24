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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentDirectory;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Performance\PerformanceScope;

/**
 * WHO A TOPIC IS ABOUT, ANSWERED ONCE.
 *
 * A MODULE PUBLISHING A TOPIC HAS TO ENUMERATE DEPARTMENTS, and the
 * first one to try read the team bundle's entity and the registry's
 * ledger across two boundaries it does not depend on. This is the
 * published read, and it answers the whole question: who they are, what
 * they are placed among, what each attaches, and since when each of
 * those modules has been running where they can see it.
 *
 * ATTACHING IS NOT THE SAME AS BEING ASKABLE, which is the distinction
 * the whole thing exists for: a department attaching Patrols in an area
 * that does not run Patrols has nothing to say about patrols, and its
 * cell is `notMine` — nobody asked it, so it did not fail to answer.
 */
#[CoversClass(DepartmentDirectory::class)]
final class DepartmentDirectoryTest extends IntegrationTestCase
{
    public function testItListsTheDepartmentsWithTheirBandAndTheirMark(): void
    {
        $north = $this->anArea('Northern Reserve');
        $this->aDepartment('Ecology');
        $this->aDepartment('Wetland Management', $north);

        $entries = $this->directory()->forScope(PerformanceScope::organization())->entries;

        self::assertSame(['Ecology', 'Wetland Management'], array_map(static fn ($e): string => $e->name, $entries));
        self::assertSame(['Org-wide', 'Northern Reserve'], array_map(static fn ($e): string => $e->band, $entries));
        self::assertSame(['EC', 'WM'], array_map(static fn ($e): string => $e->mark, $entries));
    }

    /** An area's page holds its own departments and the org-wide ones it inherits. */
    public function testAnAreasScopeHoldsItsOwnAndTheOrgWideOnes(): void
    {
        $north = $this->anArea('Northern Reserve');
        $south = $this->anArea('Southern Reserve');
        $this->aDepartment('Ecology');
        $this->aDepartment('Wetland Management', $north);
        $this->aDepartment('Coastal Watch', $south);

        $entries = $this->directory()->forScope(PerformanceScope::area((string) $north->getUuidString(), 'Northern Reserve'))->entries;

        self::assertSame(['Ecology', 'Wetland Management'], array_map(static fn ($e): string => $e->name, $entries));
    }

    /**
     * A DEPARTMENT THAT ATTACHES A MODULE NO AREA IT READS RUNS cannot be
     * asked about it — which is `notMine`, and not an empty figure.
     */
    public function testAttachingIsNotTheSameAsBeingAskable(): void
    {
        $north = $this->anArea('Northern Reserve');
        $module = $this->aModule('patrols');
        $wetlands = $this->aDepartment('Wetland Management', $north);
        $wetlands->attachModule($module);
        $this->em->flush();

        $before = $this->entryFor('Wetland Management');
        self::assertTrue($before->attaches('patrols'), 'it attaches the module');
        self::assertFalse($before->canAnswerFor('patrols'), 'and no area it reads is running it');

        $this->areaModules()->install($north, 'patrols');

        $after = $this->entryFor('Wetland Management');
        self::assertTrue($after->canAnswerFor('patrols'));
        self::assertNotNull($after->runningSince['patrols']);
    }

    /** And the rows of a module's matrix are exactly the askable ones. */
    public function testTheRowsOfAModulesMatrixAreTheDepartmentsThatCanAnswer(): void
    {
        $north = $this->anArea('Northern Reserve');
        $module = $this->aModule('patrols');
        $this->aDepartment('Ecology')->attachModule($module);
        $this->aDepartment('Wetland Management', $north)->attachModule($module);
        $this->aDepartment('Tourism');
        $this->em->flush();
        $this->areaModules()->install($north, 'patrols');

        $rows = $this->directory()->forScope(PerformanceScope::organization())->answeringFor('patrols');

        self::assertSame(
            ['Ecology', 'Wetland Management'],
            array_map(static fn ($e): string => $e->name, $rows),
        );
    }

    /** Org-wide first, then each area — what a matrix bands its placings by. */
    public function testTheBandsAreOrgWideThenTheAreas(): void
    {
        $north = $this->anArea('Northern Reserve');
        $this->aDepartment('Ecology');
        $this->aDepartment('Wetland Management', $north);

        self::assertSame(['Org-wide', 'Northern Reserve'], $this->directory()->forScope(PerformanceScope::organization())->bands());
    }

    private function entryFor(string $name): \Uhifadhi\Contracts\Performance\DepartmentEntry
    {
        foreach ($this->directory()->forScope(PerformanceScope::organization())->entries as $entry) {
            if ($name === $entry->name) {
                return $entry;
            }
        }

        self::fail(\sprintf('No entry for %s.', $name));
    }

    private function aDepartment(string $name, ?HostArea $area = null): Department
    {
        $department = new Department()->setName($name);
        if (null !== $area) {
            $department->setArea($area);
        }
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    private function anArea(string $name): HostArea
    {
        $area = new HostArea()->setName($name);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function aModule(string $slug): Module
    {
        $module = new Module()
            ->setSlug($slug)
            ->setName(ucfirst($slug))
            ->setCategory(ModuleCategory::Pressure)
            ->setStatus(ModuleStatus::Live)
            ->setDataSource('the stand-in')
            ->setPosition(0);
        $this->em->persist($module);
        $this->em->flush();

        return $module;
    }

    private function areaModules(): AreaModuleService
    {
        /** @var AreaModuleService $service */
        $service = static::getContainer()->get('test_public.registry.area_modules');

        return $service;
    }

    private function directory(): DepartmentDirectory
    {
        /** @var DepartmentDirectory $directory */
        $directory = static::getContainer()->get('test_public.'.DepartmentDirectory::class);

        return $directory;
    }
}
