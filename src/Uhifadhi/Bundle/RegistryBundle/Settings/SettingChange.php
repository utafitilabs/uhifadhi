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

use Uhifadhi\Contracts\Settings\SettingDepth;

/**
 * ONE LINE OF THE SAVE BAR'S REVIEW: a setting, the level and place it is set
 * at, and the new value — or null to reset a custom value, so the place
 * follows the level above again. The organization is no place: its uuid is null.
 */
final readonly class SettingChange
{
    public function __construct(
        public string $key,
        public SettingDepth $level,
        public ?string $placeUuid,
        public int|bool|string|null $value,
    ) {
    }

    public function isReset(): bool
    {
        return null === $this->value;
    }
}
