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
 * THE SPARKLINE IS THE ATLAS'S. What is settled here is only the tone a
 * surface's line reads in, and that a history too short to be a line
 * draws none; the boxes, the points and the breaks are Sparkline's.
 */
final readonly class Figures
{
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

    /** The same reading, as the tone the atlas draws a line in. */
    public static function sparkTone(?float $delta, ColumnPolarity $polarity): SparkTone
    {
        return match (self::tone($delta, $polarity)) {
            'good' => SparkTone::Good,
            'bad' => SparkTone::Bad,
            default => SparkTone::Flat,
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
}
