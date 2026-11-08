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
 * HOW THE LIBRARY THINS A LONG RUN: largest-triangle-three-buckets keeps the
 * shape at a number of samples; min-max keeps every peak and trough.
 *
 * @see https://www.chartjs.org/docs/latest/configuration/decimation.html#decimation-algorithms
 */
enum DecimationAlgorithm: string
{
    case Lttb = 'lttb';
    case MinMax = 'min-max';
}
