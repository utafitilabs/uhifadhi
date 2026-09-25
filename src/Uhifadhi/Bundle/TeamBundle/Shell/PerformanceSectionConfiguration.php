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
use Uhifadhi\Bundle\TeamBundle\Controller\PerformanceConfigureController;
use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * WHAT `Configure` OPENS ON THE PERFORMANCE SECTION.
 *
 * ONE SCREEN, and it is settings: what the page is about before
 * anybody has asked it anything — the window it opens on, and what a
 * period is read against. Those are the two controls in the header,
 * and where a reader's choice is remembered is a question about the
 * SECTION rather than about a page in it.
 *
 * THERE IS NO WIDGET LIBRARY ENTRY, because this section has no widget
 * board: every surface here is a composed reading of somebody else's
 * figures, not an arrangement of plates. It is missing rather than
 * faked.
 */
final readonly class PerformanceSectionConfiguration implements ConfigurationSectionsInterface
{
    public function __construct(private Door $door)
    {
    }

    public function slug(): string
    {
        return PerformanceSectionTabs::SURFACE;
    }

    public function heading(): string
    {
        return 'Performance';
    }

    public function summary(): string
    {
        return 'What this section opens on, and what a period is read against.';
    }

    public function sections(): array
    {
        // THE SETTINGS SCREEN ENFORCES THE DEPARTMENTS' CONFIGURE PAIR.
        if (!$this->door->opens(DepartmentConfigureController::PAIR)) {
            return [];
        }

        return [
            ConfigurationSection::screen(
                ConfigurationSection::SETTINGS,
                'Performance settings',
                PerformanceConfigureController::SETTINGS,
            ),
        ];
    }
}
