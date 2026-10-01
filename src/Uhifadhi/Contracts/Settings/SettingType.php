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
 * WHAT A SETTING'S VALUE IS: a whole number in a unit, a switch, or one of a
 * fixed set of choices. The Configure page draws the field from it, and the
 * store refuses a value of the wrong shape.
 */
enum SettingType: string
{
    /** A whole number, in the definition's unit and within its limits. */
    case Number = 'number';

    /** On or off. */
    case Toggle = 'toggle';

    /** One of the definition's choices. */
    case Choice = 'choice';
}
