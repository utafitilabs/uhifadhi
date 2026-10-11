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

namespace Uhifadhi\Bundle\TeamBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentVisibilityEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Performance\DepartmentDirectory;

/**
 * WHICH DEPARTMENTS THE SIGNED-IN VIEWER READS — on need to know. A
 * department is read by the people who belong to it or support it, by
 * somebody whose authority reaches the whole organization, by somebody
 * who may configure it, whose Configure page is reached from its record, and
 * — when it is Open (#74) — by everybody who reads departments. A Listed one
 * is named to everybody in the register ({@see listedOnly()}) and read only as
 * a Private one is.
 *
 * The record asks it of one department and every list asks it of its rows,
 * so a list never names a department whose record would refuse the viewer.
 */
final readonly class DepartmentReach
{
    public function __construct(
        private AreaAuthority $authority,
        private AuthorizationCheckerInterface $authorization,
        private DepartmentRepository $departments,
    ) {
    }

    public function reads(Department $department): bool
    {
        if ($this->authority->isUnbounded() || DepartmentVisibilityEnum::Open === $department->getVisibility()) {
            return true;
        }

        if ($this->authority->actor()?->getPlacement()?->serves($department) ?? false) {
            return true;
        }

        return $department->isAreaLevel()
            && $this->authority->covers($department->getArea())
            && $this->authorization->isGranted('departments.configure');
    }

    /**
     * The Listed departments the viewer does not read: the register names them
     * — name, area, head — and opens none of them.
     *
     * @param iterable<Department> $departments
     *
     * @return list<Department>
     */
    public function listedOnly(iterable $departments): array
    {
        $listed = [];
        foreach ($departments as $department) {
            if (DepartmentVisibilityEnum::Listed === $department->getVisibility() && !$this->reads($department)) {
                $listed[] = $department;
            }
        }

        return $listed;
    }

    /** The directory's entries the viewer reads, for a page that lists departments by their figures. */
    public function readableEntries(DepartmentDirectory $directory): DepartmentDirectory
    {
        $readable = [];
        foreach ($this->readable($this->departments->findAll()) as $department) {
            $readable[(string) $department->getUuidString()] = true;
        }

        return new DepartmentDirectory(array_values(array_filter(
            $directory->entries,
            static fn ($entry): bool => isset($readable[$entry->uuid]),
        )));
    }

    /**
     * @param iterable<Department> $departments
     *
     * @return list<Department>
     */
    public function readable(iterable $departments): array
    {
        $readable = [];
        foreach ($departments as $department) {
            if ($this->reads($department)) {
                $readable[] = $department;
            }
        }

        return $readable;
    }
}
