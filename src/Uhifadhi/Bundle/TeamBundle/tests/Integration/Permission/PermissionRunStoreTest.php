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
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionDrift;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionChangeRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT A RUN FILES IS WHAT THE PAGES READ BACK: the run, its answers, its
 * standing lists and its drift, stored and found again through the
 * repositories the readers use.
 */
#[CoversClass(PermissionRun::class)]
#[CoversClass(PermissionAnswer::class)]
#[CoversClass(PermissionFinding::class)]
#[CoversClass(PermissionDrift::class)]
#[CoversClass(PermissionRunRepository::class)]
#[CoversClass(PermissionAnswerRepository::class)]
#[CoversClass(PermissionFindingRepository::class)]
#[CoversClass(PermissionDriftRepository::class)]
final class PermissionRunStoreTest extends IntegrationTestCase
{
    public function testARequestIsPendingUntilItStartsAndTheLatestFinishedRunIsFound(): void
    {
        $older = $this->finishedRun('2026-11-05 02:00');
        $latest = $this->finishedRun('2026-11-06 14:02');
        $pending = new PermissionRun(PermissionRunTriggerEnum::Manual, new \DateTimeImmutable('2026-11-06 15:00'), 7, 'Amani Kisanga');
        $this->em->persist($pending);
        $this->em->flush();
        $this->em->clear();

        $runs = $this->runs();
        self::assertSame($latest->getId(), $runs->findOneLatestFinished()?->getId());
        self::assertSame($older->getId(), $runs->findOnePreviousFinished($latest)?->getId());
        self::assertNull($runs->findOnePreviousFinished($older));

        $found = $runs->findOnePending();
        self::assertNotNull($found);
        self::assertSame(PermissionRunStatusEnum::Pending, $found->getStatus());
        self::assertSame(PermissionRunTriggerEnum::Manual, $found->getTrigger());
        self::assertSame('Amani Kisanga', $found->getRequestedByName());
        self::assertSame(7, $found->getRequestedById());
    }

    public function testAPendingRequestStartsFinishesAndKeepsItsCounts(): void
    {
        $run = new PermissionRun(PermissionRunTriggerEnum::Nightly, new \DateTimeImmutable('2026-11-06 02:00'));
        $run->start(new \DateTimeImmutable('2026-11-06 02:00:01'), [['kind' => 'organization', 'id' => null, 'name' => 'the whole organization']], ['patrols.record']);
        $run->finish(new \DateTimeImmutable('2026-11-06 02:00:09'), [
            'people' => 12, 'staff' => 10, 'superAdmins' => 1, 'admins' => 1,
            'questions' => 40, 'shapes' => 4, 'answers' => 3, 'findings' => 2, 'drift' => 1,
        ]);
        $this->em->persist($run);
        $this->em->flush();
        $this->em->clear();

        $found = $this->runs()->findOneLatestFinished();
        self::assertNotNull($found);
        self::assertSame(PermissionRunStatusEnum::Finished, $found->getStatus());
        self::assertNull($found->getRequestedByName(), 'nobody asked: the schedule did');
        self::assertSame(12, $found->getCount('people'));
        self::assertSame(1, $found->getCount('drift'));
        self::assertSame(8000, $found->getDurationMs());
        self::assertSame(['patrols.record'], $found->getPairs());
        self::assertSame('the whole organization', $found->getPlaces()[0]['name']);
    }

    public function testACellIsFoundWithThePeopleInIt(): void
    {
        $run = $this->finishedRun('2026-11-06 14:02');
        $this->em->persist(PermissionAnswer::forPair($run, 'patrols.record', PlaceKindEnum::Area, 3, 11, [4, 9, 21]));
        $this->em->persist(PermissionAnswer::forPair($run, 'patrols.record', PlaceKindEnum::Organization, null, 12, [30]));
        $this->em->persist(PermissionAnswer::forPair($run, 'incidents.read', PlaceKindEnum::Area, 3, 11, [4]));
        $this->em->persist(PermissionAnswer::forPower($run, 'make-admin', 'a-colleague', 11, null, 'refused', 'member.tier', []));
        $this->em->flush();
        $this->em->clear();

        $answers = $this->answers()->findByRunAndQuestion($this->reload($run), QuestionKindEnum::Pair, 'patrols.record');
        self::assertCount(2, $answers);

        $cell = $this->answers()->findOneCell($this->reload($run), 'patrols.record', PlaceKindEnum::Area, 3, 11);
        self::assertNotNull($cell);
        self::assertSame(3, $cell->getPeopleCount());
        self::assertSame([4, 9, 21], $cell->getPeople());

        $power = $this->answers()->findByRunAndQuestion($this->reload($run), QuestionKindEnum::Power, 'make-admin');
        self::assertCount(1, $power);
        self::assertSame('refused', $power[0]->getVerdict());
        self::assertSame('member.tier', $power[0]->getRefusedBy());
        self::assertSame('a-colleague', $power[0]->getTarget());
        self::assertSame(0, $power[0]->getPeopleCount());
    }

    public function testFindingsAreCountedListByList(): void
    {
        $run = $this->finishedRun('2026-11-06 14:02');
        $this->em->persist(new PermissionFinding($run, FindingListEnum::ReachNothing, PermissionFinding::PERSON, 4, 'Frank Massawe', detail: 'no position · no placement'));
        $this->em->persist(new PermissionFinding($run, FindingListEnum::ReachNothing, PermissionFinding::PERSON, 5, 'Chiku Nnko', positionName: 'Security', detail: 'no ground'));
        $this->em->persist(new PermissionFinding($run, FindingListEnum::Exceptions, PermissionFinding::POSITION, 2, 'Control room operator', positionName: 'Control room operator', pair: 'locations.read', detail: 'The control room follows every patrol.', byName: 'Naomi Kileo', at: new \DateTimeImmutable('2026-11-06 09:40')));
        $this->em->flush();
        $this->em->clear();

        $counts = $this->findings()->countByRunAndList($this->reload($run));
        self::assertSame(2, $counts[FindingListEnum::ReachNothing->value]);
        self::assertSame(1, $counts[FindingListEnum::Exceptions->value]);
        self::assertArrayNotHasKey(FindingListEnum::Vacant->value, $counts);

        $reach = $this->findings()->findByRunAndList($this->reload($run), FindingListEnum::ReachNothing);
        self::assertSame(['Chiku Nnko', 'Frank Massawe'], array_map(static fn (PermissionFinding $f): string => $f->getSubjectName(), $reach));
        self::assertSame('Security', $reach[0]->getPositionName());
    }

    public function testDriftIsReviewedOnceAndStaysReviewed(): void
    {
        $previous = $this->finishedRun('2026-11-05 02:00');
        $run = $this->finishedRun('2026-11-06 14:02');
        $drift = new PermissionDrift($run, $previous, QuestionKindEnum::Pair, 'patrols.record', PlaceKindEnum::Area, 3, 'Kilimani Game Reserve', 11, 'Studies', before: 0, after: 4);
        $this->em->persist($drift);
        $this->em->flush();

        self::assertSame(1, $this->drift()->countUnreviewedByRun($run));
        $drift->review(new \DateTimeImmutable('2026-11-06 15:00'), 'Naomi Kileo');
        $this->em->flush();
        $this->em->clear();

        $rows = $this->drift()->findByRun($this->reload($run));
        self::assertCount(1, $rows);
        self::assertSame('Naomi Kileo', $rows[0]->getReviewedByName());
        self::assertSame(0, $rows[0]->getBefore());
        self::assertSame(4, $rows[0]->getAfter());
        self::assertSame($previous->getId(), $rows[0]->getPreviousRun()?->getId());
        self::assertSame(0, $this->drift()->countUnreviewedByRun($this->reload($run)));
    }

    public function testPruningDropsTheAnswersAndFindingsOfOlderRunsAndKeepsTheRuns(): void
    {
        $oldest = $this->finishedRun('2026-11-04 02:00');
        $previous = $this->finishedRun('2026-11-05 02:00');
        $latest = $this->finishedRun('2026-11-06 02:00');
        foreach ([$oldest, $previous, $latest] as $run) {
            $this->em->persist(PermissionAnswer::forPair($run, 'patrols.record', PlaceKindEnum::Organization, null, 1, [1]));
            $this->em->persist(new PermissionFinding($run, FindingListEnum::Vacant, PermissionFinding::POSITION, 1, 'Tourism warden'));
        }
        $this->em->flush();

        $this->answers()->deleteByRun($oldest);
        $this->findings()->deleteByRun($oldest);
        $this->em->clear();

        self::assertSame(0, $this->answers()->countByRun($this->reload($oldest)));
        self::assertSame(1, $this->answers()->countByRun($this->reload($previous)));
        self::assertSame([], $this->findings()->findByRunAndList($this->reload($oldest), FindingListEnum::Vacant));
        self::assertSame([$oldest->getId()], array_map(static fn (PermissionRun $r): ?int => $r->getId(), $this->runs()->findFinishedOlderThan($this->reload($previous))));
    }

    public function testTheHistoryLinesBetweenTwoInstantsAndPerMonth(): void
    {
        foreach (['2026-10-03 10:15', '2026-11-04 15:30', '2026-11-06 09:40'] as $when) {
            $this->em->persist(new PermissionChange(new \DateTimeImmutable($when), 1, 'Naomi Kileo', PermissionChangeEnum::GrantsChanged, PermissionChange::POSITION, 3, null, 'Studies'));
        }
        $this->em->flush();

        $changes = $this->service(PermissionChangeRepository::class);
        self::assertCount(2, $changes->findRecordedBetween(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-07')));
        self::assertSame(['2026-10' => 1, '2026-11' => 2], $changes->countByMonthSince(new \DateTimeImmutable('2026-06-01')));
    }

    private function finishedRun(string $at): PermissionRun
    {
        $run = new PermissionRun(PermissionRunTriggerEnum::Nightly, new \DateTimeImmutable($at));
        $run->start(new \DateTimeImmutable($at), [], []);
        $run->finish(new \DateTimeImmutable($at.':05'), []);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function reload(PermissionRun $run): PermissionRun
    {
        $found = $this->em->find(PermissionRun::class, $run->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function runs(): PermissionRunRepository
    {
        return $this->service(PermissionRunRepository::class);
    }

    private function answers(): PermissionAnswerRepository
    {
        return $this->service(PermissionAnswerRepository::class);
    }

    private function findings(): PermissionFindingRepository
    {
        return $this->service(PermissionFindingRepository::class);
    }

    private function drift(): PermissionDriftRepository
    {
        return $this->service(PermissionDriftRepository::class);
    }
}
