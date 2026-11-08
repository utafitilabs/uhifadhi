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

namespace Uhifadhi\Bundle\ShellBundle\Contract;

use Uhifadhi\Bundle\ShellBundle\Model\ModuleSettingsPage;

/**
 * THE MODULES WITH A SETTINGS PAGE OF THEIR OWN, for Settings › Modules to
 * open onto. Whoever draws those pages publishes this under {@see SERVICE};
 * where nobody does, Settings › Modules simply has no doors.
 */
interface ModuleSettingsPagesInterface
{
    public const string SERVICE = 'uhifadhi.module_settings_pages';

    /**
     * @return list<ModuleSettingsPage> one per module that declares a setting, in name order
     */
    public function pages(): array;
}
