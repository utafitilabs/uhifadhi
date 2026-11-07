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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionDrift;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Bundle\TeamBundle\MessageHandler\RecomputePermissionsHandler;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionAnswerService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionDriftService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentService;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * A RUN ASKS THE REAL VOTER ABOUT EVERY ACTIVE PERSON, PAIR AND PLACE, and
 * files what it heard: the cells, the six standing lists, and the answers
 * that changed with nothing in the history to say why.
 */
#[CoversClass(PermissionRunService::class)]
#[CoversClass(PermissionAnswerService::class)]
#[CoversClass(PermissionFindingService::class)]
#[CoversClass(PermissionDriftService::class)]
#[CoversClass(RecomputePermissionsHandler::class)]
final class PermissionRunServiceTest extends IntegrationTestCase
{
    use BuildsAnOrganization;

    private const string RANGER_POSITION = 'Patrol, security and inspection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    /** §7, a ranger: one of the Kilimani count on the ranger row, and of no other column but their own area. */
    public function testTheRangersAreFiledWhereTheyArePlacedAndNowhereElse(): void
    {
        $run = $this->runNow();
        $ranger = $this->id(self::RANGER_POSITION);

        self::assertSame(['Imani Laizer', 'Omari Mollel', 'Thabo Ndlovu'], $this->names($this->cell($run, 'surveys.record', 'Kilimani Game Reserve', $ranger)));
        self::assertSame(['Juma Mrema'], $this->names($this->cell($run, 'surveys.record', 'Sinde Flats', $ranger)));
        self::assertNull($this->cell($run, 'surveys.record', 'Mlima Mkuu', $ranger));
        self::assertNull($this->cell($run, 'surveys.record', null, $ranger), 'placed at areas, they do not reach the whole organization');
    }

    /** §7, the head of operations: one person in every column of the row. */
    public function testAnExceptionInForceFilesTheHolderInEveryPlace(): void
    {
        $run = $this->runNow();
        $head = $this->id('Head of Operations');

        foreach ([null, 'Kilimani Game Reserve', 'Ziwa Kavu', 'Mlima Mkuu', 'Sinde Flats'] as $place) {
            self::assertSame(['Grace Ndosi'], $this->names($this->cell($run, 'locations.read', $place, $head)), $place ?? 'the whole organization');
        }
    }

    public function testAnExceptionHeldButNotInForceAndATierOnlyPairAreFiledForNobody(): void
    {
        $run = $this->runNow();

        self::assertSame([], $this->answers()->findByRunAndQuestion($run, QuestionKindEnum::Pair, 'positions.configure'), 'stored on the People officer, honoured nowhere');
        foreach ($this->answers()->findByRunAndQuestion($run, QuestionKindEnum::Pair, 'locations.read') as $answer) {
            self::assertNotSame($this->id('Rhino coordinator'), $answer->getPositionId());
        }
    }

    public function testTheRunCountsTheTiersAndThePeopleItAsked(): void
    {
        $run = $this->runNow();

        self::assertSame(PermissionRunStatusEnum::Finished, $run->getStatus());
        self::assertSame(15, $run->getCount('people'), 'every active person; the deactivated ranger is not one');
        self::assertSame(13, $run->getCount('staff'));
        self::assertSame(1, $run->getCount('superAdmins'));
        self::assertSame(1, $run->getCount('admins'));
        self::assertSame(5, \count($run->getPlaces()));
        self::assertSame('the whole organization', $run->getPlaces()[0]['name']);
        self::assertContains('surveys.record', $run->getPairs());
        self::assertGreaterThan(0, $run->getCount('questions'));
    }

    /** THE SHAPE CACHE IS AN IMPLEMENTATION CHOICE: asking every person gives the very same file. */
    public function testAskingOncePerShapeNeverChangesAnAnswer(): void
    {
        $shapes = $this->runNow();
        $byShape = $this->file($shapes);

        $everyone = $this->service(PermissionAnswerService::class)->compute(
            $this->runs()->findOneLatestFinished() ?? throw new \LogicException('no run'),
            askEveryone: true,
        );
        $byPerson = [];
        foreach ($everyone['answers'] as $answer) {
            if (QuestionKindEnum::Pair === $answer->getQuestionKind()) {
                $byPerson[$this->key($answer)] = $this->sorted($answer->getPeople());
            }
        }
        ksort($byPerson);

        self::assertSame($byPerson, $byShape);
        self::assertLessThan($everyone['questions'], $shapes->getCount('questions'), 'and it asks fewer questions');
    }

    public function testTheSevenStandingListsAreFiled(): void
    {
        $this->service(PermissionChangeLog::class)->record(PermissionChangeEnum::TierChanged, $this->people['Desta Haile'], 'Staff', 'Admin', null, $this->people['Desta Haile']);
        $run = $this->runNow();
        $findings = $this->service(PermissionFindingRepository::class);

        $reach = $findings->findByRunAndList($run, FindingListEnum::ReachNothing);
        self::assertSame(['Bakari Chuwa', 'Chiku Nnko', 'Frank Massawe', 'Latifa Macha'], array_map(static fn (PermissionFinding $f): string => $f->getSubjectName(), $reach));
        self::assertSame('no department', $reach[0]->getDetail());
        self::assertSame('no ground', $reach[1]->getDetail());
        self::assertSame('no position · no placement', $reach[2]->getDetail());
        self::assertSame('no position', $reach[3]->getDetail());

        $notInForce = $findings->findByRunAndList($run, FindingListEnum::NotInForce);
        self::assertSame([['Rhino coordinator', 'locations.read']], array_map(static fn (PermissionFinding $f): array => [$f->getSubjectName(), $f->getPair()], $notInForce));

        $notHonoured = $findings->findByRunAndList($run, FindingListEnum::NotHonoured);
        self::assertSame([['People officer', 'positions.configure']], array_map(static fn (PermissionFinding $f): array => [$f->getSubjectName(), $f->getPair()], $notHonoured));

        $vacant = $findings->findByRunAndList($run, FindingListEnum::Vacant);
        self::assertSame(['Tourism warden'], array_map(static fn (PermissionFinding $f): string => $f->getSubjectName(), $vacant));
        self::assertSame('2026-08-14', $vacant[0]->getAt()?->format('Y-m-d'));

        // THE RULE SAYS NOBODY BUT A SUPER ADMIN RAISES THEMSELVES, and no
        // position does. The answers are the voters' own and are filed as
        // they come: an entry here is the alarm the hub raises, never a cell
        // edited away; a Super Admin's yes is expected and not filed.
        foreach ($findings->findByRunAndList($run, FindingListEnum::Gaining) as $gaining) {
            self::assertSame('Admin', $gaining->getSubjectName(), (string) $gaining->getDetail());
        }

        $exceptions = $findings->findByRunAndList($run, FindingListEnum::Exceptions);
        self::assertCount(1, $exceptions);
        self::assertSame('Head of Operations', $exceptions[0]->getSubjectName());
        self::assertSame('locations.read', $exceptions[0]->getPair());
        self::assertSame('Naomi Kileo', $exceptions[0]->getByName());
        self::assertSame('Runs every operation across the four areas.', $exceptions[0]->getDetail());

        $selfChanges = $findings->findByRunAndList($run, FindingListEnum::SelfChanges);
        self::assertCount(1, $selfChanges);
        $desta = $selfChanges[0];
        self::assertSame(['Desta Haile', 'tier-changed', 'tier', 'Staff', 'Admin'], [$desta->getSubjectName(), $desta->getChangeKind(), $desta->getWhat(), $desta->getBeforeValue(), $desta->getAfterValue()]);
        self::assertInstanceOf(\DateTimeImmutable::class, $desta->getChangedAt());
        self::assertSame([null, null, null], [$desta->getPair(), $desta->getDetail(), $desta->getAt()], 'its own columns, not the pair, the detail and the time of the other lists');
        self::assertSame(9 + \count($findings->findByRunAndList($run, FindingListEnum::Gaining)), $run->getCount('findings'), 'four, one, one, one, one and one, and the gaining list');
    }

    public function testPowersAreFiledPerActorAndTarget(): void
    {
        $run = $this->runNow();

        $answers = $this->answers()->findByRunAndQuestion($run, QuestionKindEnum::Power, 'make-admin');
        self::assertNotSame([], $answers);
        $tiers = array_filter($answers, static fn (PermissionAnswer $a): bool => 'super_admin' === $a->getTier());
        self::assertNotSame([], $tiers, 'a Super Admin is an actor');
        foreach ($answers as $answer) {
            if (null !== $answer->getPositionId()) {
                self::assertNotSame('allowed', $answer->getVerdict(), 'no position makes anybody Admin');
                self::assertSame(0, $answer->getPeopleCount());
            }
        }
    }

    /** A department's modules changed and nothing was written down: that is drift. */
    public function testAnAnswerThatChangesWithNoLineIsDrift(): void
    {
        $first = $this->runNow();
        $this->departments['Ecology and Monitoring']->attachModule($this->surveys());
        $this->em->flush();
        $second = $this->runNow();

        $drift = $this->service(PermissionDriftRepository::class)->findByRun($second);
        self::assertNotSame([], $drift);
        $neema = array_values(array_filter($drift, fn (PermissionDrift $d): bool => $d->getPositionId() === $this->id(self::RANGER_POSITION) && 'surveys.record' === $d->getQuestionKey()));
        self::assertCount(1, $neema);
        self::assertSame('Kilimani Game Reserve', $neema[0]->getPlaceName());
        self::assertSame(3, $neema[0]->getBefore());
        self::assertSame(4, $neema[0]->getAfter());
        self::assertSame($first->getId(), $neema[0]->getPreviousRun()?->getId());
        self::assertSame(\count($drift), $second->getCount('drift'));
    }

    /** The core writes ModuleAttached, so the same change made through the service is explained. */
    public function testAChangeTheHistoryRecordsIsNotDrift(): void
    {
        $this->runNow();
        $this->service(DepartmentService::class)->attach($this->departments['Ecology and Monitoring'], $this->surveys());
        $second = $this->runNow();

        self::assertSame([], $this->service(PermissionDriftRepository::class)->findByRun($second));
    }

    /** A line explains the answers it touches, and only those. */
    public function testALineAboutOnePositionDoesNotExplainAnother(): void
    {
        $this->runNow();
        $this->service(PermissionChangeLog::class)->record(PermissionChangeEnum::GrantsChanged, $this->positions['Rhino coordinator'], 'surveys.read', 'surveys.read', null, $this->people['Naomi Kileo']);
        $this->positions[self::RANGER_POSITION]->setGrantValues(['stations.read', 'surveys.read'], ['stations.read', 'surveys.read', 'surveys.record']);
        $this->em->flush();
        $second = $this->runNow();

        $keys = array_map(static fn (PermissionDrift $d): string => $d->getSubjectName().' · '.$d->getQuestionKey(), $this->service(PermissionDriftRepository::class)->findByRun($second));
        self::assertContains(self::RANGER_POSITION.' · surveys.record', $keys);
        self::assertNotContains('Rhino coordinator · stations.read', $keys);
    }

    public function testRequestsCollapseIntoOnePendingRunAndAStartingRunTakesTheRestWithIt(): void
    {
        $service = $this->service(PermissionRunService::class);
        $first = $service->requestRun(PermissionRunTriggerEnum::Manual, $this->people['Naomi Kileo']);
        $again = $service->requestRun(PermissionRunTriggerEnum::Nightly, null);

        self::assertSame($first->getId(), $again->getId());
        self::assertSame('Naomi Kileo', $first->getRequestedByName());
        self::assertCount(1, $this->transport()->getSent(), 'one wake-up for one request');

        $stray = new PermissionRun(PermissionRunTriggerEnum::HistoryLine, new \DateTimeImmutable());
        $this->em->persist($stray);
        $this->em->flush();

        $this->service(RecomputePermissionsHandler::class)(new RecomputePermissions($first->getId()));
        $this->em->clear();

        self::assertSame(PermissionRunStatusEnum::Finished, $this->runs()->find($first->getId())?->getStatus());
        self::assertSame(PermissionRunStatusEnum::Superseded, $this->runs()->find($stray->getId())?->getStatus());
        self::assertNull($this->runs()->findOnePending());
    }

    public function testAMessageForARunThatIsNoLongerPendingDoesNothing(): void
    {
        $run = $this->runNow();
        $answers = $this->answers()->countByRun($run);

        $this->service(RecomputePermissionsHandler::class)(new RecomputePermissions($run->getId()));

        self::assertSame($answers, $this->answers()->countByRun($run));
    }

    public function testOnlyTheLatestTwoFinishedRunsKeepTheirAnswers(): void
    {
        $first = $this->runNow();
        $second = $this->runNow();
        $third = $this->runNow();

        self::assertSame(0, $this->answers()->countByRun($first));
        self::assertGreaterThan(0, $this->answers()->countByRun($second));
        self::assertGreaterThan(0, $this->answers()->countByRun($third));
        self::assertNotNull($this->runs()->find($first->getId()), 'the run row itself is kept');
    }

    private function runNow(): PermissionRun
    {
        $service = $this->service(PermissionRunService::class);
        $run = $service->requestRun(PermissionRunTriggerEnum::Manual, null);
        $service->handle($run->getId());

        $finished = $this->runs()->find($run->getId());
        self::assertNotNull($finished);

        return $finished;
    }

    private function cell(PermissionRun $run, string $pair, ?string $area, int $position): ?PermissionAnswer
    {
        return null === $area
            ? $this->answers()->findOneCell($run, $pair, PlaceKindEnum::Organization, null, $position)
            : $this->answers()->findOneCell($run, $pair, PlaceKindEnum::Area, $this->areas[$area]->getId(), $position);
    }

    /** @return list<string> */
    private function names(?PermissionAnswer $answer): array
    {
        $names = [];
        foreach ($answer?->getPeople() ?? [] as $id) {
            foreach ($this->people as $name => $person) {
                if ($person->getId() === $id) {
                    $names[] = $name;
                }
            }
        }
        sort($names);

        return $names;
    }

    /** @return array<string, list<int>> */
    private function file(PermissionRun $run): array
    {
        $file = [];
        foreach ($this->answers()->findByRun($run) as $answer) {
            if (QuestionKindEnum::Pair === $answer->getQuestionKind()) {
                $file[$this->key($answer)] = $this->sorted($answer->getPeople());
            }
        }
        ksort($file);

        return $file;
    }

    private function key(PermissionAnswer $answer): string
    {
        return implode('|', [$answer->getQuestionKey(), $answer->getPlaceKind()?->value, $answer->getPlaceId(), $answer->getPositionId()]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function id(string $position): int
    {
        return $this->positions[$position]->getId() ?? throw new \LogicException('not persisted');
    }

    private function surveys(): Module
    {
        return $this->em->getRepository(Module::class)->findOneBy(['slug' => 'surveys']) ?? throw new \LogicException('no module');
    }

    private function runs(): PermissionRunRepository
    {
        return $this->service(PermissionRunRepository::class);
    }

    private function answers(): PermissionAnswerRepository
    {
        return $this->service(PermissionAnswerRepository::class);
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('test_public.permissions_transport');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
