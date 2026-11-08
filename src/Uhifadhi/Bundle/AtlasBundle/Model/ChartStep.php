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
 * WHERE A STEPPED LINE CHANGES ITS LEVEL against the reading that changed
 * it: after it (the house), before it, or half-way to the next.
 *
 * @see https://www.chartjs.org/docs/latest/charts/line.html#stepped — `stepped`: true, 'before', 'after' or 'middle'
 */
enum ChartStep: string
{
    case After = 'after';
    case Before = 'before';
    case Middle = 'middle';
}
