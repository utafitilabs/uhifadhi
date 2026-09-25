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
 * A FIGURE'S RECENT HISTORY, DRAWN AS A LINE UNDER IT.
 *
 * THE CALLER STATES THE HISTORY AND WHAT ITS MOVEMENT MEANS; where each
 * point lands in the box is the atlas's. Every run is scaled against the
 * whole history, so two runs of one series are on one scale.
 *
 * A PERIOD NOBODY WROTE DOWN BREAKS THE LINE rather than dipping it: the
 * line stops at the hole and starts again after it, so the gap is something
 * a reader sees rather than a nought somebody measured. A lone reading
 * between two holes is no line at all.
 */
final readonly class Sparkline
{
    /** How far the line stays clear of the top and the bottom of its box, so a peak is not clipped. */
    public const float INSET = 3.0;

    /**
     * @param list<float|null> $history oldest first, one per period; null where nobody wrote one down
     */
    public function __construct(
        public array $history,
        public SparkTone $tone = SparkTone::Flat,
        public SparkSize $size = SparkSize::Card,
    ) {
    }

    /** Fewer than two readings is not a line. */
    public function isEmpty(): bool
    {
        return [] === $this->runs();
    }

    /**
     * THE LINE, ONE POLYLINE'S POINTS PER UNBROKEN RUN, in the box's own
     * coordinates.
     *
     * @return list<string>
     */
    public function runs(): array
    {
        $readings = array_values(array_filter($this->history, static fn (?float $point): bool => null !== $point));
        $count = \count($this->history);
        if (\count($readings) < 2) {
            return [];
        }

        $low = min($readings);
        $range = max($readings) - $low;
        $width = $this->size->width();
        $top = self::INSET;
        $bottom = $this->size->height() - self::INSET;

        $runs = [];
        $run = [];
        foreach ($this->history as $index => $reading) {
            if (null === $reading) {
                if (\count($run) > 1) {
                    $runs[] = implode(' ', $run);
                }
                $run = [];

                continue;
            }

            $x = $width * $index / ($count - 1);
            // A flat series sits on the baseline rather than dividing by nothing.
            $y = 0.0 === $range ? $bottom : $bottom - ($reading - $low) / $range * ($bottom - $top);
            $run[] = \sprintf('%.1f,%.1f', $x, $y);
        }

        if (\count($run) > 1) {
            $runs[] = implode(' ', $run);
        }

        return $runs;
    }
}
