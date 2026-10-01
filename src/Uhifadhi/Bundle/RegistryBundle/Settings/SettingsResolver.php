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

namespace Uhifadhi\Bundle\RegistryBundle\Settings;

use Uhifadhi\Bundle\RegistryBundle\Repository\SettingValueRepository;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * THE VALUE IN FORCE: the department's own, else the area's, else the
 * organization's, else the definition's default — each level read only where
 * the setting's depth reaches it, so a stray row at a level the setting does
 * not reach is never believed.
 */
final readonly class SettingsResolver implements SettingsReaderInterface
{
    public function __construct(
        private SettingsCatalogue $catalogue,
        private SettingValueRepository $values,
    ) {
    }

    public function value(string $key, ?string $areaUuid = null, ?string $departmentUuid = null): int|bool|string
    {
        $definition = $this->catalogue->get($key);
        $set = [];
        foreach ($this->values->forKey($key) as $row) {
            $set[$row->getLevel()->value.'|'.($row->getPlaceUuid() ?? '')] = $row->getValue();
        }

        $candidates = [
            [SettingDepth::Department, $departmentUuid],
            [SettingDepth::Area, $areaUuid],
            [SettingDepth::Organization, null],
        ];
        foreach ($candidates as [$level, $place]) {
            if (!$definition->depth->reaches($level) || (SettingDepth::Organization !== $level && null === $place)) {
                continue;
            }
            $found = $set[$level->value.'|'.($place ?? '')] ?? null;
            if (null !== $found) {
                return $found;
            }
        }

        return $definition->default;
    }
}
