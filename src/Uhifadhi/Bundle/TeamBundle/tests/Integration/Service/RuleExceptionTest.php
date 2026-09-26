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
use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\RuleExceptionRefusedException;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * AN EXCEPTION TO A RULE IS GIVEN ON ITS OWN, BY A SUPER ADMIN, WITH A REASON
 * (ruled 2026-09-26).
 *
 * The control room's grant, "Live locations", lifts the rank rule: whoever
 * holds it sees every live position, the chief's included. So it is not a
 * box among boxes. What this suite proves at the table:
 *
 *  - only a Super Admin gives or takes it — an Admin, who may compose every
 *    other grant, may not;
 *  - it is refused without a written reason, and the reason is stored with
 *    who gave it and when;
 *  - taking it away keeps the row, stamped with who and when, so the seat's
 *    history still says it once saw everybody and why;
 *  - the matrix's own save can neither add it nor, by leaving it out, remove it;
 *  - the ordinary write refuses an exception and the exception write refuses
 *    an ordinary pair, so each lives on exactly one card.
 */
#[CoversClass(PositionService::class)]
#[CoversClass(GrantJustification::class)]
#[CoversClass(GrantJustificationRepository::class)]
final class RuleExceptionTest extends IntegrationTestCase
{
    private const string PAIR = 'locations.read';
    private const string REASON = 'The radio room at headquarters coordinates every rescue and must see every ranger.';

    public function testASuperAdminGivesItWithAReasonAndTheRowSaysWhoAndWhen(): void
    {
        $room = $this->aPosition('Radio Operator');
        $naomi = $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin);

        $this->positions()->grantException($room, self::PAIR, '  '.self::REASON.' ', $naomi, new \DateTimeImmutable('2026-09-26 08:00:00'));

        $this->em->clear();
        $stored = $this->reload($room);
        self::assertContains(self::PAIR, $stored->getGrantValues());

        $current = $this->justifications()->findCurrentByPosition($stored);
        self::assertArrayHasKey(self::PAIR, $current);
        self::assertSame(self::REASON, $current[self::PAIR]->getReason(), 'the reason is stored trimmed');
        self::assertSame('Naomi Example', $current[self::PAIR]->getGrantedByName());
        self::assertSame($naomi->getId(), $current[self::PAIR]->getGrantedBy()?->getId());
        self::assertSame('2026-09-26 08:00:00', $current[self::PAIR]->getGrantedAt()->format('Y-m-d H:i:s'));
        self::assertNull($current[self::PAIR]->getRevokedAt());
    }

    /** @return iterable<string, array{TeamRoleEnum}> */
    public static function notASuperAdmin(): iterable
    {
        yield 'an Admin, who composes every other grant' => [TeamRoleEnum::Admin];
        yield 'a staff member, whatever their position' => [TeamRoleEnum::Staff];
    }

    #[DataProvider('notASuperAdmin')]
    public function testNobodyButASuperAdminMayGiveIt(TeamRoleEnum $tier): void
    {
        $room = $this->aPosition('Radio Operator');
        $actor = $this->aPerson('Amani', $tier);

        try {
            $this->positions()->grantException($room, self::PAIR, self::REASON, $actor);
            self::fail('a '.$tier->label().' gave an exception to the rank rule');
        } catch (RuleExceptionRefusedException $refusal) {
            self::assertStringContainsString('Super Admin', $refusal->getMessage());
        }

        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());
        self::assertSame([], $this->justifications()->findCurrentByPosition($this->reload($room)));
    }

    #[DataProvider('notASuperAdmin')]
    public function testNobodyButASuperAdminMayTakeItAway(TeamRoleEnum $tier): void
    {
        $room = $this->aPosition('Radio Operator');
        $this->positions()->grantException($room, self::PAIR, self::REASON, $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin));

        $this->expectException(RuleExceptionRefusedException::class);

        $this->positions()->revokeException($room, self::PAIR, $this->aPerson('Amani', $tier));
    }

    /** @return iterable<string, array{string}> */
    public static function noReason(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \n\t "];
        yield 'too short to be a reason' => ['ok'];
    }

    #[DataProvider('noReason')]
    public function testItIsRefusedWithoutAWrittenReason(string $reason): void
    {
        $room = $this->aPosition('Radio Operator');

        try {
            $this->positions()->grantException($room, self::PAIR, $reason, $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin));
            self::fail('an exception was given without a reason');
        } catch (RuleExceptionRefusedException $refusal) {
            self::assertStringContainsString('reason', $refusal->getMessage());
        }

        $this->em->clear();
        self::assertNotContains(self::PAIR, $this->reload($room)->getGrantValues());
    }

    public function testAnOrdinaryPairIsNotGivenOnTheExceptionsCard(): void
    {
        $room = $this->aPosition('Radio Operator');

        $this->expectException(RuleExceptionRefusedException::class);

        $this->positions()->grantException($room, 'stations.read', self::REASON, $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin));
    }

    public function testASeatThatAlreadyHoldsItIsNotGivenItTwice(): void
    {
        $room = $this->aPosition('Radio Operator');
        $naomi = $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin);
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);

        $this->expectException(RuleExceptionRefusedException::class);

        $this->positions()->grantException($room, self::PAIR, 'A second reason for the same seat.', $naomi);
    }

    /**
     * TAKEN AWAY, NOT FORGOTTEN: the pair leaves the seat and the row stays,
     * stamped, so the seat's history still says it once saw everybody and why.
     */
    public function testTakingItAwayKeepsTheRowStampedWithWhoAndWhen(): void
    {
        $room = $this->aPosition('Radio Operator');
        $naomi = $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin);
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi, new \DateTimeImmutable('2026-09-26 08:00:00'));

        $this->positions()->revokeException($room, self::PAIR, $naomi, new \DateTimeImmutable('2026-09-27 17:30:00'));

        $this->em->clear();
        $stored = $this->reload($room);
        self::assertNotContains(self::PAIR, $stored->getGrantValues());
        self::assertSame([], $this->justifications()->findCurrentByPosition($stored));

        $history = $this->justifications()->findByPositionNewestFirst($stored);
        self::assertCount(1, $history);
        self::assertSame('2026-09-27 17:30:00', $history[0]->getRevokedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Naomi Example', $history[0]->getRevokedByName());
        self::assertSame(self::REASON, $history[0]->getReason());
    }

    public function testGivenAgainAfterwardsTheHistoryHoldsBothNewestFirst(): void
    {
        $room = $this->aPosition('Radio Operator');
        $naomi = $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin);
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi, new \DateTimeImmutable('2026-09-01 08:00:00'));
        $this->positions()->revokeException($room, self::PAIR, $naomi, new \DateTimeImmutable('2026-09-10 08:00:00'));
        $this->positions()->grantException($room, self::PAIR, 'Back for the pilot: the radio room coordinates rescues again.', $naomi, new \DateTimeImmutable('2026-09-20 08:00:00'));

        $this->em->clear();
        $history = $this->justifications()->findByPositionNewestFirst($this->reload($room));

        self::assertSame(['2026-09-20', '2026-09-01'], array_map(static fn (GrantJustification $j): string => $j->getGrantedAt()->format('Y-m-d'), $history));
        self::assertNull($history[0]->getRevokedAt());
    }

    public function testTakingAwayWhatTheSeatDoesNotHoldIsRefused(): void
    {
        $room = $this->aPosition('Radio Operator');

        $this->expectException(RuleExceptionRefusedException::class);

        $this->positions()->revokeException($room, self::PAIR, $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin));
    }

    /** The matrix posts only what it draws, and it never draws an exception. */
    public function testTheMatrixSaveCannotAddIt(): void
    {
        $room = $this->aPosition('Radio Operator');

        try {
            $this->positions()->setGrants($room, ['stations.read', self::PAIR]);
            self::fail('the matrix save added an exception');
        } catch (RuleExceptionRefusedException $refusal) {
            self::assertStringContainsString('its own card', $refusal->getMessage());
        }

        $this->em->clear();
        self::assertSame([], $this->reload($room)->getGrantValues());
    }

    public function testTheMatrixSaveLeavingItOutDoesNotRemoveIt(): void
    {
        $room = $this->aPosition('Radio Operator');
        $this->positions()->grantException($room, self::PAIR, self::REASON, $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin));

        $this->positions()->setGrants($room, ['stations.read']);

        $this->em->clear();
        $stored = $this->reload($room);
        self::assertEqualsCanonicalizing(['stations.read', self::PAIR], $stored->getGrantValues());
        self::assertArrayHasKey(self::PAIR, $this->justifications()->findCurrentByPosition($stored));
    }

    public function testTheReviewListNamesEveryCurrentExceptionAndNoTakenOne(): void
    {
        $naomi = $this->aPerson('Naomi', TeamRoleEnum::SuperAdmin);
        $room = $this->aPosition('Radio Operator');
        $warden = $this->aPosition('Chief Warden');
        $this->positions()->grantException($room, self::PAIR, self::REASON, $naomi);
        $this->positions()->grantException($warden, self::PAIR, 'The chief warden answers for every rescue in the organization.', $naomi);
        $this->positions()->revokeException($warden, self::PAIR, $naomi);

        $this->em->clear();
        $current = $this->justifications()->findAllCurrent();

        self::assertSame(['Radio Operator'], array_map(static fn (GrantJustification $j): ?string => $j->getPosition()->getName(), $current));
    }

    private function aPosition(string $name): Position
    {
        $position = $this->positions()->create($name);

        return $position;
    }

    private function aPerson(string $first, TeamRoleEnum $tier): User
    {
        $person = new User()
            ->setEmail(mb_strtolower($first).'.'.bin2hex(random_bytes(3)).'@example.test')
            ->setFirstName($first)
            ->setLastName('Example')
            ->setPassword('x')
            ->setTeamRole($tier);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function reload(Position $position): Position
    {
        $fresh = $this->em->getRepository(Position::class)->find((int) $position->getId());
        self::assertInstanceOf(Position::class, $fresh);

        return $fresh;
    }

    private function positions(): PositionService
    {
        return $this->service(PositionService::class);
    }

    private function justifications(): GrantJustificationRepository
    {
        return $this->service(GrantJustificationRepository::class);
    }
}
