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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\FakeTopicProvider;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\TopicKpi;

/**
 * WHICH TOPICS A PERFORMANCE PAGE HAS, AND IN WHICH ORDER.
 *
 * THE HOST'S FIRST, THEN THE MODULES' IN THE ORDER SOMEBODY ARRANGED. The
 * host's topics are figures every department has whatever it attaches, so
 * they lead; a module's follows, in the organization's own module order.
 * Alphabetical would be the dictionary's opinion about an organization's
 * priorities, and tag order is the order the container happened to build
 * services in.
 *
 * A MODULE NOBODY RUNS PUBLISHES NOTHING HERE. A topic from a module this
 * installation does not have — or, in an area's scope, one that area does
 * not run — is not an empty section: it is not a section.
 */
#[CoversClass(PerformanceTopics::class)]
final class PerformanceTopicsTest extends IntegrationTestCase
{
    public function testTheHostsTopicsLeadAndTheModulesFollowInTheirOwnOrder(): void
    {
        $this->aCatalogue(['incidents' => 0, 'patrols' => 1]);

        self::assertSame(
            ['staffing', 'goals', 'attention', 'incidents', 'patrols'],
            $this->keysOf($this->topics()->forScope(PerformanceScope::organization(), self::period())),
        );
    }

    /** Rearranged, the page reads the new order. */
    public function testTheOrderFollowsTheOrganizationsOwnArrangement(): void
    {
        $this->aCatalogue(['patrols' => 0, 'incidents' => 1]);

        self::assertSame(
            ['staffing', 'goals', 'attention', 'patrols', 'incidents'],
            $this->keysOf($this->topics()->forScope(PerformanceScope::organization(), self::period())),
        );
    }

    /** A module this installation does not carry has no topic. */
    public function testAModuleTheInstallationDoesNotHaveHasNoTopic(): void
    {
        $this->aCatalogue(['patrols' => 0]);

        self::assertSame(
            ['staffing', 'goals', 'attention', 'patrols'],
            $this->keysOf($this->topics()->forScope(PerformanceScope::organization(), self::period())),
        );
    }

    /** One topic by its own key, for the topic's own page. */
    public function testATopicIsFoundByItsKey(): void
    {
        $this->aCatalogue(['patrols' => 0]);

        $topic = $this->topics()->byKey('patrols', PerformanceScope::organization(), self::period());

        self::assertNotNull($topic);
        self::assertSame('Patrols', $topic->title());
        self::assertNull($this->topics()->byKey('nothing', PerformanceScope::organization(), self::period()));
    }

    /**
     * FIVE FIGURES, ALWAYS. The contract says a topic publishes five and
     * the collector is where that is checked, once, rather than in each of
     * five renderers.
     */
    public function testEveryTopicPublishesFourHeadlineFigures(): void
    {
        $this->aCatalogue(['patrols' => 0]);

        foreach ($this->topics()->forScope(PerformanceScope::organization(), self::period()) as $topic) {
            self::assertCount(4, $topic->kpis(PerformanceScope::organization(), self::period()), $topic->key());
        }
    }

    /**
     * AND THE ROW EACH ONE DRAWS IS THE DESIGN'S, label for label and in
     * order. A topic's four are a sentence read left to right, so the
     * order is part of the drawing and not a detail of assembly.
     */
    public function testEachTopicsRowIsTheDesignsRowInOrder(): void
    {
        $this->aCatalogue(['patrols' => 0]);

        $rows = [];
        foreach ($this->topics()->forScope(PerformanceScope::organization(), self::period()) as $topic) {
            $rows[$topic->key()] = array_map(
                static fn (TopicKpi $kpi): string => $kpi->label,
                $topic->kpis(PerformanceScope::organization(), self::period()),
            );
        }

        self::assertSame(['Positions', 'Filled', 'Vacant', 'Over threshold'], $rows['staffing']);
        self::assertSame(['Declared', 'Met', 'Off track', 'No figure yet'], $rows['goals']);
        self::assertSame(['Items raised', 'Unowned', 'Resolved', 'Records'], $rows['attention']);
    }

    /**
     * THE STAFFING TOPIC COUNTS WHAT HAS STOOD EMPTY TOO LONG, from the day
     * each post fell vacant — and says how many it could not count,
     * because a post that was already empty before the day was recorded
     * may be the oldest vacancy there is.
     *
     * THE POSTS CARRY NO DEPARTMENT. A position used to be filed under one;
     * the ruling made the department a placement written against each person,
     * so a vacancy is a fact about the post alone and the organization-wide
     * count reads it there.
     */
    public function testTheStaffingTopicCountsThePostsThatHaveStoodEmptyTooLong(): void
    {
        $department = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Protection Service');
        $this->em->persist($department);

        $long = new \Uhifadhi\Bundle\TeamBundle\Entity\Position()->setName('Ranger');
        $long->setPermissionValues([], []);
        $long->setVacantSince(new \DateTimeImmutable('-96 days'));
        $fresh = new \Uhifadhi\Bundle\TeamBundle\Entity\Position()->setName('Warden');
        $fresh->setPermissionValues([], []);
        $fresh->setVacantSince(new \DateTimeImmutable('-3 days'));
        $undated = new \Uhifadhi\Bundle\TeamBundle\Entity\Position()->setName('Scout');
        $undated->setPermissionValues([], []);
        $this->em->persist($long);
        $this->em->persist($fresh);
        $this->em->persist($undated);

        // THE POSTS REACH THE DEPARTMENT THROUGH THE PEOPLE WHO HELD THEM.
        // Each of the three is stood in by somebody placed in Protection
        // Service and since deactivated, which is how a post comes to be
        // both the department's and empty.
        foreach ([$long, $fresh, $undated] as $i => $post) {
            $this->departedHolder('Rangers'.$i, $post, $department);
        }
        $this->em->flush();

        $staffing = $this->topics()->byKey('staffing', PerformanceScope::organization(), self::period());
        self::assertNotNull($staffing);

        $figures = $staffing->kpis(PerformanceScope::organization(), self::period());
        $overThreshold = $figures[3];

        self::assertSame('staffing.over_threshold', $overThreshold->key);
        self::assertSame(1.0, $overThreshold->value);
        self::assertStringContainsString('1 more stood empty', $overThreshold->caption);
        self::assertStringContainsString('unfilled past', $overThreshold->caption);
    }

    /**
     * A GOAL'S STATE IS DERIVED AT THE MOMENT OF ASKING, from the figure
     * it names against the target it set — so nothing is stored that a
     * revised figure could contradict.
     *
     * AND "NO FIGURE YET" IS ITS OWN STATE. A goal whose module has
     * published nothing is not a miss: counting a silence as a failure
     * would mark a department down for somebody else's module, and the
     * page states the two facts separately.
     */
    public function testAGoalWithNoFigureIsNeitherMetNorMissed(): void
    {
        $department = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Community Development');
        $this->em->persist($department);

        // A target nothing reports against: no module is attached, so the
        // KPI this goal names is published by nobody.
        $goal = new \Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal()
            ->setDepartment($department)
            ->setStatement('Claims settled within 10 days')
            ->setKpiRef('incidents.days_to_settle')
            ->setTarget(10.0)
            ->setUnit('days')
            // A goal is a promise over a WINDOW; there is no such thing as
            // one without dates, and the schema says so.
            ->setOpensAt(new \DateTimeImmutable('-30 days'))
            ->setClosesAt(new \DateTimeImmutable('+30 days'));
        $this->em->persist($goal);
        $this->em->flush();

        $goals = $this->topics()->byKey('goals', PerformanceScope::organization(), self::period());
        self::assertNotNull($goals);

        $figures = [];
        foreach ($goals->kpis(PerformanceScope::organization(), self::period()) as $kpi) {
            $figures[$kpi->key] = $kpi->value;
        }

        self::assertSame(1.0, $figures['goals.no_figure']);
        self::assertSame(0.0, $figures['goals.met']);
        // At risk and missed are one card now; neither is inflated by a
        // goal nothing can be measured against.
        self::assertSame(0.0, $figures['goals.off_track']);
        self::assertSame(1.0, $figures['goals.declared']);
    }

    /**
     * EVERY DEPARTMENT IS A ROW, INCLUDING THE ONES THAT DECLARED NONE.
     * Any department can declare a goal, so a quiet one is a row saying
     * nought rather than a row that is missing — which is the whole
     * reason this matrix does not fold.
     */
    public function testADepartmentThatDeclaredNoGoalIsStillARow(): void
    {
        $quiet = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Finance');
        $this->em->persist($quiet);
        $this->em->flush();

        $goals = $this->topics()->byKey('goals', PerformanceScope::organization(), self::period());
        self::assertNotNull($goals);

        $matrix = $goals->matrix(PerformanceScope::organization(), self::period());
        $names = array_map(static fn (object $row): string => $row->departmentName, $matrix->rows);

        self::assertContains('Finance', $names);
        self::assertSame(0.0, $matrix->rows[0]->cells['goals.declared']->value);
    }

    /**
     * PACE IS A CHIP PER GOAL, NOT AN AVERAGE OF THEM. Four goals in four
     * states is four facts; a single number would answer a question
     * nobody asked and a reader could not get back to the goals from it.
     */
    public function testThePaceColumnCountsStatesRatherThanMeasuringThem(): void
    {
        $department = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Ecology');
        $this->em->persist($department);

        foreach ([['Met one', 1.0], ['Met two', 1.0]] as [$statement, $target]) {
            $goal = new \Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal()
                ->setDepartment($department)
                ->setStatement($statement)
                ->setTarget($target)
                ->setUnit('')
                ->setOpensAt(new \DateTimeImmutable('-30 days'))
                ->setClosesAt(new \DateTimeImmutable('+30 days'));
            $this->em->persist($goal);
        }
        $this->em->flush();

        $goals = $this->topics()->byKey('goals', PerformanceScope::organization(), self::period());
        self::assertNotNull($goals);

        $pace = $goals->matrix(PerformanceScope::organization(), self::period())->rows[0]->cells['goals.pace'];

        self::assertTrue($pace->isMarked(), 'the pace column counts states');
        self::assertNull($pace->value, 'and states no figure, because there is none to state');
        self::assertCount(2, $pace->marks, 'one chip a goal');
    }

    /**
     * A DEPARTMENT WITH NO COMPUTING MODULE IS FOLDED, NOT DRAWN AT
     * NOUGHT. Only a module raises an item or writes a record, so a
     * nought there would say the department was asked and answered
     * none — which is a different, untrue statement.
     *
     * AND THE FOLDED LINE SAYS WHAT THEY DO HAVE. Seats and goals are
     * the host's own and every department has them, so the line carries
     * both: the reader can see the quiet ones are accounted for rather
     * than dropped.
     */
    public function testADepartmentWithNoComputingModuleIsFoldedRatherThanScoredNought(): void
    {
        $measuring = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Protection Service');
        $quiet = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Finance');
        $this->em->persist($measuring);
        $this->em->persist($quiet);
        $this->em->flush();

        $attention = $this->attentionReading((string) $measuring->getUuidString());
        $matrix = $attention->matrix(PerformanceScope::organization(), self::period());

        $names = array_map(static fn (object $row): string => $row->departmentName, $matrix->rows);

        self::assertContains('Protection Service', $names, 'the department a module measures is its own row');
        self::assertNotContains('Finance', $names, 'and the quiet one is not a row of noughts');

        $fold = array_values(array_filter($matrix->rows, static fn (object $row): bool => '' === $row->departmentUuid));
        self::assertCount(1, $fold, 'one folded line a scope band');
        self::assertStringContainsString('1 department with no computing module', $fold[0]->departmentName);
        self::assertStringContainsString('seats', $fold[0]->departmentName);
        self::assertStringContainsString('goals', $fold[0]->departmentName);
    }

    /**
     * AND THE FOLDED LINE'S CELLS ARE AN HONEST ABSENCE, not a zero:
     * the column does not apply to those departments at all, which is
     * the emptiness the matrix draws as a dash.
     */
    public function testTheFoldedLinesCellsSayTheColumnIsNotTheirs(): void
    {
        $measuring = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Protection Service');
        $this->em->persist($measuring);
        $this->em->persist(new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Finance'));
        $this->em->flush();

        $matrix = $this->attentionReading((string) $measuring->getUuidString())
            ->matrix(PerformanceScope::organization(), self::period());

        $fold = array_values(array_filter($matrix->rows, static fn (object $row): bool => '' === $row->departmentUuid))[0];

        foreach ($fold->cells as $cell) {
            self::assertTrue($cell->notMine);
            self::assertNull($cell->value, 'not nought — the question was never put to them');
        }
    }

    /**
     * THE FIGURES ARE FOUND BY ROLE, NEVER BY LABEL. A module calls its
     * records "cases", "sightings" or "patrols logged"; the host reads
     * the role the column declares and adds those up.
     */
    public function testTheItemsAreFoundByRoleAndNotByLabel(): void
    {
        $department = new \Uhifadhi\Bundle\TeamBundle\Entity\Department()->setName('Protection Service');
        $this->em->persist($department);
        $this->em->flush();

        $figures = [];
        foreach ($this->attentionReading((string) $department->getUuidString())
            ->kpis(PerformanceScope::organization(), self::period()) as $kpi) {
            $figures[$kpi->key] = $kpi->value;
        }

        // The stand-in module publishes 12 under a column it calls "Open",
        // declaring the items-raised role; the host never reads the word.
        self::assertSame(12.0, $figures['attention.raised']);
        self::assertArrayNotHasKey('attention.measuring', $figures, 'Coverage is a caption, not a plate.');
    }

    /**
     * The attention topic, reading ONE stand-in module that measures the
     * department named — built by hand because the fixture modules in
     * the kernel answer for a department no suite owns.
     */
    private function attentionReading(string $departmentUuid): \Uhifadhi\Bundle\TeamBundle\Performance\AttentionTopic
    {
        $container = static::getContainer();

        $departments = $container->get('test_public.'.\Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository::class);
        $goals = $container->get('test_public.'.\Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository::class);
        $staffing = $container->get('test_public.'.\Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures::class);

        \assert($departments instanceof \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository);
        \assert($goals instanceof \Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository);
        \assert($staffing instanceof \Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures);

        return new \Uhifadhi\Bundle\TeamBundle\Performance\AttentionTopic(
            $departments,
            $goals,
            $staffing,
            [new FakeTopicProvider('patrols', $departmentUuid)],
        );
    }

    /** @param array<string, int> $modules slug to the position it is arranged at */
    private function aCatalogue(array $modules): void
    {
        foreach ($modules as $slug => $position) {
            $this->em->persist(new Module()
                ->setSlug($slug)
                ->setName(ucfirst($slug))
                ->setCategory(ModuleCategory::Pressure)
                ->setStatus(ModuleStatus::Live)
                ->setDataSource('the stand-in')
                ->setPosition($position));
        }
        $this->em->flush();
    }

    /**
     * @param list<\Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface> $topics
     *
     * @return list<string>
     */
    private function keysOf(array $topics): array
    {
        return array_map(static fn ($topic): string => $topic->key(), $topics);
    }

    private function topics(): PerformanceTopics
    {
        /** @var PerformanceTopics $topics */
        $topics = static::getContainer()->get('test_public.'.PerformanceTopics::class);

        return $topics;
    }

    /**
     * Somebody who held a post in this department and is no longer active —
     * the only way a department has a post nobody stands in, now that a
     * department's posts are the ones its members hold.
     */
    private function departedHolder(
        string $surname,
        \Uhifadhi\Bundle\TeamBundle\Entity\Position $position,
        \Uhifadhi\Bundle\TeamBundle\Entity\Department $department,
    ): void {
        $placement = new \Uhifadhi\Bundle\TeamBundle\Entity\Placement()
            ->acrossTheOrganization()->inDepartments([$department]);
        $this->em->persist($placement);

        $person = new \Uhifadhi\Bundle\TeamBundle\Entity\User()
            ->setEmail(strtolower($surname).'@example.test')
            ->setFirstName('Departed')->setLastName($surname)
            ->setPassword('x')
            ->setPosition($position)->setPlacement($placement);
        $person->deactivate();
        $this->em->persist($person);
    }

    private static function period(): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable('2026-09-19'));
    }
}
