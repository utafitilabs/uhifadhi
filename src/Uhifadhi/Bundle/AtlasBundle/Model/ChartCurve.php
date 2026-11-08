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
 * HOW A LINE IS DRAWN BETWEEN TWO READINGS.
 *
 * The house draws a gentle curve: enough to read the shape, never enough to
 * invent a value between two periods. A cubic spline overshoots past a jump;
 * a monotone one never goes past a reading, which is the curve for a level
 * or a count.
 *
 * @see https://www.chartjs.org/docs/latest/charts/line.html#cubicinterpolationmode — "default" is a weighted cubic; "monotone" preserves monotonicity
 */
enum ChartCurve: string
{
    case Straight = 'straight';
    case Gentle = 'gentle';
    case Cubic = 'cubic';
    case Monotone = 'monotone';

    /** The line tension Chart.js is handed for this curve. */
    public function tension(): float
    {
        return match ($this) {
            self::Straight => 0.0,
            self::Gentle => 0.25,
            self::Cubic, self::Monotone => 0.4,
        };
    }
}
