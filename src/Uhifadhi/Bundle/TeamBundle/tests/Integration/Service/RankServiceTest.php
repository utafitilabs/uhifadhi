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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE RANKS OF THE ORGANIZATION, STORED — one scale by default, ranks in
 * seniority order, and a person holding one rank at a time with its history.
 *
 * THE DATABASE IS THE PROOF: every assertion reads the rows back through a
 * fresh query, so a rank "held" only in memory would fail here.
 */
#[CoversClass(RankService::class)]
#[CoversClass(RankRepository::class)]
#[CoversClass(RankHoldingRepository::class)]
final class RankServiceTest extends IntegrationTestCase
{
    public function testAnInstallationWithNoScaleGetsTheDefaultOneOnTheFirstRank(): void
    {
        self::assertCount(0, $this->ranks()->scales());

        $rank = $this->ranks()->addRank($this->ranks()->defaultScale(), 'Conservation Ranger I', 'CR I');

        $scales = $this->ranks()->scales();
        self::assertCount(1, $scales);
        self::assertNull($scales[0]->getName(), 'the one scale is not named until a second exists');
        self::assertSame($scales[0], $rank->getScale());
        self::assertSame(1, $rank->getSeniority());
    }

    public function testRanksAreAppendedInSeniorityOrder(): void
    {
        $scale = $this->ranks()->defaultScale();
        $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I');
        $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');
        $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR');
        $this->em->clear();

        self::assertSame(['CR I', 'CR II', 'SCR'], array_map(
            static fn (Rank $rank): string => $rank->getShortCode(),
            $this->repository()->findActiveOrdered(),
        ));
    }

    public function testABlankNameOrCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ranks()->addRank($this->ranks()->defaultScale(), 'Conservation Ranger I', '  ');
    }

    public function testAShortCodeIsUniqueWithinItsScale(): void
    {
        $scale = $this->ranks()->defaultScale();
        $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I');

        $this->expectException(\InvalidArgumentException::class);
        $this->ranks()->addRank($scale, 'Another rank', 'cr i');
    }

    public function testSavingAScaleRenamesRecodesAndReordersItsRanks(): void
    {
        $scale = $this->ranks()->defaultScale();
        $one = $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I');
        $two = $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');

        $this->ranks()->saveScale($scale, null, [
            (string) $two->getUuidString() => ['name' => 'Ranger II', 'code' => 'R II'],
            (string) $one->getUuidString() => ['name' => 'Ranger I', 'code' => 'R I'],
        ], 'Ranger Sergeant', 'RSgt');
        $this->em->clear();

        self::assertSame(['R II · Ranger II', 'R I · Ranger I', 'RSgt · Ranger Sergeant'], array_map(
            static fn (Rank $rank): string => $rank->getShortCode().' · '.$rank->getName(),
            $this->repository()->findActiveOrdered(),
        ));
    }

    public function testASecondScaleNamesTheFirstAndIsNeverADepartment(): void
    {
        $first = $this->ranks()->defaultScale();
        $this->ranks()->addRank($first, 'Conservation Ranger I', 'CR I');

        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->em->clear();

        $names = array_map(static fn (RankScale $scale): ?string => $scale->getName(), $this->ranks()->scales());
        self::assertSame(['Uniformed', 'Civil'], $names);
        self::assertSame('Civil', $civil->getName());
    }

    public function testASecondScaleNeedsANameForTheFirst(): void
    {
        $this->ranks()->defaultScale();
        $this->em->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->ranks()->addScale('Civil', null);
    }

    public function testScalesAreNotLimitedToTwo(): void
    {
        $this->ranks()->defaultScale();
        $this->ranks()->addScale('Civil', 'Uniformed');
        $this->ranks()->addScale('Marine', null);

        self::assertCount(3, $this->ranks()->scales());
    }

    public function testOnceScalesAreSeveralEveryOneIsNamed(): void
    {
        $first = $this->ranks()->defaultScale();
        $this->ranks()->addScale('Civil', 'Uniformed');

        $this->expectException(\InvalidArgumentException::class);
        $this->ranks()->saveScale($first, '', [], null, null);
    }

    public function testAPersonHoldsOneRankAtATimeAndKeepsTheHistory(): void
    {
        $scale = $this->ranks()->defaultScale();
        $cr = $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');
        $scr = $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR');
        $joseph = $this->person('Joseph', 'Mollel');
        $naomi = $this->person('Naomi', 'Kileo');
        $this->em->flush();

        $this->ranks()->assign($joseph, $cr, new \DateTimeImmutable('2024-01-09'), $naomi);
        $this->ranks()->assign($joseph, $scr, new \DateTimeImmutable('2025-03-03'), $naomi);
        $this->em->clear();

        $person = $this->em->getRepository(User::class)->findOneBy(['email' => 'j.mollel@example.test']);
        self::assertInstanceOf(User::class, $person);
        $history = $this->holdings()->findByPersonNewestFirst($person);
        self::assertCount(2, $history);
        self::assertSame('SCR', $history[0]->getRank()->getShortCode());
        self::assertNull($history[0]->getUntil(), 'the rank held now is open');
        self::assertSame('2025-03-03', $history[1]->getUntil()?->format('Y-m-d'), 'a promotion closes the rank before it');
        self::assertSame('Naomi Kileo', $history[0]->getRecordedBy()?->getFullName());

        $current = $this->holdings()->findCurrentByPeople([$person]);
        self::assertCount(1, $current);
        self::assertSame('SCR', $current[(int) $person->getId()]->getRank()->getShortCode());
    }

    public function testNoRankClosesTheOneHeld(): void
    {
        $rank = $this->ranks()->addRank($this->ranks()->defaultScale(), 'Conservation Ranger I', 'CR I');
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();

        $this->ranks()->assign($joseph, $rank, new \DateTimeImmutable('2024-01-09'), null);
        $this->ranks()->assign($joseph, null, new \DateTimeImmutable('2025-01-01'), null);

        self::assertSame([], $this->holdings()->findCurrentByPeople([$joseph]));
        self::assertCount(1, $this->holdings()->findByPersonNewestFirst($joseph));
    }

    public function testARankSomebodyHeldIsRetiredAndOneNobodyHeldIsDeleted(): void
    {
        $scale = $this->ranks()->defaultScale();
        $held = $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I');
        $never = $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();
        $this->ranks()->assign($joseph, $held, new \DateTimeImmutable('2024-01-09'), null);

        $this->ranks()->remove($held);
        $this->ranks()->remove($never);
        $this->em->clear();

        self::assertSame([], $this->repository()->findActiveOrdered());
        $kept = $this->repository()->findOneBy(['shortCode' => 'CR I']);
        self::assertInstanceOf(Rank::class, $kept);
        self::assertNotNull($kept->getRetiredAt());
        self::assertNull($this->repository()->findOneBy(['shortCode' => 'CR II']));
        self::assertCount(1, $this->em->getRepository(RankHolding::class)->findAll(), 'the history survives');
    }

    public function testHoldersAreCountedPerRankOverTheRanksHeldNow(): void
    {
        $scale = $this->ranks()->defaultScale();
        $one = $this->ranks()->addRank($scale, 'Conservation Ranger I', 'CR I');
        $two = $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');
        $a = $this->person('Anna', 'Sanka');
        $b = $this->person('Baraka', 'Mushi');
        $this->em->flush();
        $this->ranks()->assign($a, $one, new \DateTimeImmutable('2024-01-01'), null);
        $this->ranks()->assign($b, $one, new \DateTimeImmutable('2024-01-01'), null);
        $this->ranks()->assign($b, $two, new \DateTimeImmutable('2025-01-01'), null);

        $counts = $this->holdings()->countCurrentByRank();
        self::assertSame(1, $counts[(int) $one->getId()] ?? 0);
        self::assertSame(1, $counts[(int) $two->getId()] ?? 0);
    }

    public function testARankMovesToAnotherScaleAtItsJuniorEndAndKeepsItsHolders(): void
    {
        $uniformed = $this->ranks()->defaultScale();
        $cri = $this->ranks()->addRank($uniformed, 'Conservation Ranger I', 'CR I');
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->ranks()->addRank($civil, 'Officer I', 'O I');
        $joseph = $this->person('Joseph', 'Mollel');
        $this->em->flush();
        $this->ranks()->assign($joseph, $cri, new \DateTimeImmutable('2024-01-09'), null);

        $this->ranks()->moveRank($cri, $civil);
        $this->em->clear();

        $moved = $this->repository()->findOneBy(['shortCode' => 'CR I']);
        self::assertInstanceOf(Rank::class, $moved);
        self::assertSame('Civil', $moved->getScale()->getName());
        self::assertSame(2, $moved->getSeniority(), 'it lands at the junior end of the target');
        $from = $this->em->getRepository(RankScale::class)->findOneBy(['name' => 'Uniformed']);
        self::assertInstanceOf(RankScale::class, $from);
        self::assertSame([], $this->repository()->findActiveByScale($from), 'nothing live is left where it was');
        self::assertCount(1, $this->em->getRepository(RankHolding::class)->findBy(['rank' => $moved]), 'the holder comes along');
    }

    public function testAMoveIsRefusedWhenTheCodeIsAlreadyOnTheTargetScale(): void
    {
        $uniformed = $this->ranks()->defaultScale();
        $cri = $this->ranks()->addRank($uniformed, 'Conservation Ranger I', 'CR I');
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->ranks()->addRank($civil, 'Clerk I', 'cr i');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already a rank on the Civil scale');
        $this->ranks()->moveRank($cri, $civil);
    }

    public function testAScaleWithALiveRankIsNotRemoved(): void
    {
        $uniformed = $this->ranks()->defaultScale();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $this->ranks()->addRank($civil, 'Officer I', 'O I');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('still has a rank');
        $this->ranks()->removeScale($civil);
    }

    public function testAScaleWhoseRanksWereRetiredIsRetiredWithThemAndReadNoMore(): void
    {
        $uniformed = $this->ranks()->defaultScale();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');
        $oi = $this->ranks()->addRank($civil, 'Officer I', 'O I');
        $anna = $this->person('Anna', 'Sanka');
        $this->em->flush();
        $this->ranks()->assign($anna, $oi, new \DateTimeImmutable('2024-01-09'), null);
        $this->ranks()->remove($oi);

        $this->ranks()->removeScale($civil);
        $this->em->clear();

        $kept = $this->em->getRepository(RankScale::class)->findOneBy(['name' => 'Civil']);
        self::assertInstanceOf(RankScale::class, $kept);
        self::assertTrue($kept->isRetired());
        self::assertSame(['Uniformed'], array_map(static fn (RankScale $s): ?string => $s->getName(), $this->ranks()->scales()));
        self::assertCount(1, $this->em->getRepository(RankHolding::class)->findAll(), 'the history survives');
    }

    public function testAScaleThatNeverHadARankIsDeleted(): void
    {
        $this->ranks()->defaultScale();
        $civil = $this->ranks()->addScale('Civil', 'Uniformed');

        $this->ranks()->removeScale($civil);
        $this->em->clear();

        self::assertNull($this->em->getRepository(RankScale::class)->findOneBy(['name' => 'Civil']));
    }

    public function testTheLastScaleIsNotRemoved(): void
    {
        $only = $this->ranks()->defaultScale();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('last scale');
        $this->ranks()->removeScale($only);
    }

    private function ranks(): RankService
    {
        return $this->service(RankService::class);
    }

    private function repository(): RankRepository
    {
        return $this->service(RankRepository::class);
    }

    private function holdings(): RankHoldingRepository
    {
        return $this->service(RankHoldingRepository::class);
    }

    private function person(string $first, string $last): User
    {
        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }
}
