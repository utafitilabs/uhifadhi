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
 * ONE ITEM OF A CHART OF TWO OR THREE MEASURES: where it sits on each axis,
 * and on a bubble chart its third measure, drawn as the bubble's area.
 */
final readonly class ChartPoint
{
    public function __construct(
        public float $x,
        public float $y,
        public ?float $size = null,
    ) {
        if (null !== $size && $size < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A bubble is sized by a measure of nought or more; %s is not one.', $size));
        }
    }
}
