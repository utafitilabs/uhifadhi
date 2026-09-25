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
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Model\DepartmentQuery;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Shell\AreaNavChild;
use Uhifadhi\Contracts\Shell\AreaNavChildrenInterface;

/**
 * THE DEPARTMENTS UNDER AN AREA IN THE SIDEBAR.
 *
 * THE AREA'S DEPARTMENTS TAB HAS NO PICKER COLUMN, so this is how one is
 * chosen — exactly as the organization's register is picked from under its
 * own row. A rung points at the tab with that card FOCUSED, which is the one
 * value the page marks, so the lit row and the marked card cannot disagree.
 *
 * ORG-WIDE FIRST, THEN THE AREA'S OWN. The tree is read from the
 * organization inwards — what every area shares, then what this one has of
 * its own — and the tab's page is read the other way about, from this place
 * outwards. Both orders are the reader's position stated honestly; neither
 * is the other's order got wrong.
 */
final readonly class DepartmentAreaNavChildren implements AreaNavChildrenInterface
{
    public function __construct(
        private RouterInterface $router,
        private RequestStack $requests,
        private DepartmentRepository $departments,
        private Door $door,
    ) {
    }

    public function screenRoute(): string
    {
        return AreaDepartmentController::TAB;
    }

    public function childrenFor(string $areaUuid, string $areaName): array
    {
        // EVERY RUNG OPENS THE AREA'S DEPARTMENTS TAB, so it asks that tab's pair.
        if (!$this->door->opens(DepartmentController::READ)) {
            return [];
        }

        $tab = $this->router->generate(AreaDepartmentController::TAB, ['uuid' => $areaUuid]);
        $focused = $this->requests->getCurrentRequest()?->query->get(DepartmentQuery::FOCUS);

        $rows = [];
        foreach ([...$this->departments->findOrgLevelOrdered(), ...$this->ownOf($areaUuid)] as $department) {
            $uuid = (string) $department->getUuidString();

            $rows[] = new AreaNavChild(
                label: (string) $department->getName(),
                url: $tab.'?'.http_build_query([DepartmentQuery::FOCUS => $uuid]).'#d-'.$uuid,
                current: $uuid === $focused,
            );
        }

        return $rows;
    }

    /**
     * THIS AREA'S OWN, BY THE IDENTIFIER THE TREE WAS GIVEN. The contract
     * hands an area's uuid rather than an area, so the lookup is over the
     * departments' own association: this bundle never names the class an
     * installation resolved the area contract to.
     *
     * @return list<\Uhifadhi\Bundle\TeamBundle\Entity\Department>
     */
    private function ownOf(string $areaUuid): array
    {
        $own = [];
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            if ($areaUuid === $department->getArea()?->getUuidString()) {
                $own[] = $department;
            }
        }

        return $own;
    }
}
