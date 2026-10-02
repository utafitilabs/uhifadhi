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

use Symfony\Contracts\Service\ResetInterface;
use Uhifadhi\Bundle\RegistryBundle\Repository\SettingValueRepository;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * THE VALUE IN FORCE: the department's own, else the area's, else the
 * organization's, else the definition's default — each level read only where
 * the setting's depth reaches it, so a stray row at a level the setting does
 * not reach is never believed.
 *
 * ONE QUERY A REQUEST, WHATEVER IS ASKED: a live read asks for every person's
 * area, so the rows are read once — all of them, they are few — and held until
 * the request ends (kernel.reset) or a save changes them ({@see reset()}).
 */
final class SettingsResolver implements SettingsReaderInterface, ResetInterface
{
    /** @var array<string, int|float|bool|string>|null keyed "key|level|place" */
    private ?array $set = null;

    public function __construct(
        private readonly SettingsCatalogue $catalogue,
        private readonly SettingValueRepository $values,
    ) {
    }

    public function reset(): void
    {
        $this->set = null;
    }

    public function value(string $key, ?string $areaUuid = null, ?string $departmentUuid = null): int|float|bool|string|null
    {
        $definition = $this->catalogue->get($key);
        $set = $this->set ??= $this->load();

        $candidates = [
            [SettingDepth::Department, $departmentUuid],
            [SettingDepth::Area, $areaUuid],
            [SettingDepth::Organization, null],
        ];
        foreach ($candidates as [$level, $place]) {
            if (!$definition->depth->reaches($level) || (SettingDepth::Organization !== $level && null === $place)) {
                continue;
            }
            $found = $set[$key.'|'.$level->value.'|'.($place ?? '')] ?? null;
            if (null !== $found) {
                return $found;
            }
        }

        return $definition->default;
    }

    /**
     * @return array<string, int|float|bool|string>
     */
    private function load(): array
    {
        $set = [];
        foreach ($this->values->findAll() as $row) {
            $set[$row->getKey().'|'.$row->getLevel()->value.'|'.($row->getPlaceUuid() ?? '')] = $row->getValue();
        }

        return $set;
    }
}
