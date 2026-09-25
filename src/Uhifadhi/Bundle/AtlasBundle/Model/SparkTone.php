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
 * WHAT A SPARKLINE'S MOVEMENT MEANS — never which way it points.
 *
 * More days to settle is a worse month; a line painted green for every rise
 * would congratulate a department for it. So the caller, who knows what the
 * figure measures, says whether the movement is good, bad or neither, and
 * the value is the class the sheet paints the line by.
 */
enum SparkTone: string
{
    case Good = 'up';
    case Bad = 'dn';
    case Flat = 'fl';
}
