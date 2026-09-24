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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Uhifadhi\Contracts\Shell\AreaSectionsInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSection;

/**
 * ANOTHER BUNDLE PUTTING A SECTION ON AN AREA'S CONFIGURE STRIP, tagged the
 * way the team bundle tags its Departments section. It stands in for every
 * such contribution: the area bundle never learns what it is about.
 */
final readonly class FakeAreaSections implements AreaSectionsInterface
{
    public function sectionsFor(string $areaUuid, string $areaName): array
    {
        return [ConfigurationSection::screen('contributed', 'Contributed '.$areaName, 'test_contributed_section', ['uuid' => $areaUuid])];
    }
}
