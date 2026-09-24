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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentMark;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Performance\DepartmentDirectory as Directory;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\DepartmentEntry;
use Uhifadhi\Contracts\Performance\PerformanceScope;

/**
 * WHO THE DEPARTMENTS ARE, WHAT THEY ATTACH, AND SINCE WHEN THEY COULD
 * HAVE BEEN ASKED — the host's answer to the question every topic starts
 * with.
 *
 * THE JOIN IS DONE HERE, ONCE. The departments are this bundle's and the
 * area × module ledger is the registry's; a module publishing a topic
 * would otherwise reach across two package boundaries it does not depend
 * on — which is exactly what the first one to try had to do.
 *
 * "ATTACHES IT" AND "CAN BE ASKED ABOUT IT" ARE DIFFERENT THINGS, and
 * this is where the difference is computed. A department attaching
 * Patrols in an area that does not run Patrols has nothing to say about
 * patrols, and its cell is `notMine` rather than an empty figure: nobody
 * asked it, so it did not fail to answer.
 *
 * AN ORG-WIDE DEPARTMENT READS EVERY AREA, so it can be asked about a
 * module any area runs, and its `runningSince` is the EARLIEST of those
 * — the first day anywhere it could have been asked at all.
 */
final readonly class DepartmentDirectory implements DepartmentDirectoryInterface
{
    public function __construct(
        private DepartmentRepository $departments,
        private AreaModuleRepository $areaModules,
    ) {
    }

    public function forScope(PerformanceScope $scope): Directory
    {
        $running = $this->areaModules->runningSince();

        $entries = [];
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            $areaUuid = $department->getArea()?->getUuidString();

            // AN AREA'S PAGE HOLDS THE DEPARTMENTS THAT READ THAT AREA:
            // its own, and the org-wide ones every area inherits.
            if (!$scope->isOrganization() && null !== $areaUuid && $areaUuid !== $scope->areaUuid) {
                continue;
            }

            $attached = [];
            foreach ($department->getModules() as $module) {
                $attached[] = (string) $module->getSlug();
            }

            $entries[] = new DepartmentEntry(
                uuid: (string) $department->getUuidString(),
                name: (string) $department->getName(),
                areaUuid: $areaUuid,
                band: self::bandOf($department),
                attached: $attached,
                runningSince: self::since($attached, $this->areasRead($department, $scope), $running),
                mark: DepartmentMark::of((string) $department->getName()),
            );
        }

        return new Directory($entries);
    }

    /**
     * WHICH AREAS THIS DEPARTMENT CAN SEE, inside this scope: an
     * area-level department sees its own; an org-wide one sees every area
     * the page is about.
     *
     * @return list<string>
     */
    private function areasRead(Department $department, PerformanceScope $scope): array
    {
        $own = $department->getArea()?->getUuidString();
        if (null !== $own) {
            return [$own];
        }

        if (!$scope->isOrganization()) {
            return [(string) $scope->areaUuid];
        }

        return array_keys($this->areaModules->runningSince());
    }

    /**
     * SINCE WHEN EACH ATTACHED MODULE HAS BEEN RUNNING WHERE THIS
     * DEPARTMENT CAN SEE IT — the earliest such day, and null where no
     * area it reads runs the module at all.
     *
     * @param list<string>                                          $attached
     * @param list<string>                                          $areas
     * @param array<string, array<string, \DateTimeImmutable|null>> $running
     *
     * @return array<string, \DateTimeImmutable|null>
     */
    private static function since(array $attached, array $areas, array $running): array
    {
        $since = [];
        foreach ($attached as $slug) {
            $earliest = null;
            $anywhere = false;

            foreach ($areas as $areaUuid) {
                if (!\array_key_exists($slug, $running[$areaUuid] ?? [])) {
                    continue;
                }

                $anywhere = true;
                $day = $running[$areaUuid][$slug];
                if (null !== $day && (null === $earliest || $day < $earliest)) {
                    $earliest = $day;
                }
            }

            // RUNNING SINCE NOBODY KNOWS WHEN is still running: a row
            // written before the day was recorded dates no holes, but it
            // does not make the module unaskable.
            $since[$slug] = $anywhere ? ($earliest ?? new \DateTimeImmutable('@0')) : null;
        }

        return $since;
    }

    /** What this row is placed among: the organization, or its area. */
    private static function bandOf(Department $department): string
    {
        $area = $department->getArea();

        return null === $area ? 'Org-wide' : (string) $area->getName();
    }
}
