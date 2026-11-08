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
 * ONE AXIS OF A CHART OF TWO MEASURES, stated: what it measures (written
 * along it), its unit (in the hover), and where the caller has a rule its
 * least, its most and its step.
 *
 * The x and y of a scatter or a bubble; the right-hand axis of a chart of
 * two measures; the title of a bar chart's value axis.
 */
final readonly class ChartAxis
{
    public function __construct(
        public string $title = '',
        public string $unit = '',
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
    ) {
        if (null !== $min && null !== $max && $min >= $max) {
            throw new \InvalidArgumentException(\sprintf('An axis runs upward; "%s" runs from %s to %s.', $title, $min, $max));
        }
        if (null !== $step && $step <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('An axis steps by a positive amount; "%s" steps by %s.', $title, $step));
        }
    }
}
