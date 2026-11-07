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

use Uhifadhi\Contracts\Kpi\FigurePeriod;

/**
 * WHAT THE PERIOD IS READ AGAINST — the period before it, or the same
 * period a year ago.
 *
 * TWO, AND THE DESIGN'S THIRD IS NOT ONE OF THEM. "The declared target"
 * is not a period at all: it compares a figure with what somebody
 * committed to, which is a goal's business and is already answered in
 * the ledger's Pace column. Offering it here would put two different
 * kinds of comparison behind one control and make "up 3" mean two
 * things on one page.
 *
 * IT IS IN THE ADDRESS, like the scope and the period, so the page a
 * reader is looking at can be sent to somebody.
 */
enum Comparison: string
{
    case Previous = 'previous';
    case LastYear = 'last-year';

    public static function fromRequest(string $value): self
    {
        return self::tryFrom($value) ?? self::Previous;
    }

    /** The period this comparison means, for the period being read. */
    public function of(FigurePeriod $period): FigurePeriod
    {
        return match ($this) {
            self::Previous => $period->previous(),
            self::LastYear => $period->sameLastYear(),
        };
    }

    /** What the control calls it, with the period it works out to. */
    public function label(FigurePeriod $period): string
    {
        return match ($this) {
            self::Previous, self::LastYear => 'Against '.$this->of($period)->label,
        };
    }
}
