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

namespace Uhifadhi\Bundle\TeamBundle\Performance;

use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Contracts\Performance\ColumnPolarity;

/**
 * HOW THIS PLATFORM PRINTS A FIGURE — once, for every surface that
 * prints one.
 *
 * THE MATRIX CELL AND THE TOPIC CARD SHOW THE SAME FIGURE, and a reader
 * moving between them must not have to work out whether "6.2" and "6"
 * are the same number. So the rounding, the thousands, the real minus
 * sign, the words for a figure that did not move and the tone a movement
 * reads in are settled here rather than in each surface.
 *
 * A TONE IS NEVER A COLOUR AND NEVER A SIGN. More days to settle is a
 * worse month; a page that painted every rise green would congratulate a
 * department for it.
 *
 * THE SPARKLINE HAS ITS OWN SIZE AT EVERY SURFACE and one rule. A matrix
 * cell's line is 70×18 and a topic card's is 100×26; what is shared is
 * that a period nobody wrote down BREAKS the line rather than dipping
 * it, and that every run is scaled against the whole history so two runs
 * of one series are on one scale.
 */
final readonly class Figures
{
    /** The matrix cell's line, as the design draws it. */
    public const float CELL_WIDTH = 70.0;
    public const float CELL_HEIGHT = 18.0;

    /** And the topic card's, which is wider and taller for the same history. */
    public const float CARD_WIDTH = 100.0;
    public const float CARD_HEIGHT = 26.0;

    /** How far the line stays clear of its box, so a peak is not clipped. */
    private const float INSET = 3.0;

    /** Thousands separated, a fraction kept to one place. */
    public static function figure(float $value): string
    {
        $whole = round($value) === round($value, 1);

        return number_format($value, $whole ? 0 : 1, '.', ',');
    }

    /** "+2", "−2" with a real minus sign, or the words for a figure that did not move. */
    public static function delta(?float $delta): string
    {
        if (null === $delta) {
            return '';
        }
        if (0.0 === $delta) {
            return 'no change';
        }

        $magnitude = number_format(abs($delta), abs($delta) === round(abs($delta)) ? 0 : 1, '.', ',');

        return ($delta < 0 ? "\u{2212}" : '+').$magnitude;
    }

    /** Which way a movement reads, according to the column and never to its sign. */
    public static function tone(?float $delta, ColumnPolarity $polarity): string
    {
        if (null === $delta) {
            return '';
        }
        if (0.0 === $delta) {
            return 'flat';
        }

        return match ($polarity->isGood($delta)) {
            true => 'good',
            false => 'bad',
            null => '',
        };
    }

    /** The same reading, in the two letters a line's class is written with. */
    public static function sparkTone(?float $delta, ColumnPolarity $polarity): string
    {
        return match (self::tone($delta, $polarity)) {
            'good' => 'up',
            'bad' => 'dn',
            default => 'fl',
        };
    }

    /**
     * THE HISTORY AS THE ATLAS DRAWS IT, or nothing where there is no line:
     * fewer than two readings is not a line, and a box with nothing in it
     * reads as a flat run.
     *
     * @param list<float|null> $history
     */
    public static function line(array $history, SparkTone $tone, SparkSize $size): ?Sparkline
    {
        $line = new Sparkline($history, $tone, $size);

        return $line->isEmpty() ? null : $line;
    }

    /**
     * THE HISTORY AS A LINE, WITH ITS HOLES LEFT OPEN. A period nobody
     * wrote down is not a nought on the line: the line stops there and
     * starts again after it, so the gap is something a reader can see
     * rather than a dip somebody measured.
     *
     * @param list<float|null> $history
     *
     * @return list<string> one polyline's points per unbroken run
     */
    public static function spark(array $history, float $width, float $height): array
    {
        $readings = array_values(array_filter($history, static fn (?float $point): bool => null !== $point));
        $count = \count($history);
        if (\count($readings) < 2 || $count < 2) {
            return [];
        }

        $low = min($readings);
        $high = max($readings);
        $range = $high - $low;
        $top = self::INSET;
        $bottom = $height - self::INSET;

        $runs = [];
        $run = [];
        foreach ($history as $index => $reading) {
            if (null === $reading) {
                if (\count($run) > 1) {
                    $runs[] = implode(' ', $run);
                }
                $run = [];

                continue;
            }

            $x = $width * $index / ($count - 1);
            // A flat series sits on the baseline rather than dividing by zero.
            $y = 0.0 === $range ? $bottom : $bottom - ($reading - $low) / $range * ($bottom - $top);
            $run[] = \sprintf('%.1f,%.1f', $x, $y);
        }

        if (\count($run) > 1) {
            $runs[] = implode(' ', $run);
        }

        return $runs;
    }
}
