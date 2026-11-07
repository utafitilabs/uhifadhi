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

use Uhifadhi\Bundle\AtlasBundle\Model\Sparkline;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkSize;
use Uhifadhi\Bundle\AtlasBundle\Model\SparkTone;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentScopeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalDirectionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalStateEnum;
use Uhifadhi\Bundle\TeamBundle\Performance\Figures;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;

/**
 * WHAT A DEPARTMENT'S RECORD READS — the facts strip, the people who belong
 * to it and the people who support it, its figures at the area being read,
 * its goals as cards, what needs a decision, where the figures come from,
 * its history and the modules it runs.
 *
 * A DEPARTMENT COMPUTES NO NUMBER OF ITS OWN: every figure is a module's,
 * and a goal whose figure nobody publishes reads "no figure yet", never a
 * miss and never a zero.
 */
final readonly class DepartmentRecordService
{
    public function __construct(
        private UserRepository $users,
        private ModuleCatalogue $catalogue,
        private DepartmentGoalRepository $goals,
    ) {
    }

    /**
     * How each of a department's goals reads today, keyed by its uuid: the
     * figure its modules publish under the key it names, or none.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return array<string, GoalStateEnum>
     */
    public function goalStates(Department $department, array $kpis, \DateTimeImmutable $now): array
    {
        $published = [];
        foreach ($kpis as $kpi) {
            $published[$kpi->moduleSlug.'.'.$kpi->key] ??= $kpi->value;
        }

        $states = [];
        foreach ($this->goals->findForDepartment($department) as $goal) {
            $ref = $goal->getKpiRef();
            $states[(string) $goal->getUuidString()] = $goal->stateFor(null === $ref ? null : ($published[$ref] ?? null), $now);
        }

        return $states;
    }

    /**
     * The departments behind a goal — one at risk or off track — each with
     * the first such goal and what it reads.
     *
     * @param list<Department>                   $departments
     * @param array<string, list<DepartmentKpi>> $kpis        by department uuid
     *
     * @return list<array{department: Department, statement: string, now: string, target: string}>
     */
    public function behind(array $departments, array $kpis, \DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($departments as $department) {
            $figures = $kpis[(string) $department->getUuidString()] ?? [];
            $states = $this->goalStates($department, $figures, $now);
            $published = [];
            foreach ($figures as $kpi) {
                $published[$kpi->moduleSlug.'.'.$kpi->key] ??= $kpi->value;
            }
            foreach ($this->goals->findForDepartment($department) as $goal) {
                $state = $states[(string) $goal->getUuidString()] ?? null;
                if (\in_array($state, [GoalStateEnum::AtRisk, GoalStateEnum::Missed], true)) {
                    $unit = '' === $goal->getUnit() ? '' : ' '.$goal->getUnit();
                    $rows[] = [
                        'department' => $department,
                        'statement' => $goal->getStatement(),
                        'now' => self::number((float) ($published[(string) $goal->getKpiRef()] ?? 0.0)).$unit,
                        'target' => self::number($goal->getTarget()).$unit,
                    ];
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Everybody active whose work reaches the department: those who belong
     * to it, then those who support it, each by name.
     *
     * @return list<array{user: User, supports: bool}>
     */
    public function people(Department $department): array
    {
        $belong = $support = [];
        foreach ($this->users->findAllByName() as $user) {
            $placement = $user->getPlacement();
            if (!$user->isActive() || null === $placement) {
                continue;
            }
            if ($placement->belongsTo($department)) {
                $belong[] = ['user' => $user, 'supports' => false];
            } elseif ($placement->serves($department)) {
                $support[] = ['user' => $user, 'supports' => true];
            }
        }

        return [...$belong, ...$support];
    }

    /**
     * The facts strip: how many belong, how many support, how many of the
     * installed modules it runs.
     *
     * @param list<array{user: User, supports: bool}> $people
     *
     * @return array{belong: int, support: int, modules: int, installed: int}
     */
    public function facts(Department $department, array $people): array
    {
        $support = \count(array_filter($people, static fn (array $p): bool => $p['supports']));

        return [
            'belong' => \count($people) - $support,
            'support' => $support,
            'modules' => \count($this->modules($department, true)),
            'installed' => \count($this->catalogue->all()),
        ];
    }

    /**
     * Who is in it: every position its people hold, with how many hold it
     * and their initials; a position held only by supporters says so.
     *
     * @param list<array{user: User, supports: bool}> $people
     *
     * @return list<array{name: string, count: int, faces: list<string>, supports: bool}>
     */
    public function positions(array $people): array
    {
        $byName = [];
        foreach ($people as $person) {
            $name = (string) $person['user']->getPosition()?->getName();
            if ('' === $name) {
                continue;
            }
            $byName[$name] ??= ['name' => $name, 'count' => 0, 'faces' => [], 'supports' => true];
            ++$byName[$name]['count'];
            $byName[$name]['faces'][] = self::initials($person['user']->getFullName());
            $byName[$name]['supports'] = $byName[$name]['supports'] && $person['supports'];
        }
        ksort($byName);

        return array_values($byName);
    }

    /**
     * The figures at the area being read: every set when nothing is chosen,
     * the totals first; one area's own sets when one is.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<DepartmentKpi>
     */
    public function figures(array $kpis, ?string $areaName): array
    {
        if (null !== $areaName) {
            return array_values(array_filter($kpis, static fn (DepartmentKpi $kpi): bool => $kpi->areaName === $areaName));
        }
        usort($kpis, static fn (DepartmentKpi $a, DepartmentKpi $b): int => (int) $b->isTotal() <=> (int) $a->isTotal());

        return $kpis;
    }

    /**
     * The line under each figure, in the figures' order, as the atlas draws
     * it at the card's size and in the figure's tone; null where its history
     * holds fewer than two readings.
     *
     * @param list<DepartmentKpi> $figures
     *
     * @return list<Sparkline|null>
     */
    public function sparklines(array $figures): array
    {
        return array_map(static fn (DepartmentKpi $kpi): ?Sparkline => Figures::line($kpi->spark, match ($kpi->direction()) {
            'good' => SparkTone::Good,
            'bad' => SparkTone::Bad,
            default => SparkTone::Flat,
        }, SparkSize::Card), $figures);
    }

    /**
     * One card a goal: its bar against the target's tick, what it reads now
     * and the figure it reads.
     *
     * @param list<DepartmentGoal>         $goals
     * @param array<string, GoalStateEnum> $states
     * @param list<DepartmentKpi>          $kpis
     *
     * @return list<array{statement: string, state: GoalStateEnum, tone: string, chip: string, fill: int, tick: ?int, target: string, owner: ?string, reads: string}>
     */
    public function goals(array $goals, array $states, array $kpis): array
    {
        $published = [];
        foreach ($kpis as $kpi) {
            if ($kpi->isTotal() || !isset($published[$kpi->moduleSlug.'.'.$kpi->key])) {
                $published[$kpi->moduleSlug.'.'.$kpi->key] = $kpi;
            }
        }

        $cards = [];
        foreach ($goals as $goal) {
            $state = $states[(string) $goal->getUuidString()] ?? GoalStateEnum::NoFigure;
            $kpi = $published[(string) $goal->getKpiRef()] ?? null;
            $value = $kpi?->value;
            $target = $goal->getTarget();
            $unit = '' === $goal->getUnit() ? '' : ' '.$goal->getUnit();
            $sign = GoalDirectionEnum::AtLeast === $goal->getDirection() ? '≥' : '≤';
            $closes = $goal->getClosesAt()?->format('j M Y');
            $measured = null !== $value && GoalStateEnum::NoFigure !== $state;
            $fill = $tick = null;
            if ($measured && $target > 0) {
                $fill = (int) round(min(100.0, $value / $target * 100));
                $tick = $value <= $target ? 100 : (int) round($target / $value * 100);
            }

            $cards[] = [
                'statement' => $goal->getStatement(),
                'state' => $state,
                'tone' => match ($state) {
                    GoalStateEnum::Met => 'ontrack',
                    GoalStateEnum::AtRisk => 'atrisk',
                    GoalStateEnum::Missed => 'offtrack',
                    default => 'blocked',
                },
                'chip' => match ($state) {
                    GoalStateEnum::Met => 'ok',
                    GoalStateEnum::AtRisk => 'warn',
                    GoalStateEnum::Missed => 'fail',
                    default => 'idle',
                },
                'fill' => $fill ?? 100,
                'tick' => $tick,
                'target' => \sprintf(
                    '%s · target %s %s%s%s',
                    $measured ? self::number((float) $value).$unit.' now' : 'not measured yet',
                    $sign,
                    self::number($target),
                    $unit,
                    null === $closes ? '' : ' · closes '.$closes,
                ),
                'owner' => $goal->getOwner()?->getName(),
                'reads' => null === $kpi
                    ? (null === $goal->getKpiRef() ? 'reads no figure' : 'reads '.$goal->getKpiRef().' — no attached module publishes it')
                    : 'reads '.$kpi->moduleName.' · '.mb_strtolower($kpi->label),
            ];
        }

        return $cards;
    }

    /**
     * What needs a decision: every goal off track or at risk, one line
     * each, then the goals nobody can measure, as one line.
     *
     * @param list<array{statement: string, state: GoalStateEnum, target: string}> $cards
     *
     * @return list<array{head: string, cause: string, door: string, url: string}>
     */
    public function attention(array $cards, string $configureUrl): array
    {
        $lines = [];
        foreach ($cards as $card) {
            if (\in_array($card['state'], [GoalStateEnum::Missed, GoalStateEnum::AtRisk], true)) {
                $lines[] = [
                    'head' => $card['statement'].(GoalStateEnum::Missed === $card['state'] ? ' — off track' : ' — at risk'),
                    'cause' => $card['target'],
                    'door' => 'Goals',
                    'url' => '#dp-goals',
                ];
            }
        }

        $unmeasured = \count(array_filter($cards, static fn (array $c): bool => GoalStateEnum::NoFigure === $c['state']));
        if ($unmeasured > 0) {
            $lines[] = [
                'head' => \sprintf('%d %s cannot be measured yet', $unmeasured, 1 === $unmeasured ? 'goal' : 'goals'),
                'cause' => 'no attached module publishes '.(1 === $unmeasured ? 'its figure' : 'their figures'),
                'door' => 'Modules',
                'url' => $configureUrl,
            ];
        }

        return $lines;
    }

    /**
     * Where the figures come from: one row an attached module, with how
     * many of the figures on the page it feeds and in how many areas.
     *
     * @param list<DepartmentKpi> $kpis
     *
     * @return list<array{name: string, figures: int, areas: int}>
     */
    public function sources(Department $department, array $kpis): array
    {
        $rows = [];
        foreach ($this->modules($department, true) as $module) {
            $own = array_filter($kpis, static fn (DepartmentKpi $kpi): bool => $kpi->moduleSlug === $module['slug']);
            $areas = array_unique(array_filter(array_map(static fn (DepartmentKpi $kpi): ?string => $kpi->areaName, $own)));
            $keys = array_unique(array_map(static fn (DepartmentKpi $kpi): string => $kpi->key, $own));
            $rows[] = ['name' => $module['name'], 'figures' => \count($keys), 'areas' => \count($areas)];
        }

        return $rows;
    }

    /**
     * The installed modules and whether the department runs each, by name.
     *
     * @return list<array{slug: string, name: string, description: string, on: bool}>
     */
    public function modules(Department $department, bool $onlyOn = false): array
    {
        $rows = [];
        foreach ($this->catalogue->all() as $module) {
            $on = $department->hasModule($module);
            if ($onlyOn && !$on) {
                continue;
            }
            $rows[] = [
                'slug' => (string) $module->getSlug(),
                'name' => (string) $module->getName(),
                'description' => (string) $module->getDescription(),
                'on' => $on,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * What happened to it, newest first: its scope changes, then its
     * creation.
     *
     * @return list<array{at: \DateTimeImmutable, text: string, by: ?string}>
     */
    public function history(Department $department): array
    {
        $rows = [];
        foreach ($department->getScopeChanges() as $change) {
            $rows[] = [
                'at' => $change->getRecordedAt(),
                'text' => DepartmentScopeEnum::Area === $change->getToScope()
                    ? 'Confined to '.($change->getArea()?->getName() ?? 'one area')
                    : 'Made organization-wide',
                'by' => $change->getChangedBy()?->getFullName(),
            ];
        }
        $created = $department->getCreatedAt();
        if (null !== $created) {
            $rows[] = ['at' => $created, 'text' => 'Department created', 'by' => null];
        }
        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $rows;
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), \array_slice($words, 0, 2))));
    }

    private static function number(float $value): string
    {
        return floor($value) === $value ? number_format($value) : number_format($value, 1);
    }
}
