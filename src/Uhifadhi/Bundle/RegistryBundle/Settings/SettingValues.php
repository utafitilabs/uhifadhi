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

/**
 * WHAT IS SET FOR ONE SETTING, as Settings draws it: the organization's value
 * (null while it holds the default) and the custom values per area and per
 * department, keyed by place uuid.
 */
final readonly class SettingValues
{
    /**
     * @param array<string, int|float|bool|string> $areas
     * @param array<string, int|float|bool|string> $departments
     */
    public function __construct(
        public int|float|bool|string|null $organization,
        public array $areas,
        public array $departments,
    ) {
    }
}
