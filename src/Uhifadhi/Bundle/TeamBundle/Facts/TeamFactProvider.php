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

namespace Uhifadhi\Bundle\TeamBundle\Facts;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Performance\StaffingTopic;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Service\PositionVacancy;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Facts\FigureDefinition;

/**
 * THE TEAM'S FIGURES THE WORKER FILES ON THE FACTS LEDGER, so the
 * performance pages read one stored row per figure instead of counting
 * every department's posts on every request.
 *
 * | key | subject | what |
 * |---|---|---|
 * | `staffing.positions` | department | the posts its members hold |
 * | `staffing.filled` | department | those somebody active stands in |
 * | `staffing.vacant` | department | those nobody active stands in |
 * | `staffing.people` | department | the active people standing in them |
 * | `staffing.over_threshold` | installation, area | posts empty for {@see StaffingTopic::THRESHOLD_DAYS} days or more |
 * | `staffing.vacant_undated` | installation, area | empty posts whose day of falling vacant nobody wrote down |
 *
 * NONE IS ADDITIVE. Each is the state of the establishment when the worker
 * last looked, so a quarter is its own row and never the sum of its months.
 *
 * THE THRESHOLD FIGURES ARE FILED PER SCOPE, NOT PER DEPARTMENT. A post is
 * a department's when one of its members holds it, so two departments can
 * share one; summing their counts would count it twice. A page is drawn for
 * the organization or for one area — whose departments are its own and the
 * organization-wide ones — and each of those is counted once, here.
 *
 * @see vendor/uhifadhi/uhifadhi/src/Uhifadhi/Contracts/docs/module-development.md — "Facts a module computes on a schedule"
 */
final readonly class TeamFactProvider implements FactProviderInterface
{
    /** What `uhifadhi:facts:rebuild --module=` names these figures by. */
    public const string SLUG = 'team';

    public const string OVER_THRESHOLD = 'staffing.over_threshold';
    public const string VACANT_UNDATED = 'staffing.vacant_undated';

    private const array STAFFING = [
        StaffingFigures::POSITIONS,
        StaffingFigures::FILLED,
        StaffingFigures::VACANT,
        StaffingFigures::PEOPLE,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DepartmentRepository $departments,
        private StaffingFigures $staffing,
        private DepartmentMembership $membership,
        private UserRepository $users,
        private PositionVacancy $vacancy,
        private ClockInterface $clock,
    ) {
    }

    public function moduleSlug(): string
    {
        return self::SLUG;
    }

    public function figures(): array
    {
        $figures = [];
        foreach (self::STAFFING as $key) {
            $figures[] = new FigureDefinition($key, FactSubject::DEPARTMENT, additive: false);
        }
        $figures[] = new FigureDefinition(self::OVER_THRESHOLD, FactSubject::AREA, additive: false);
        $figures[] = new FigureDefinition(self::VACANT_UNDATED, FactSubject::AREA, additive: false);

        return $figures;
    }

    public function compute(FactRequest $request): iterable
    {
        $departments = $this->departments->findAllActiveOrdered();

        foreach ($departments as $department) {
            $uuid = (string) $department->getUuidString();
            if (!$request->covers($uuid) || !$this->asksAny($request, self::STAFFING)) {
                continue;
            }
            foreach ($this->staffing->of($department) as $key => $value) {
                if ($request->asks($key)) {
                    yield new FactValue(FactSubject::DEPARTMENT, $uuid, $key, $value);
                }
            }
        }

        if (!$this->asksAny($request, [self::OVER_THRESHOLD, self::VACANT_UNDATED])) {
            return;
        }

        // A CLOSED PERIOD IS COUNTED AS OF ITS END, so its closing pass
        // files what was true on its last day.
        $now = $this->clock->now();
        $at = $request->period->until < $now ? $request->period->until : $now;

        $postsOf = [];
        foreach ($departments as $department) {
            $postsOf[(int) $department->getId()] = $this->membership->positionsIn($department);
        }

        if ($request->covers(FactSubject::INSTALLATION_UUID)) {
            yield from $this->thresholdFigures($request, FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID, $departments, $postsOf, $at);
        }

        foreach ($this->areas() as $area) {
            $uuid = (string) $area->getUuidString();
            if (!$request->covers($uuid)) {
                continue;
            }

            $reading = array_values(array_filter(
                $departments,
                static fn (Department $department): bool => null === $department->getArea() || $uuid === $department->getArea()->getUuidString(),
            ));

            yield from $this->thresholdFigures($request, FactSubject::AREA, $uuid, $reading, $postsOf, $at);
        }
    }

    /**
     * @param list<Department>           $departments the ones the scope reads
     * @param array<int, list<Position>> $postsOf     department id to the posts its members hold
     *
     * @return iterable<FactValue>
     */
    private function thresholdFigures(FactRequest $request, string $kind, string $uuid, array $departments, array $postsOf, \DateTimeImmutable $at): iterable
    {
        $empty = [];
        $undated = 0;
        $seen = [];
        foreach ($departments as $department) {
            foreach ($postsOf[(int) $department->getId()] ?? [] as $position) {
                if (isset($seen[(int) $position->getId()])) {
                    continue;
                }
                $seen[(int) $position->getId()] = true;

                $since = $position->getVacantSince();
                if (null !== $since) {
                    // A POST THAT FELL EMPTY AFTER THE MOMENT COUNTED was
                    // held at that moment.
                    if ($since <= $at) {
                        $empty[] = $position;
                    }
                } elseif (0 === $this->users->countActiveHoldingAnyPosition([$position])) {
                    // EMPTY SINCE BEFORE ANYBODY WROTE THE DAY DOWN — held
                    // posts carry no vacant-since either, and only whether
                    // somebody stands in it tells the two apart.
                    ++$undated;
                }
            }
        }

        if ($request->asks(self::OVER_THRESHOLD)) {
            yield new FactValue($kind, $uuid, self::OVER_THRESHOLD, (float) $this->vacancy->overThreshold($empty, StaffingTopic::THRESHOLD_DAYS, $at));
        }
        if ($request->asks(self::VACANT_UNDATED)) {
            yield new FactValue($kind, $uuid, self::VACANT_UNDATED, (float) $undated);
        }
    }

    /** @param list<string> $keys */
    private function asksAny(FactRequest $request, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($request->asks($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<AreaInterface> */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }
}
