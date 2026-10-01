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

namespace Uhifadhi\Bundle\AreaBundle\Settings;

use Uhifadhi\Bundle\AreaBundle\Service\PingInterval;
use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * SETTINGS › CORE: what the platform does whatever modules run. Presence is the
 * area bundle's — how often a handset on duty reports, and when a silent one
 * goes stale — set by Super Admins and Admins for the organization and
 * customised per area (ruled 1 Oct 2026; the area's own columns retired).
 */
final readonly class CoreSettings implements SettingDefinitionSourceInterface
{
    public const string OWNER = 'core';
    public const string PING_INTERVAL = 'core.ping_interval';
    public const string STALE_AFTER = 'core.stale_after';

    public function settingDefinitions(): iterable
    {
        yield new SettingDefinition(self::PING_INTERVAL, self::OWNER, 'Presence', 'Ping interval',
            'How often a handset on duty reports its position.', SettingType::Number, PingInterval::DEFAULT_MINUTES,
            SettingDepth::Area, unit: 'min', min: 1, max: 1440, position: 1);
        yield new SettingDefinition(self::STALE_AFTER, self::OWNER, 'Presence', 'Stale after',
            'A position older than this shows as stale.', SettingType::Number, null,
            SettingDepth::Area, unit: 'min', min: 1, max: 2880, position: 2, unsetMeans: 'two ping intervals');
    }
}
