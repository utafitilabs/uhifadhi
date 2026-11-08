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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * THE UNIT A TIME AXIS IS READ IN, and how the house writes it: a tick per
 * unit in a short form, the hover in a long one, both in date-fns tokens.
 *
 * @see https://date-fns.org/v2.30.0/docs/format — the tokens: `HH:mm`, `d MMM`, `II` (the ISO week), `EEE`, `MMMM`, `yyyy`; text between single quotes is written as it is
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/time.html#time-units — `millisecond` to `year`
 */
enum TimeUnit: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** A tick's words. */
    public function format(): string
    {
        return match ($this) {
            self::Hour => 'HH:mm',
            self::Day => 'd MMM',
            self::Week => "'W'II",
            self::Month => 'MMM',
            self::Year => 'yyyy',
        };
    }

    /** The hover's words. */
    public function tooltip(): string
    {
        return match ($this) {
            self::Hour => 'EEE d MMM, HH:mm',
            self::Day => 'EEE d MMM yyyy',
            self::Week => "'Week of' d MMM yyyy",
            self::Month => 'MMMM yyyy',
            self::Year => 'yyyy',
        };
    }
}
