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

namespace Uhifadhi\Bundle\RegistryBundle\Facts;

use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactPeriodKind;
use Uhifadhi\Contracts\Facts\FactReaderInterface;

/**
 * THE FACTS LEDGER, READ: what a page asks instead of computing.
 *
 * A MONTH, AND ANY FIGURE THAT IS NOT ADDITIVE, IS ONE ROW. A quarter or a
 * year of an ADDITIVE figure is never stored: it is the sum of its months,
 * at most twelve rows, which is a bounded read however long the installation
 * has been running.
 *
 * THE SUM RUNS FROM THE PERIOD'S FIRST MONTH AND STOPS AT THE FIRST MONTH
 * NOBODY COMPUTED. A quarter with its middle month missing is not presented
 * as the quarter: it is the months up to the gap, and its time says so,
 * because a sum is as old as its oldest month still open ({@see Fact::$asOf}). A month
 * whose figure is unknown makes the sum unknown — a total over a month
 * nobody measured is not a figure. A quarter whose first month was never
 * computed has no figure at all.
 */
final readonly class FactReader implements FactReaderInterface
{
    public function __construct(
        private FigureFactRepository $ledger,
        private FactProviders $providers,
    ) {
    }

    public function latest(string $subjectKind, string $subjectUuid, string $figureKey, string $periodKey): ?Fact
    {
        return $this->batch($subjectKind, [$subjectUuid], [$figureKey], $periodKey)[$subjectUuid][$figureKey] ?? null;
    }

    public function batch(string $subjectKind, array $subjectUuids, array $figureKeys, string $periodKey): array
    {
        $period = FactPeriod::fromKey($periodKey);

        $facts = [];
        foreach ($this->ledger->findBySubjects($subjectKind, $subjectUuids, $figureKeys, [$period->key]) as $fact) {
            $facts[$fact->subjectUuid][$fact->figureKey] = $fact;
        }

        if (FactPeriodKind::Month === $period->kind) {
            return $facts;
        }

        $additive = array_values(array_filter(
            $figureKeys,
            fn (string $key): bool => true === $this->providers->definition($key)?->additive,
        ));

        if ([] === $additive) {
            return $facts;
        }

        $months = $period->months();
        $monthly = [];
        foreach ($this->ledger->findBySubjects($subjectKind, $subjectUuids, $additive, array_map(static fn (FactPeriod $m): string => $m->key, $months)) as $fact) {
            $monthly[$fact->subjectUuid][$fact->figureKey][$fact->periodKey] = $fact;
        }

        foreach ($monthly as $subject => $figures) {
            foreach ($figures as $figure => $byMonth) {
                if (isset($facts[$subject][$figure])) {
                    continue;
                }

                $composed = $this->compose($period, $months, $byMonth);
                if (null !== $composed) {
                    $facts[$subject][$figure] = $composed;
                }
            }
        }

        return $facts;
    }

    /**
     * @param list<FactPeriod>    $months
     * @param array<string, Fact> $byMonth month key to its row
     */
    private function compose(FactPeriod $period, array $months, array $byMonth): ?Fact
    {
        $used = [];
        foreach ($months as $month) {
            if (!isset($byMonth[$month->key])) {
                break;
            }
            $used[] = $byMonth[$month->key];
        }

        if ([] === $used) {
            return null;
        }

        $sum = 0.0;
        $known = true;
        $computedAt = $used[0]->computedAt;

        // A FINAL MONTH IS TRUE FOR GOOD; a month still open (or one never
        // written after it closed) is true only as of its computation. The sum
        // is as old as its oldest such month, and when every month it read is
        // final it is true to the end of the last of them.
        $asOf = null;

        foreach ($used as $fact) {
            if (null === $fact->value) {
                $known = false;
            } else {
                $sum += $fact->value;
            }
            $computedAt = max($computedAt, $fact->computedAt);
            if (!$fact->isFinal()) {
                $asOf = null === $asOf ? $fact->asOf : min($asOf, $fact->asOf);
            }
        }

        $asOf ??= $used[\count($used) - 1]->asOf;

        return new Fact(
            $used[0]->subjectKind,
            $used[0]->subjectUuid,
            $used[0]->figureKey,
            $period->key,
            $known ? $sum : null,
            $computedAt,
            $asOf,
        );
    }
}
