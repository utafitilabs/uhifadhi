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

namespace Uhifadhi\Bundle\AtlasBundle\Model\Heatmap;

/**
 * WHERE A FIGURE STANDS IN ITS COLUMN — five places and the absence, each a
 * class the heat sheet tints. Jade for the top of a column, amber and then
 * red for the bottom of it; the absence is dashed rather than pale, so
 * "nobody published" cannot be read as "published badly".
 */
enum HeatTint: string
{
    case Leads = 'h5';
    case Above = 'h4';
    case Mid = 'h3';
    case Below = 'h2';
    case Trails = 'h1';
    case None = 'h0';
}
