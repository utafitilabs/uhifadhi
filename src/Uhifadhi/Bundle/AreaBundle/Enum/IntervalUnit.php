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

namespace Uhifadhi\Bundle\AreaBundle\Enum;

/**
 * THE UNIT AN INTERVAL IS TYPED IN. A setting that is a stretch of time is a
 * number somebody typed and a unit they picked, and it is stored as whole
 * minutes: the unit is how it is said, never what is kept.
 *
 * OFFERED BACK IN THE LARGEST UNIT IT IS A WHOLE COUNT OF, so 120 reads as
 * "2 hours" and 90 as "90 minutes" rather than "1.5 hours" — the form shows
 * what was typed whenever what was typed was a whole number.
 */
enum IntervalUnit: string
{
    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';

    /** The word the select prints. */
    public function label(): string
    {
        return $this->value;
    }

    public function minutes(): int
    {
        return match ($this) {
            self::Minutes => 1,
            self::Hours => 60,
            self::Days => 1440,
        };
    }

    /** A typed pair as whole minutes, rounded to the nearest one. */
    public function toMinutes(float $count): int
    {
        return (int) round($count * $this->minutes());
    }

    /** The largest unit this many minutes is a whole count of. */
    public static function largestWholeFor(int $minutes): self
    {
        foreach ([self::Days, self::Hours] as $unit) {
            if ($minutes > 0 && 0 === $minutes % $unit->minutes()) {
                return $unit;
            }
        }

        return self::Minutes;
    }

    /** This many minutes, said in that unit: "30 minutes", "1 hour", "2 hours". */
    public static function say(int $minutes): string
    {
        $unit = self::largestWholeFor($minutes);
        $count = intdiv($minutes, $unit->minutes());

        return \sprintf('%d %s', $count, 1 === $count ? rtrim($unit->value, 's') : $unit->value);
    }
}
