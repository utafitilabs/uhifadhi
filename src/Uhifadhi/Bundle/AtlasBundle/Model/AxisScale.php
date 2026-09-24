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
 * THE TOP OF A VALUE AXIS AND THE DISTANCE BETWEEN ITS GRIDLINES, stated.
 *
 * THE RULE IS THE CALLER'S. Left alone, Chart.js picks its own ticks, and
 * they are fine for a run over time; a ranking wants its gridlines on whole
 * numbers and its top decided — "the smallest covering multiple of three,
 * never below three" is one module's rule, and another module may have
 * another. What the atlas owns is where the two numbers go.
 *
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/linear.html — `max` "overrides maximum value from data" (options.scales[scaleId]); `ticks.stepSize` "User-defined fixed step size for the scale"
 */
final readonly class AxisScale
{
    public function __construct(
        public float $max,
        public float $step,
    ) {
        if ($max <= 0.0 || $step <= 0.0 || $step > $max) {
            throw new \InvalidArgumentException(\sprintf('An axis runs from nought to a positive maximum in positive steps no wider than itself; max %s with step %s is not one.', $max, $step));
        }
    }

    /**
     * THE SMALLEST COVERING MULTIPLE. The top is the smallest multiple of
     * `$ticks` that still covers the largest value and is never below
     * `$ticks` itself, so every gridline lands on a whole number and an
     * empty month still has a width to measure against.
     */
    public static function covering(float $largest, int $ticks): self
    {
        if ($ticks < 1) {
            throw new \InvalidArgumentException(\sprintf('An axis has at least one gridline above nought; %d is not a count of them.', $ticks));
        }

        $max = max((float) $ticks, ceil($largest / $ticks) * $ticks);

        return new self($max, $max / $ticks);
    }
}
