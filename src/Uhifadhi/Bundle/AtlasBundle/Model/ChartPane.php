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
 * ONE PANE OF A STACKED-PANELS CHART: its own value axis, title and share
 * of the height, under one time axis shared by both — two measures of
 * different size shown as two panes, never as two scales on one plot.
 */
final readonly class ChartPane
{
    public function __construct(
        public string $title = '',
        /** Its share of the height against the other pane's. */
        public float $weight = 1.0,
        public ?float $max = null,
        public ?float $step = null,
    ) {
        if ($weight <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('A pane takes a share of the height above nought; "%s" states %s.', $title, $weight));
        }
    }
}
