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
 * HOW FAR DOWN A SETTING MAY BE CUSTOMISED. The organization always holds a
 * value; a setting of depth Area may also hold one per area, and a setting of
 * depth Department one per area and one per department. Only Super Admins and
 * Admins set any of them, in Settings.
 */
enum SettingDepth: string
{
    /** One value for the whole organization. */
    case Organization = 'organization';

    /** The organization's value, customised for an area where needed. */
    case Area = 'area';

    /** The organization's value, customised for an area or a department. */
    case Department = 'department';

    /** Whether a setting of this depth may hold a value at the given level. */
    public function reaches(self $level): bool
    {
        return $level->rank() <= $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Organization => 0,
            self::Area => 1,
            self::Department => 2,
        };
    }
}
