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
 * A TEXTURE OVER A COLOUR — the house hatch, 45° strokes of the colour over
 * a fifth of it: a second encoding for "empty" or "not yet", whose own hue
 * sits too near its neighbours to stand alone.
 */
enum ChartTexture: string
{
    case Hatch = 'hatch';
}
