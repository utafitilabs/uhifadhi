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

namespace Uhifadhi\Contracts\Facts;

/**
 * THE PERIOD A FACT IS FILED UNDER — a calendar month, quarter or year, named
 * by a sortable key: `2026-09`, `2026-Q3`, `2026`.
 *
 * THIS IS WHERE THOSE KEYS ARE MINTED, for the ledger and for the performance
 * history alike, so a page cannot ask for `2026-q3` while the ledger wrote
 * `2026-Q3`. Half-open, like every window in the platform: a month is every
 * instant from its first to the first of the next.
 *
 * THE BOUNDARIES ARE IN THE ZONE OF THE INSTANT IT IS BUILT FROM, and a key is
 * read in PHP's default zone — the installation's. A month ends at midnight
 * where the installation works, not at midnight in Greenwich.
 */
final readonly class FactPeriod
{
    private function __construct(
        public FactPeriodKind $kind,
        public string $key,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
    ) {
    }

    public static function month(\DateTimeImmutable $when): self
    {
        $from = $when->modify('first day of this month')->setTime(0, 0);

        return new self(FactPeriodKind::Month, $from->format('Y-m'), $from, $from->modify('+1 month'));
    }

    /** The calendar quarter, not ninety days. */
    public static function quarter(\DateTimeImmutable $when): self
    {
        $quarter = intdiv((int) $when->format('n') - 1, 3);
        $from = $when->setDate((int) $when->format('Y'), $quarter * 3 + 1, 1)->setTime(0, 0);

        return new self(FactPeriodKind::Quarter, \sprintf('%s-Q%d', $from->format('Y'), $quarter + 1), $from, $from->modify('+3 months'));
    }

    public static function year(\DateTimeImmutable $when): self
    {
        $from = $when->setDate((int) $when->format('Y'), 1, 1)->setTime(0, 0);

        return new self(FactPeriodKind::Year, $from->format('Y'), $from, $from->modify('+1 year'));
    }

    /**
     * THE PERIODS AN INSTANT IS OPEN IN, month first — what the schedule
     * recomputes.
     *
     * @return list<self>
     */
    public static function containing(\DateTimeImmutable $when): array
    {
        return [self::month($when), self::quarter($when), self::year($when)];
    }

    /**
     * A KEY, READ BACK AS ITS PERIOD.
     *
     * @throws \InvalidArgumentException for a key that names no period
     */
    public static function fromKey(string $key, ?\DateTimeZone $zone = null): self
    {
        $zone ??= new \DateTimeZone(date_default_timezone_get());

        if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m)) {
            return self::month(new \DateTimeImmutable(\sprintf('%s-%s-01', $m[1], $m[2]), $zone));
        }
        if (1 === preg_match('/^(\d{4})-Q([1-4])$/', $key, $m)) {
            return self::quarter(new \DateTimeImmutable(\sprintf('%s-%02d-01', $m[1], ((int) $m[2] - 1) * 3 + 1), $zone));
        }
        if (1 === preg_match('/^\d{4}$/', $key)) {
            return self::year(new \DateTimeImmutable($key.'-01-01', $zone));
        }

        throw new \InvalidArgumentException(\sprintf('"%s" names no period: a month is 2026-09, a quarter 2026-Q3, a year 2026.', $key));
    }

    /**
     * THE MONTHS FROM ONE INSTANT'S TO ANOTHER'S, both included, oldest
     * first — the range a rebuild walks.
     *
     * @return list<self>
     */
    public static function monthsBetween(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $month = self::month($from);
        $last = self::month($until);

        if ($last->from < $month->from) {
            throw new \InvalidArgumentException(\sprintf('The range ends (%s) before it starts (%s).', $last->key, $month->key));
        }

        $months = [];
        while ($month->from <= $last->from) {
            $months[] = $month;
            $month = self::month($month->until);
        }

        return $months;
    }

    /**
     * THE MONTHS THIS PERIOD IS MADE OF, oldest first — what an additive
     * figure's quarter or year is summed from. A month is made of itself.
     *
     * @return list<self>
     */
    public function months(): array
    {
        return self::monthsBetween($this->from, $this->until->modify('-1 day'));
    }

    /** The period before this one, of the same kind. */
    public function previous(): self
    {
        $before = $this->from->modify('-1 day');

        return match ($this->kind) {
            FactPeriodKind::Month => self::month($before),
            FactPeriodKind::Quarter => self::quarter($before),
            FactPeriodKind::Year => self::year($before),
        };
    }

    public function hasEndedAt(\DateTimeImmutable $when): bool
    {
        return $when >= $this->until;
    }
}
