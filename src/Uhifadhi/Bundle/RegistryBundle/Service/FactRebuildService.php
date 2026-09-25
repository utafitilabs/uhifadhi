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

namespace Uhifadhi\Bundle\RegistryBundle\Service;

use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactProviders;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactPeriodKind;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactValue;

/**
 * ASKING THE MODULES FOR THEIR FIGURES AND FILING THE ANSWERS — the one
 * place the facts ledger is written from.
 *
 * TWO CALLERS, ONE WAY OF ASKING. The operator's `uhifadhi:facts:rebuild`
 * asks for every month of a range; the schedule asks for the periods open
 * now. Either way a month asks for every figure a provider declared, and a
 * quarter or a year asks only for the figures that are not additive — the
 * others are read as the sum of their months and are never stored at that
 * length.
 *
 * IDEMPOTENT. A run files each figure with `INSERT … ON CONFLICT DO UPDATE`,
 * so the second run of a period replaces the first's rows; running it again
 * after a rule change is how the ledger is corrected.
 *
 * A CLOSED PERIOD IS NEVER RECOMPUTED BY THE SCHEDULE — what the performance
 * history holds for the same reason: records are edited, people move, and a
 * figure recomputed in November is not what August was. The one exception
 * is the CLOSING PASS: a period last computed before it ended (the evening
 * run on its last day) is computed once more by the first run after it
 * ends, so its final figure includes its final hours; from then on its rows
 * are dated after its end and the schedule leaves it alone. A closed period
 * nobody ever computed is the operator's to rebuild.
 *
 * "NOW" IS THE CLOCK'S, the framework's `clock` service, so a test fixes it
 * and every row of one run carries the same time.
 */
final readonly class FactRebuildService
{
    public function __construct(
        private FactProviders $providers,
        private FigureFactRepository $ledger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * EVERY MONTH OF A RANGE, then the quarters and years it touches, for
     * one module's providers or all of them.
     *
     * @param list<FactPeriod>                       $months   the range, oldest first
     * @param (callable(FactPeriod, int): void)|null $progress told each period and how many figures it filed
     *
     * @return int how many figures were filed
     *
     * @throws \InvalidArgumentException for a module that computes no facts
     */
    public function rebuild(array $months, ?string $moduleSlug = null, ?string $subjectUuid = null, ?callable $progress = null): int
    {
        $providers = $this->providersOf($moduleSlug);

        $longer = [];
        foreach ($months as $month) {
            foreach ([FactPeriod::quarter($month->from), FactPeriod::year($month->from)] as $period) {
                $longer[$period->key] = $period;
            }
        }

        $written = 0;
        foreach ($providers as $provider) {
            foreach ([...$months, ...array_values($longer)] as $period) {
                $filed = $this->compute($provider, $period, $subjectUuid);
                if (null === $filed) {
                    continue;
                }
                $written += $filed;
                if (null !== $progress) {
                    $progress($period, $filed);
                }
            }
        }

        return $written;
    }

    /**
     * THE SCHEDULED RUN: the month, quarter and year open now, for every
     * provider — and the closing pass for a period that has just ended.
     *
     * @return int how many figures were filed
     */
    public function recomputeOpen(): int
    {
        $now = $this->clock->now();
        $written = 0;

        foreach ($this->providers->all() as $provider) {
            foreach (FactPeriod::containing($now) as $open) {
                $closed = $open->previous();
                $last = $this->ledger->getLastComputedAt($this->keysFor($provider, $closed), $closed->key);

                if (null !== $last && $last < $closed->until) {
                    $written += $this->compute($provider, $closed, null) ?? 0;
                }

                $written += $this->compute($provider, $open, null) ?? 0;
            }
        }

        return $written;
    }

    /**
     * @return list<FactProviderInterface>
     */
    private function providersOf(?string $moduleSlug): array
    {
        $providers = $this->providers->all($moduleSlug);

        if (null !== $moduleSlug && [] === $providers) {
            throw new \InvalidArgumentException(\sprintf('No installed module named "%s" computes facts.', $moduleSlug));
        }

        return $providers;
    }

    /**
     * The figures a period asks a provider for: every one for a month, the
     * ones that do not add up for a quarter or a year.
     *
     * @return list<string>
     */
    private function keysFor(FactProviderInterface $provider, FactPeriod $period): array
    {
        $keys = [];
        foreach ($provider->figures() as $figure) {
            if (FactPeriodKind::Month === $period->kind || !$figure->additive) {
                $keys[] = $figure->key;
            }
        }

        return $keys;
    }

    /** @return int|null how many figures were filed, or null when the period asks this provider for nothing */
    private function compute(FactProviderInterface $provider, FactPeriod $period, ?string $subjectUuid): ?int
    {
        $keys = $this->keysFor($provider, $period);
        if ([] === $keys) {
            return null;
        }

        $request = new FactRequest($period, $keys, $subjectUuid);

        // ONLY WHAT WAS ASKED IS FILED. A provider that answers for a
        // figure or a subject outside the request would otherwise write
        // rows no run asked for and no rebuild of that subject replaces.
        $asked = array_filter(
            iterator_to_array($provider->compute($request), false),
            static fn (FactValue $value): bool => $request->asks($value->figureKey) && $request->covers($value->subjectUuid),
        );

        return $this->ledger->upsert($asked, $period->key, $this->clock->now());
    }
}
