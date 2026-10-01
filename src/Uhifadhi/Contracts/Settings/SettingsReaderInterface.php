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

namespace Uhifadhi\Contracts\Settings;

/**
 * THE VALUE IN FORCE, for a module or the core to act on. The answer is the
 * department's custom value if it has one, else the area's, else the
 * organization's, else the definition's default — each level only where the
 * setting's depth reaches it. Pass the area and the department the reading is
 * about; either may be null. Null comes back only for a number left unset
 * ({@see SettingDefinition::\$unsetMeans}): its owner computes the value.
 */
interface SettingsReaderInterface
{
    /**
     * @throws \InvalidArgumentException when no definition has this key
     */
    public function value(string $key, ?string $areaUuid = null, ?string $departmentUuid = null): int|bool|string|null;
}
