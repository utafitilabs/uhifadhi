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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE SIX STANDING LISTS, THE FRESHNESS LINE AND THE DRIFT, as the run filed
 * them, in the words the pages print.
 */
#[CoversClass(PermissionFindingsReader::class)]
final class PermissionFindingsReaderTest extends IntegrationTestCase
{
    use BuildsAnOrganization;
    use RunsPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    public function testWithNoRunTheListsAreEmptyAndTheNextRunIsStillKnown(): void
    {
        $standing = $this->reader()->standing();
        $freshness = $this->reader()->freshness();

        self::assertNull($standing['asOf']);
        self::assertSame([0, 0, 0, 0, 0, 0, 0], array_column($standing['lists'], 'count'));
        self::assertNull($freshness['asOf']);
        self::assertSame('02:00', $freshness['nextRun']->format('H:i'), 'the default schedule, 0 2 * * *');
        self::assertGreaterThan(new \DateTimeImmutable(), $freshness['nextRun']);
        self::assertSame(['unreviewed' => 0, 'lines' => []], $this->reader()->drift());
    }

    public function testTheSixListsAreReadInTheirOrderWithTheirEntries(): void
    {
        $this->runNow();
        $lists = array_column($this->reader()->standing()['lists'], null, 'id');

        self::assertSame(['reach-nothing', 'not-in-force', 'not-honoured', 'gaining', 'vacant', 'exceptions', 'self-changes'], array_keys($lists));
        self::assertFalse($lists['self-changes']['alarm'], 'informational: an auditor reads it, nobody is warned');
        self::assertTrue($lists['reach-nothing']['alarm']);
        self::assertFalse($lists['vacant']['alarm']);

        $reach = array_column($lists['reach-nothing']['entries'], null, 'name');
        self::assertSame(['Bakari Chuwa', 'Chiku Nnko', 'Frank Massawe', 'Latifa Macha'], array_keys($reach), 'the four who reach nothing');
        self::assertSame(4, $lists['reach-nothing']['count']);
        self::assertSame('no position · no placement', $reach['Frank Massawe']['why']);
        self::assertSame('Driver', $reach['Bakari Chuwa']['position']);
        self::assertSame('no department', $reach['Bakari Chuwa']['why']);

        self::assertSame([['positionId' => $this->positionId('Rhino coordinator'), 'position' => 'Rhino coordinator', 'pair' => 'locations.read',
            'pairLabel' => $lists['not-in-force']['entries'][0]['pairLabel'], 'why' => $lists['not-in-force']['entries'][0]['why']]], $lists['not-in-force']['entries']);
        $why = $lists['not-in-force']['entries'][0]['why'];
        self::assertIsString($why);
        self::assertStringStartsWith('lifts ', $why);

        self::assertSame('People officer', $lists['not-honoured']['entries'][0]['position']);
        self::assertSame('positions.configure', $lists['not-honoured']['entries'][0]['pair']);

        self::assertSame(['Tourism warden'], array_column($lists['vacant']['entries'], 'position'));
        self::assertEquals(new \DateTimeImmutable('2026-08-14'), $lists['vacant']['entries'][0]['since']);

        $exception = $lists['exceptions']['entries'][0];
        self::assertSame(['Head of Operations', 'locations.read', 'Naomi Kileo', 'Runs every operation across the four areas.'],
            [$exception['position'], $exception['pair'], $exception['by'], $exception['reason']]);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 10:18'), $exception['at']);

        self::assertNotContains('Super Admin', array_column($lists['gaining']['entries'], 'subject'), 'a Super Admin gaining power is expected, not filed');
        foreach ($lists['gaining']['entries'] as $entry) {
            self::assertSame(['subject', 'positionId', 'power', 'detail'], array_keys($entry));
        }
    }

    public function testFreshnessSaysWhenTheRunWasAndWhatItAsked(): void
    {
        $first = $this->runNow();
        $second = $this->runNow();
        $freshness = $this->reader()->freshness();

        self::assertEquals($second->getStartedAt(), $freshness['asOf']);
        self::assertEquals($first->getStartedAt(), $freshness['previousRun']);
        self::assertFalse($freshness['pending']);
        self::assertFalse($freshness['running']);
        self::assertNull($freshness['lastFailure']);
        self::assertSame($second->getCount('questions'), $freshness['questions']);
    }

    /** Drift is folded to one line per subject and question, with the places that moved under it. */
    public function testDriftIsFoldedAndMarkedReviewedOnce(): void
    {
        $this->runNow();
        $surveys = $this->em->getRepository(Module::class)->findOneBy(['slug' => 'surveys']) ?? throw new \LogicException('no module');
        $this->departments['Ecology and Monitoring']->attachModule($surveys);
        $this->em->flush();
        $this->runNow();

        $drift = $this->reader()->drift();
        $keys = array_map(static fn (array $l): string => $l['subject'].' · '.$l['question'], $drift['lines']);
        self::assertSame(\count($keys), \count(array_unique($keys)), 'one line per subject × question');
        $ranger = array_values(array_filter($drift['lines'], static fn (array $l): bool => 'Patrol, security and inspection · surveys.record' === $l['subject'].' · '.$l['question']))[0];
        self::assertSame('Surveys · record', $ranger['label']);
        self::assertSame([['place' => 'Kilimani Game Reserve', 'was' => 3, 'now' => 4, 'reviewed' => false]],
            array_map(static fn (array $p): array => array_diff_key($p, ['driftId' => 0]), $ranger['places']));
        self::assertNotNull($ranger['previousRun']);
        self::assertGreaterThan(0, $drift['unreviewed']);

        $marked = $this->reader()->markReviewed($this->people['Naomi Kileo']);
        self::assertSame($drift['unreviewed'], $marked);
        self::assertSame(0, $this->reader()->drift()['unreviewed']);
        self::assertSame(0, $this->reader()->markReviewed($this->people['Naomi Kileo']), 'marked once');
        $this->em->clear();
        foreach ($this->service(PermissionDriftRepository::class)->findAll() as $row) {
            self::assertSame('Naomi Kileo', $row->getReviewedByName());
        }
    }

    public function testMarkingTheIdsGivenLeavesTheRest(): void
    {
        $this->runNow();
        $surveys = $this->em->getRepository(Module::class)->findOneBy(['slug' => 'surveys']) ?? throw new \LogicException('no module');
        $this->departments['Ecology and Monitoring']->attachModule($surveys);
        $this->em->flush();
        $this->runNow();

        $before = $this->reader()->drift()['unreviewed'];
        $first = $this->reader()->drift()['lines'][0]['places'][0]['driftId'];

        self::assertSame(1, $this->reader()->markReviewed($this->people['Naomi Kileo'], [$first]));
        self::assertSame($before - 1, $this->reader()->drift()['unreviewed']);
    }

    /** The seventh list: a person changing their own tier, rank or position since the previous run. */
    public function testSelfChangesSinceThePreviousRunAreListed(): void
    {
        $this->runNow();
        $log = $this->service(PermissionChangeLog::class);
        $log->record(PermissionChangeEnum::TierChanged, $this->people['Naomi Kileo'], 'Admin', 'Super Admin', null, $this->people['Naomi Kileo']);
        $log->record(PermissionChangeEnum::TierChanged, $this->people['Desta Haile'], 'Staff', 'Admin', null, $this->people['Naomi Kileo']);
        $log->record(PermissionChangeEnum::Seated, $this->people['Thabo Ndlovu'], null, 'Station head', null, $this->people['Thabo Ndlovu']);
        $log->record(PermissionChangeEnum::EmailChanged, $this->people['Imani Laizer'], 'a', 'b', null, $this->people['Imani Laizer']);
        $run = $this->runNow();

        $list = array_column($this->reader()->standing()['lists'], null, 'id')['self-changes'];
        self::assertSame(2, $list['count'], 'their own tier and their own seat; somebody else\'s tier and their own email are not self-changes');
        $naomi = array_values(array_filter($list['entries'], static fn (array $e): bool => 'Naomi Kileo' === $e['name']))[0];
        self::assertSame(['personId', 'name', 'kind', 'what', 'before', 'after', 'at'], array_keys($naomi));
        self::assertSame(['tier-changed', 'tier', 'Admin', 'Super Admin'], [$naomi['kind'], $naomi['what'], $naomi['before'], $naomi['after']]);
        self::assertInstanceOf(\DateTimeImmutable::class, $naomi['at']);

        $thabo = array_values(array_filter($list['entries'], static fn (array $e): bool => 'Thabo Ndlovu' === $e['name']))[0];
        self::assertSame(['seated', 'position', null, 'Station head'], [$thabo['kind'], $thabo['what'], $thabo['before'], $thabo['after']]);

        $stored = array_column(array_map(static fn (PermissionFinding $f): array => ['name' => $f->getSubjectName(), 'finding' => $f],
            $this->service(PermissionFindingRepository::class)->findByRunAndList($run, FindingListEnum::SelfChanges)), 'finding', 'name');
        $finding = $stored['Naomi Kileo'];
        self::assertSame(['tier-changed', 'tier', 'Admin', 'Super Admin'], [$finding->getChangeKind(), $finding->getWhat(), $finding->getBeforeValue(), $finding->getAfterValue()]);
        self::assertSame($finding->getChangedAt(), $naomi['at'], 'the entry\'s time is the change\'s own');
        self::assertSame([null, null, null], [$finding->getPair(), $finding->getDetail(), $finding->getAt()], 'nothing is folded into the other lists\' columns');
    }

    public function testWithNoPreviousRunTheLastThirtyDaysAreRead(): void
    {
        $this->service(PermissionChangeLog::class)->record(PermissionChangeEnum::TierChanged, $this->people['Naomi Kileo'], 'Admin', 'Super Admin', null, $this->people['Naomi Kileo']);
        $this->runNow();

        self::assertSame(1, array_column($this->reader()->standing()['lists'], 'count', 'id')['self-changes']);
    }

    public function testNothingIsWorthRunningBeforeARun(): void
    {
        self::assertSame([], $this->reader()->worthRunning());
    }

    /** The Check page's examples come from the run's findings, in the ruling's order. */
    public function testTheChecksWorthRunningAreDerivedFromTheFindings(): void
    {
        $this->runNow();
        $rows = $this->reader()->worthRunning();

        $whys = array_column($rows, 'why');
        $order = ['reaches nothing', 'exception not in force', 'stored, not honoured', 'gains power', 'exception in force', 'nothing flagged'];
        $positions = array_map(static fn (string $w): int => (int) array_search($w, $order, true), $whys);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'reach nothing, not in force, not honoured, gaining, exceptions');

        $first = $rows[0];
        self::assertSame(['person', 'question', 'place', 'why', 'kind', 'group', 'flagged'], array_keys($first));
        $flagged = array_values(array_filter($rows, static fn (array $r): bool => $r['flagged']));
        foreach ($flagged as $row) {
            self::assertSame('exception in force' === $row['why'] ? 'confirmation' : 'attention', $row['group'], $row['why']);
        }
        self::assertSame(['attention', 'confirmation', 'none'], array_values(array_unique(array_column($rows, 'group'))), 'the attention rows first, then the confirmations, then nobody flagged');
        $kinds = ['reaches nothing' => 'reaches-nothing', 'exception not in force' => 'not-in-force', 'stored, not honoured' => 'stored-not-honoured', 'gains power' => 'gains-power', 'exception in force' => 'in-force', 'nothing flagged' => 'nothing-flagged'];
        foreach ($rows as $row) {
            self::assertSame($kinds[$row['why']], $row['kind']);
            self::assertSame('nothing flagged' !== $row['why'], $row['flagged']);
        }
        self::assertSame('Bakari Chuwa', $first['person']['name']);
        self::assertSame(['kind' => 'pair', 'key' => 'stations.read', 'label' => $first['question']['label']], $first['question'], 'the first pair the Driver holds');
        self::assertSame(['key' => $this->areaKey('Mlima Mkuu'), 'name' => 'Mlima Mkuu'], $first['place']);
        $frank = array_values(array_filter($rows, static fn (array $r): bool => 'Frank Massawe' === $r['person']['name']))[0];
        self::assertSame('organization', $frank['place']['key'], 'placed nowhere: the whole organization');

        $byWhy = array_column($rows, null, 'why');
        self::assertSame(['Elias Mdee', 'locations.read', 'organization'], [$byWhy['exception not in force']['person']['name'], $byWhy['exception not in force']['question']['key'], $byWhy['exception not in force']['place']['key']]);
        self::assertSame(['Mariam Temba', 'positions.configure', 'organization'], [$byWhy['stored, not honoured']['person']['name'], $byWhy['stored, not honoured']['question']['key'], $byWhy['stored, not honoured']['place']['key']]);
        self::assertSame(['Grace Ndosi', 'locations.read'], [$byWhy['exception in force']['person']['name'], $byWhy['exception in force']['question']['key']]);
        foreach ($rows as $row) {
            if ('gains power' === $row['why']) {
                self::assertSame('power', $row['question']['kind']);
                self::assertNotSame('Naomi Kileo', $row['person']['name'], 'a Super Admin gaining power is not a finding');
            }
        }
    }

    /** Every active Staff person once: the flagged by their first finding, the rest by name with nothing flagged. */
    public function testEveryStaffPersonIsWorthRunningOnce(): void
    {
        $this->runNow();
        $rows = $this->reader()->worthRunning();

        $names = array_column(array_column($rows, 'person'), 'name');
        self::assertSame($names, array_values(array_unique($names)), 'one row a person');
        $staff = [];
        foreach ($this->people as $name => $person) {
            if ($person->isActive() && TeamRoleEnum::Staff === $person->getTeamRole()) {
                $staff[] = $name;
            }
        }
        sort($staff);
        $sorted = $names;
        sort($sorted);
        self::assertSame($staff, $sorted, 'every active Staff person, no tier, nobody deactivated');

        $rest = array_column(array_column(array_values(array_filter($rows, static fn (array $r): bool => !$r['flagged'])), 'person'), 'name');
        self::assertNotEmpty($rest);
        $byName = $rest;
        sort($byName);
        self::assertSame($byName, $rest, 'the unflagged by name');
        $thabo = array_values(array_filter($rows, static fn (array $r): bool => 'Thabo Ndlovu' === $r['person']['name']))[0];
        self::assertSame(['nothing flagged', 'none', 'surveys.read', $this->areaKey('Kilimani Game Reserve')], [$thabo['why'], $thabo['group'], $thabo['question']['key'], $thabo['place']['key']], 'the first sensitive pair they hold, at their placement');
        $grace = array_values(array_filter($rows, static fn (array $r): bool => 'Grace Ndosi' === $r['person']['name']));
        self::assertCount(1, $grace, 'flagged once, by the first finding naming her');
    }

    private function reader(): PermissionFindingsReader
    {
        return $this->service(PermissionFindingsReader::class);
    }
}
