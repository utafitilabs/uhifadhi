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
 * A ONE-HUE SEQUENTIAL RAMP for a magnitude on a grid: nought in the grid
 * ink, then one step of the hue per band of values. The breaks are the top
 * of each band but the last — 2, 4, 6, 8 makes 1–2, 3–4, 5–6, 7–8 and 9 or
 * more — and the legend names each band.
 */
final readonly class ChartRamp
{
    /** The steps of the hue, nought aside. */
    public const int STEPS = 5;

    /**
     * @param list<float> $breaks the top of each band but the last, rising
     * @param string|null $token  the hue, a token; null is the house's sequential blue
     */
    public function __construct(
        public array $breaks,
        public ?string $token = null,
    ) {
        if (self::STEPS - 1 !== \count($breaks)) {
            throw new \InvalidArgumentException(\sprintf('A ramp states %d breaks, one between each of its %d steps.', self::STEPS - 1, self::STEPS));
        }
    }
}
