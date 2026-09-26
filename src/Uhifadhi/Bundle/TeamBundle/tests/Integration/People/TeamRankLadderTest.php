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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\People;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\People\TeamRankLadder;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\People\RankLadderInterface;

/**
 * THE LADDER THE LIVE MAP DECIDES WHO SEES WHOM BY, on the real tables: the
 * scales in the Ranks page's order, each scale's ranks from most senior down,
 * retired ranks and retired scales off it, and a person's place the place of
 * the rank they hold NOW.
 */
#[CoversClass(TeamRankLadder::class)]
final class TeamRankLadderTest extends IntegrationTestCase
{
    public function testTheContractIsTheTeamsLadder(): void
    {
        self::assertInstanceOf(TeamRankLadder::class, $this->service(RankLadderInterface::class));
    }

    public function testPlacesFollowTheScaleOrderThenSeniority(): void
    {
        $officers = $this->ranks()->defaultScale();
        $pco = $this->ranks()->addRank($officers, 'Principal Conservation Officer', 'PCO');
        $co = $this->ranks()->addRank($officers, 'Conservation Officer', 'CO');
        $rangers = $this->ranks()->addScale('Rangers', 'Officers');
        $scr = $this->ranks()->addRank($rangers, 'Senior Conservation Ranger', 'SCR');
        $cr = $this->ranks()->addRank($rangers, 'Conservation Ranger', 'CR');

        $chief = $this->holding('Asha', 'Chief', $pco);
        $officer = $this->holding('Baraka', 'Officer', $co);
        $senior = $this->holding('Chausiku', 'Senior', $scr);
        $ranger = $this->holding('Daudi', 'Ranger', $cr);
        $rankless = $this->person('Eliya', 'Volunteer');
        $this->em->flush();

        $places = $this->ladder()->placesOf(self::uuids($chief, $officer, $senior, $ranger, $rankless));

        self::assertSame(1, $places[(string) $chief->getUuidString()]);
        self::assertSame(2, $places[(string) $officer->getUuidString()]);
        self::assertSame(3, $places[(string) $senior->getUuidString()], 'the second scale sits below the whole first scale');
        self::assertSame(4, $places[(string) $ranger->getUuidString()]);
        self::assertArrayNotHasKey((string) $rankless->getUuidString(), $places, 'no rank, no place');
        self::assertSame(4, $this->ladder()->length());
    }

    public function testARankHeldOnceAndReplacedCountsTheCurrentOneOnly(): void
    {
        $scale = $this->ranks()->defaultScale();
        $senior = $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR');
        $junior = $this->ranks()->addRank($scale, 'Conservation Ranger', 'CR');
        $person = $this->person('Fatuma', 'Promoted');
        $this->em->flush();
        $this->ranks()->assign($person, $junior, new \DateTimeImmutable('2024-01-01'), null);
        $this->ranks()->assign($person, $senior, new \DateTimeImmutable('2025-01-01'), null);

        self::assertSame([(string) $person->getUuidString() => 1], $this->ladder()->placesOf(self::uuids($person)));
    }

    public function testARankTakenAwayLeavesThePersonWithoutAPlace(): void
    {
        $rank = $this->ranks()->addRank($this->ranks()->defaultScale(), 'Conservation Ranger', 'CR');
        $person = $this->person('Gabriel', 'Demoted');
        $this->em->flush();
        $this->ranks()->assign($person, $rank, new \DateTimeImmutable('2024-01-01'), null);
        $this->ranks()->assign($person, null, new \DateTimeImmutable('2025-01-01'), null);

        self::assertSame([], $this->ladder()->placesOf(self::uuids($person)));
    }

    public function testARetiredRankIsOffTheLadderAndTheRanksBelowMoveUp(): void
    {
        $scale = $this->ranks()->defaultScale();
        $gone = $this->ranks()->addRank($scale, 'Chief Park Warden', 'CPW');
        $held = $this->ranks()->addRank($scale, 'Conservation Officer', 'CO');
        $holder = $this->holding('Hamisi', 'Officer', $held);
        $formerChief = $this->holding('Imani', 'Former', $gone);
        $this->em->flush();
        $gone->setRetiredAt(new \DateTimeImmutable('2026-01-01'));
        $this->em->flush();

        $places = $this->ladder()->placesOf(self::uuids($holder, $formerChief));

        self::assertSame([(string) $holder->getUuidString() => 1], $places, 'a retired rank places nobody, and the rank below it is now first');
    }

    public function testNoRanksAtAllIsAnEmptyLadder(): void
    {
        $person = $this->person('Juma', 'Anyone');
        $this->em->flush();

        self::assertSame([], $this->ladder()->placesOf(self::uuids($person)));
        self::assertSame(0, $this->ladder()->length());
    }

    private function holding(string $first, string $last, \Uhifadhi\Bundle\TeamBundle\Entity\Rank $rank): User
    {
        $person = $this->person($first, $last);
        $this->em->flush();
        $this->ranks()->assign($person, $rank, new \DateTimeImmutable('2025-01-01'), null);

        return $person;
    }

    /** @return list<string> */
    private static function uuids(User ...$people): array
    {
        return array_values(array_map(static fn (User $u): string => (string) $u->getUuidString(), $people));
    }

    private function ladder(): RankLadderInterface
    {
        return $this->service(RankLadderInterface::class);
    }

    private function ranks(): RankService
    {
        return $this->service(RankService::class);
    }

    private function person(string $first, string $last): User
    {
        $user = (new User())
            ->setEmail(strtolower($first.'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }
}
