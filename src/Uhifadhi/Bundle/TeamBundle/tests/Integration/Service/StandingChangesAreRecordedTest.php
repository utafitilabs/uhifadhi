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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;
use Uhifadhi\Bundle\TeamBundle\Service\RankService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * EVERY CHANGE TO A PERSON'S STANDING LEAVES A LINE — their seat, their
 * placement, their tier and their rank — for every person, a Super Admin
 * changing their own included, naming who changed what from what to what.
 * A change somebody makes to themselves says so.
 */
#[CoversClass(RankService::class)]
#[CoversClass(UserService::class)]
#[CoversClass(ChangeLine::class)]
final class StandingChangesAreRecordedTest extends IntegrationTestCase
{
    public function testARankGivenChangedAndTakenAwayLeavesALineEachTime(): void
    {
        $scale = $this->ranks()->defaultScale();
        $cr = $this->ranks()->addRank($scale, 'Conservation Ranger II', 'CR II');
        $scr = $this->ranks()->addRank($scale, 'Senior Conservation Ranger', 'SCR');
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $thabo = $this->person('Thabo', 'Ndlovu');

        $this->ranks()->assign($thabo, $cr, new \DateTimeImmutable('2026-01-09'), $naomi);
        $this->ranks()->assign($thabo, $cr, new \DateTimeImmutable('2026-02-09'), $naomi);
        $this->ranks()->assign($thabo, $scr, new \DateTimeImmutable('2026-03-03'), $naomi);
        $this->ranks()->assign($thabo, null, new \DateTimeImmutable('2026-04-01'), $naomi);

        self::assertSame(
            [
                ['Naomi Kileo', 'Thabo Ndlovu', 'no rank', 'Conservation Ranger II'],
                ['Naomi Kileo', 'Thabo Ndlovu', 'Conservation Ranger II', 'Senior Conservation Ranger'],
                ['Naomi Kileo', 'Thabo Ndlovu', 'Senior Conservation Ranger', 'no rank'],
            ],
            array_map(static fn (PermissionChange $c): array => [$c->getByName(), $c->getSubjectName(), $c->getBefore(), $c->getAfter()], $this->lines(PermissionChangeEnum::RankChanged)),
            'keeping the rank they hold is no change',
        );
    }

    public function testARankNobodyHeldTakenAwayIsNoChange(): void
    {
        $thabo = $this->person('Thabo', 'Ndlovu');

        $this->ranks()->assign($thabo, null, new \DateTimeImmutable('2026-04-01'), null);

        self::assertSame([], $this->lines(PermissionChangeEnum::RankChanged));
    }

    /** A SUPER ADMIN MAY CHANGE THEIR OWN STANDING, AND EVERY CHANGE IS WRITTEN DOWN. */
    public function testASuperAdminChangingThemselvesLeavesALineForEachChange(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $rank = $this->ranks()->addRank($this->ranks()->defaultScale(), 'Chief Park Warden', 'CPW');
        $warden = new Position()->setName('Chief warden');
        $this->em->persist($warden);
        $this->em->flush();

        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($naomi, 'main', $naomi->getRoles()));

        $this->ranks()->assign($naomi, $rank, new \DateTimeImmutable('2026-11-01'), $naomi);
        $this->accounts()->assignPosition($naomi, $warden);
        $this->accounts()->place($naomi, new Placement()->acrossTheOrganization());

        $lines = $this->lines();
        self::assertSame(
            [PermissionChangeEnum::RankChanged, PermissionChangeEnum::Seated, PermissionChangeEnum::Placed],
            array_map(static fn (PermissionChange $c): PermissionChangeEnum => $c->getAction(), $lines),
        );
        foreach ($lines as $line) {
            self::assertSame($naomi->getId(), $line->getById(), $line->getAction()->value);
            self::assertSame($naomi->getId(), $line->getSubjectId(), $line->getAction()->value);
        }

        self::assertSame('changed their own rank to Chief Park Warden', ChangeLine::of($lines[0])->lead);
        self::assertSame('seated themselves as Chief warden', ChangeLine::of($lines[1])->lead);
        self::assertSame('', ChangeLine::of($lines[0])->on, 'the line names nobody else');
    }

    public function testATierChangedOnThemselvesSaysSo(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Desta', 'Haile', TeamRoleEnum::SuperAdmin);

        $this->accounts()->changeTier($naomi, TeamRoleEnum::Admin, $naomi);

        $line = ChangeLine::of($this->lines(PermissionChangeEnum::TierChanged)[0]);
        self::assertSame('Naomi Kileo', $line->by);
        self::assertSame('changed their own tier to Admin', $line->lead);
    }

    public function testAChangeToSomebodyElseNamesThem(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $thabo = $this->person('Thabo', 'Ndlovu');
        $rank = $this->ranks()->addRank($this->ranks()->defaultScale(), 'Conservation Ranger II', 'CR II');

        $this->ranks()->assign($thabo, $rank, new \DateTimeImmutable('2026-11-01'), $naomi);

        $line = ChangeLine::of($this->lines(PermissionChangeEnum::RankChanged)[0]);
        self::assertSame('changed the rank of', $line->lead);
        self::assertSame('Thabo Ndlovu', $line->on);
        self::assertSame([['was', 'no rank'], ['now', 'Conservation Ranger II']], $line->diff);
    }

    /** @return list<PermissionChange> */
    private function lines(?PermissionChangeEnum $action = null): array
    {
        $this->em->flush();

        return $this->em->getRepository(PermissionChange::class)->findBy(null === $action ? [] : ['action' => $action], ['id' => 'ASC']);
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = new User()->setEmail(strtolower($first.'.'.$last).'@example.test')->setFirstName($first)->setLastName($last)
            ->setPassword('x')->setTeamRole($tier)->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function ranks(): RankService
    {
        return $this->service(RankService::class);
    }

    private function accounts(): UserService
    {
        return $this->service(UserService::class);
    }
}
