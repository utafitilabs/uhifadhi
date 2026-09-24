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

use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

/**
 * A MODULE WITH SETTINGS OF ITS OWN, stood in for. The modules register
 * draws a `Configure` door on the row of a module that declares configure
 * sections and "no settings" on one that declares none; this is the one
 * that declares some, so both halves of that rule can be asserted.
 */
final readonly class PatrolsConfigurationSections implements ConfigurationSectionsInterface
{
    public function slug(): string
    {
        return 'patrols';
    }

    public function heading(): string
    {
        return 'Patrols';
    }

    public function summary(): ?string
    {
        return null;
    }

    public function sections(): array
    {
        return [
            ConfigurationSection::page(ConfigurationSection::WIDGETS, 'Widget library', '@fixtures/org_page.html.twig'),
            ConfigurationSection::page('types', 'Patrol types', '@fixtures/org_page.html.twig'),
        ];
    }
}
