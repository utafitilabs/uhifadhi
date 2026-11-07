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

namespace Uhifadhi\Bundle\TeamBundle\Enum;

/** The places a pair is asked about: the whole organization, or one area. */
enum PlaceKindEnum: string
{
    case Organization = 'organization';
    case Area = 'area';
}
