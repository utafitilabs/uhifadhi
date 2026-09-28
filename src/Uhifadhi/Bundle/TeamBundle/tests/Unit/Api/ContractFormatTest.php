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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Api\ContractFormat;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE THREE RULES EVERY `/api` DOCUMENT SHARES, stated where they are decided.
 *
 * The endpoints' own suites prove that each document CARRIES these values; this
 * one pins the rules themselves, because they are read by a released client that
 * cannot be redeployed and are the one thing two endpoints must never spell
 * differently.
 */
final class ContractFormatTest extends TestCase
{
    /** UTC, to the second, with a literal Z — never an offset, never microseconds. */
    public function testAMomentIsWrittenInUtcWithALiteralZ(): void
    {
        $moment = new \DateTimeImmutable('2027-03-08T12:41:22.987654+03:00');

        self::assertSame('2027-03-08T09:41:22Z', ContractFormat::timestamp($moment));
    }

    public function testTheServiceNumberIdentifiesSomebodyWhoWasIssuedOne(): void
    {
        $user = new User()->setEmail('w.mbise@example.test')->setRangerCode('sl-0142');

        self::assertSame('sl-0142', ContractFormat::rangerId($user));
    }

    /** Office staff are never issued a service number, and still have to be nameable. */
    public function testTheAddressStandsInWhereThereIsNoServiceNumber(): void
    {
        $user = new User()->setEmail('n.kileo@example.test');

        self::assertSame('n.kileo@example.test', ContractFormat::rangerId($user));
    }

    /**
     * `role` IS THE POSITION where there is one: a refusal screen names the
     * position that lacks the permission, because that is what an administrator
     * has to change.
     */
    public function testTheRoleIsThePositionWhereThereIsOne(): void
    {
        $user = new User()
            ->setEmail('w.mbise@example.test')
            ->setFirstName('Witness')
            ->setLastName('Mbise')
            ->setRangerCode('sl-0142')
            ->setTeamRole(TeamRoleEnum::Staff);
        $user->setPosition(new Position()->setName('Field Ranger'));

        self::assertSame(
            ['id' => 'sl-0142', 'name' => 'Witness Mbise', 'role' => 'Field Ranger'],
            ContractFormat::ranger($user),
        );
    }

    /**
     * NEVER BLANK, AND NEVER A TIER TO STAFF (ruled 28 Sep 2026). An Admin or
     * a Super Admin with no position is named by their tier, which they see;
     * a staff member with none reads "No position".
     */
    public function testSomebodyWithNoPositionIsNamedWithoutATierUnlessTheySeeTiers(): void
    {
        $user = new User()
            ->setEmail('n.kileo@example.test')
            ->setFirstName('Naomi')
            ->setLastName('Kileo')
            ->setTeamRole(TeamRoleEnum::Staff);

        self::assertSame('No position', ContractFormat::ranger($user)['role']);
        self::assertSame('Admin', ContractFormat::ranger($user->setTeamRole(TeamRoleEnum::Admin))['role']);
    }
}
