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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;

/**
 * WHAT A DEPARTMENT'S PERFORMANCE SURFACES SHOW — its attached modules' figures,
 * and nothing else, because a department computes no number of its own.
 *
 * THE ATTACHED SET IS THE FILTER, and it is the whole of the filter. A provider
 * is asked only when the department attaches the module whose slug it names, so a
 * detached module's plate LEAVES the page rather than going to zero — the
 * distinction the KPI contract is built around, and the reason the strip is
 * honest about a module a department does not run.
 *
 * THE DEPARTMENT GOES OUT AS A REF, NEVER AS THIS BUNDLE'S ENTITY
 * ({@see DepartmentRef}). Resolving it here is what keeps a module that reports a
 * figure from depending on the team package: a provider is handed the id its rows
 * are filed under, the uuid a URL names, the name a plate prints, and the area an
 * area-level department is confined to — the scope, without which a provider
 * cannot know whether it is being asked about one area or the organization.
 *
 * `$now` IS PASSED IN, not read, so a period is a parameter and a page is
 * testable — the same arrangement the contract asks of every provider.
 *
 * THE LEDGER BEFORE THE LIVE ANSWER — the transition rule. A figure the
 * worker has filed on the facts ledger for the department, as
 * `<module>.<key>` in the month `$now` falls in, is read from there: its
 * value, its time ({@see DepartmentKpi::$asOf}), and the month before's as
 * the comparison when that was filed too. A figure with no fact keeps the
 * module's live answer. So a module moves its figures onto the ledger one
 * at a time — declare it through a fact provider, and stop computing it in
 * `kpisFor()` (answer it as unknown) once the worker files it — and no
 * page changes until the first run. One read per department, for every
 * figure at once.
 */
final readonly class DepartmentPerformance
{
    /**
     * @param iterable<DepartmentKpiProviderInterface> $providers every tagged provider, in registration order
     */
    public function __construct(
        private iterable $providers = [],
        private ?FactReaderInterface $facts = null,
    ) {
    }

    /**
     * The figures for one department, in the order its attached modules appear in
     * the catalogue — so the strip reads in the same order as the cards above it.
     *
     * An empty list is a legitimate answer twice over: a department with nothing
     * attached, and an attached module with nothing to report this period.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(Department $department, ?\DateTimeImmutable $now = null): array
    {
        $attached = [];
        foreach ($department->getModules() as $position => $module) {
            $attached[(string) $module->getSlug()] = $position;
        }

        if ([] === $attached) {
            return [];
        }

        // THE SCOPE TRAVELS WITH THE REF. An area-level department names the area
        // it is confined to, and an org-level one names none — the contract's word
        // for "roll up every area". Without it a provider is asked a question with
        // no scope in it and answers with every area's figures at once.
        $ref = new DepartmentRef(
            (int) $department->getId(),
            (string) $department->getName(),
            $department->getUuidString(),
            $department->getArea()?->getUuidString(),
        );
        $now ??= new \DateTimeImmutable();

        $byModule = [];
        foreach ($this->providers as $provider) {
            $slug = $provider->moduleSlug();
            if (!isset($attached[$slug])) {
                continue;
            }

            foreach ($provider->kpisFor($ref, $now) as $kpi) {
                $byModule[$attached[$slug]][] = $kpi;
            }
        }

        ksort($byModule);

        $kpis = [];
        foreach ($byModule as $group) {
            foreach ($group as $kpi) {
                $kpis[] = $kpi;
            }
        }

        return $this->fromLedger($kpis, $department->getUuidString(), $now);
    }

    /**
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<DepartmentKpi>
     */
    private function fromLedger(array $kpis, ?string $department, \DateTimeImmutable $now): array
    {
        if (null === $this->facts || null === $department || [] === $kpis) {
            return $kpis;
        }

        $keys = array_values(array_unique(array_map(static fn (DepartmentKpi $kpi): string => $kpi->moduleSlug.'.'.$kpi->key, $kpis)));
        $month = FactPeriod::month($now);

        $filed = $this->facts->batch(FactSubject::DEPARTMENT, [$department], $keys, $month->key)[$department] ?? [];
        if ([] === $filed) {
            return $kpis;
        }
        $before = $this->facts->batch(FactSubject::DEPARTMENT, [$department], $keys, $month->previous()->key)[$department] ?? [];

        return array_map(static function (DepartmentKpi $kpi) use ($filed, $before): DepartmentKpi {
            $key = $kpi->moduleSlug.'.'.$kpi->key;
            $fact = $kpi->isTotal() ? ($filed[$key] ?? null) : null;
            if (!$fact instanceof Fact) {
                return $kpi;
            }

            return new DepartmentKpi(
                key: $kpi->key,
                label: $kpi->label,
                moduleSlug: $kpi->moduleSlug,
                moduleName: $kpi->moduleName,
                value: $fact->value,
                unit: $kpi->unit,
                previous: isset($before[$key]) ? $before[$key]->value : $kpi->previous,
                spark: $kpi->spark,
                caption: $kpi->caption,
                areaName: $kpi->areaName,
                asOf: $fact->isFinal() ? null : $fact->asOf,
            );
        }, $kpis);
    }
}
