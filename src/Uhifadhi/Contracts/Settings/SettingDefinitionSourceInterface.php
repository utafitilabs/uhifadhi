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
 * WHERE SETTINGS COME FROM. The core and every module that has settings tag one
 * implementation; the registry collects them, refuses two owners declaring the
 * same key, and Settings draws one page per owner — Settings › Core for "core",
 * Settings › Modules › <module> for a module's slug.
 */
interface SettingDefinitionSourceInterface
{
    public const string TAG = 'uhifadhi.setting_definition';

    /**
     * @return iterable<SettingDefinition>
     */
    public function settingDefinitions(): iterable;
}
