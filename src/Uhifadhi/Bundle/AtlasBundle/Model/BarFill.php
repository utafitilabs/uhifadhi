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
 * WHAT A RANKED BAR'S FILL IS DRAWN IN — a class the sheet paints, never a
 * colour. The rest of a two-part bar is always the faded fail.
 */
enum BarFill: string
{
    /** The house accent: the one quantity the card measures. */
    case Solid = 'f';

    /** The accent at 42%: a quantity read beside another, or a count out of a catalogue. */
    case Soft = 'b';
}
