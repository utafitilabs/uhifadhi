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
 * A MODULE THAT COMPUTES FIGURES OVER A GROWING SET PUBLISHES THEM HERE.
 *
 * THE RULE THIS SEAM EXISTS FOR: a request never computes over a set that
 * grows with time or headcount. Coverage of a zone this month, metres walked
 * this quarter — anything that reads every track, every ping, every record of
 * a period — is computed by the worker on a schedule, filed on the facts
 * ledger, and read by a page as a stored number with the time it was
 * computed. When the worker lags, the page shows the last fact and its time;
 * it never computes, and it never fails.
 *
 * WHO CALLS THIS. Two callers, both the core's:
 *  - the schedule, every hour of the working day and once at night, asks
 *    for the periods open now (the month, the quarter, the year), and once
 *    more for a period that has just closed so its final figure is written;
 *  - `uhifadhi:facts:rebuild`, run by an operator after a rule changes — a
 *    zone redrawn, a buffer width changed — asks for every month in a range.
 * A closed period is never recomputed by the schedule.
 *
 * WHAT A PROVIDER DOES: {@see figures()} declares what it computes, once;
 * {@see compute()} answers one period and returns values. It WRITES NOTHING:
 * the core files what it returns, stamps the time, and replaces the row a
 * second run of the same period wrote — so a run is idempotent and a
 * provider never touches the ledger's table.
 *
 * `compute()` RUNS IN THE WORKER, not in a request: it may take minutes, and
 * it should still read the period and nothing wider — a month's figure reads
 * a month's records.
 *
 * TAG IT BY HAND, in your own extension, with {@see TAG}, because a reusable
 * bundle's services are not autoconfigured; a service in an application
 * carries `#[AutoconfigureTag(FactProviderInterface::TAG)]` on its own class,
 * because Symfony reads autoconfigure attributes off the definition's class
 * and PHP does not inherit them from an interface.
 */
interface FactProviderInterface
{
    public const string TAG = 'uhifadhi.facts';

    /** The module that computes these figures — what `--module=` selects. */
    public function moduleSlug(): string;

    /**
     * EVERY FIGURE THIS PROVIDER COMPUTES, and whether a quarter of it is the
     * sum of its months.
     *
     * @return list<FigureDefinition>
     */
    public function figures(): array;

    /**
     * THE FIGURES ASKED FOR, IN THE PERIOD ASKED ABOUT, for every subject the
     * request covers. A subject the provider has no figure for is left out;
     * a subject it looked at and could not measure is returned with a null
     * value, which a page draws as unknown rather than as a nought.
     *
     * @return iterable<FactValue>
     */
    public function compute(FactRequest $request): iterable;
}
