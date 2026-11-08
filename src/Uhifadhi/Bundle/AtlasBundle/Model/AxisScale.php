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
 * THE TOP OF A VALUE AXIS AND THE DISTANCE BETWEEN ITS GRIDLINES, stated —
 * and its foot, where the axis does not stand on nought.
 *
 * THE RULE IS THE CALLER'S. Left alone, Chart.js picks its own ticks, and
 * they are fine for a run over time; a ranking wants its gridlines on whole
 * numbers and its top decided — "the smallest covering multiple of three,
 * never below three" is one module's rule, and another module may have
 * another. What the atlas owns is where the two numbers go.
 *
 * A FOOT IS STATED WHERE NOUGHT IS NOT ON THE AXIS: hours of a day from 0
 * to 24, a signal from -90 to -60 dBm, a log axis from 10. Unstated, the axis
 * stands on nought. A STEP MAY BE LEFT OUT where the axis rules itself — a
 * log axis draws a line at each power of ten.
 *
 * @see https://www.chartjs.org/docs/latest/axes/cartesian/linear.html — `max` "overrides maximum value from data" (options.scales[scaleId]); `min`; `ticks.stepSize` "User-defined fixed step size for the scale"
 */
final readonly class AxisScale
{
    public function __construct(
        public float $max,
        public ?float $step = null,
        public ?float $min = null,
    ) {
        $foot = $min ?? 0.0;
        if ($max <= $foot || (null !== $step && ($step <= 0.0 || $step > $max - $foot))) {
            throw new \InvalidArgumentException(\sprintf('An axis runs from its foot (nought, unless stated) up to its maximum in positive steps no wider than itself; %s to %s with step %s is not one.', $foot, $max, $step ?? 'none'));
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
