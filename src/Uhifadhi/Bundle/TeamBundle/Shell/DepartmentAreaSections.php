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

use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Contracts\Shell\AreaSectionsInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * DEPARTMENTS ON AN AREA'S CONFIGURE STRIP.
 *
 * THE AREA DOES NOT NAME DEPARTMENTS — the bundle that owns them puts them on
 * the strip instead, so an installation without this bundle reads a strip
 * without the entry rather than a link to a route nobody serves.
 *
 * A SCREEN, NOT A RENDERED SECTION, for the same reason Zones and Stations
 * are: it takes writes and answers them with redirects, so it has an address
 * of its own. The frame is identical either way.
 */
final readonly class DepartmentAreaSections implements AreaSectionsInterface
{
    public function __construct(private Door $door)
    {
    }

    public function sectionsFor(string $areaUuid, string $areaName): array
    {
        // THE SCREEN ENFORCES `departments.read`, asked without the area as
        // its route asks it: departments are never placed by area.
        if (!$this->door->opens(DepartmentController::READ)) {
            return [];
        }

        return [ConfigurationSection::screen(
            'departments',
            'Departments',
            AreaDepartmentController::SECTION,
            ['uuid' => $areaUuid],
        )];
    }
}
