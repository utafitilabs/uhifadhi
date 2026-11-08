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
 * THE MARKER A SERIES' POINTS WEAR — a second encoding where two hues sit
 * near each other, so a series is never told by its colour alone. Unstated,
 * a chart of several series gives each the next marker in this order.
 *
 * @see https://www.chartjs.org/docs/latest/configuration/elements.html#point-styles — the backing values are Chart.js point styles
 */
enum PointMarker: string
{
    case Circle = 'circle';
    case Triangle = 'triangle';
    case Rect = 'rect';
    case RectRot = 'rectRot';
    case CrossRot = 'crossRot';
    case Star = 'star';
}
