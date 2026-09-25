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
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * WHAT `Configure` OPENS IN THE DEPARTMENTS SECTION.
 *
 * TWO SCREENS, NOT TWO TEMPLATES. An area's configure sections are rendered
 * into the shell's own area-shaped page; an org-level section has no area in
 * its address, so its sections are screens of its own and the strip is built
 * from their routes. That is a difference of address, not of idiom: the same
 * contract, the same strip, the same one position on the page.
 *
 * THE ORDER IS THE HOUSE'S, NOT THIS CLASS'S. The shell ranks a surface's
 * sections — Widget library, then everything else in the order it was
 * declared, then Settings last — so the strip reads the same on every surface
 * in the product whatever order a section happened to list them in. That rank
 * also decides what the one `Configure` action opens: the first entry.
 *
 * THERE IS NO WIDGET LIBRARY ENTRY YET because this section has no widget
 * board; the drawn strip has one, and it is missing here rather than faked.
 */
final readonly class DepartmentSectionConfiguration implements ConfigurationSectionsInterface
{
    public function __construct(private Door $door)
    {
    }

    public function slug(): string
    {
        return DepartmentSectionTabs::SURFACE;
    }

    public function heading(): string
    {
        return 'Departments';
    }

    public function summary(): string
    {
        return 'How this section behaves, who may change it, and the vocabulary it writes with.';
    }

    public function sections(): array
    {
        // BOTH SCREENS ENFORCE ONE PAIR, so a viewer without it has no
        // section to be offered and no Configure action to reach one by.
        if (!$this->door->opens(DepartmentConfigureController::PAIR)) {
            return [];
        }

        return [
            ConfigurationSection::screen(
                ConfigurationSection::SETTINGS,
                'Departments settings',
                DepartmentConfigureController::SETTINGS,
            ),
            ConfigurationSection::screen(
                'lists',
                'Lists',
                DepartmentConfigureController::LISTS,
            ),
        ];
    }
}
