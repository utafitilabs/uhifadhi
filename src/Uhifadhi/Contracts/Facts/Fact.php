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
 * A FIGURE AS THE LEDGER HOLDS IT: its value, and the instant it is true as of.
 *
 * `asOf` IS WHAT A PAGE PRINTS BESIDE THE FIGURE. For a period still open it
 * is when the figure was computed — "as of 13:00". A figure computed after
 * its period ended is that period's final figure and is true as of the
 * period's end, not as of the hour it happened to be written: such a fact is
 * {@see isFinal()}, and a page prints no "as of" beside it.
 *
 * A QUARTER OR YEAR READ AS THE SUM OF ITS MONTHS is as old as its oldest
 * month still open: a month that is final is true for good, so the sum is
 * true as of the earliest computation among the months that are not.
 */
final readonly class Fact
{
    public \DateTimeImmutable $asOf;

    /**
     * @param float|null $value null is UNKNOWN — the provider could not measure — never zero
     */
    public function __construct(
        public string $subjectKind,
        public string $subjectUuid,
        public string $figureKey,
        public string $periodKey,
        public ?float $value,
        public \DateTimeImmutable $computedAt,
        ?\DateTimeImmutable $asOf = null,
    ) {
        $until = $this->period()->until;
        $asOf ??= $computedAt;

        $this->asOf = $asOf < $until ? $asOf : $until;
    }

    public function period(): FactPeriod
    {
        return FactPeriod::fromKey($this->periodKey);
    }

    /** Whether the figure is its period's last word — written after the period ended. */
    public function isFinal(): bool
    {
        return $this->asOf >= $this->period()->until;
    }

    /** Whether there is a figure at all. A false here is an unknown, never a zero. */
    public function isKnown(): bool
    {
        return null !== $this->value;
    }
}
