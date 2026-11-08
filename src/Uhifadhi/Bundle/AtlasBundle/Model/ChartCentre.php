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
 * THE FIGURE IN THE HOLE of a doughnut or a gauge — "18", with its caption
 * under it, "of 33 on duty". The figure is written as the caller formats it.
 */
final readonly class ChartCentre
{
    public function __construct(
        public string $figure,
        public string $caption = '',
    ) {
    }
}
