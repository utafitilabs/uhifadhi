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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Bundle\TeamBundle\Exception\UnknownGrantException;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * WHAT A POSITION BECOMES when it is created, renamed or granted — asked of
 * the objects, with no database in the room.
 *
 * A POSITION USED TO BE FILED UNDER A DEPARTMENT, and the ruling replaced that
 * with a bare name that is unique across the whole organization: a department
 * is where somebody is placed, not something that owns a post. So the suite
 * asks for the name and nothing else, and the two facts the entity now
 * enforces about a post — how many may hold it, and which kinds of placement
 * it offers — are specified here beside the service that shapes it, because
 * they are refusals rather than storage and no database can see them.
 *
 * A GRANT IS A (CONCERN, VERB) PAIR. The service used to take flat permission
 * values and write them flat; the ruling replaced that with
 * pairs validated against what the installation DECLARES, so the suite asks
 * for pairs and the refusal is about a pair nobody declared.
 *
 * The catalogue here is a real one with no modules installed, which is the
 * state of a fresh installation: this bundle's own four concerns and nothing
 * else. That is exactly the state in which "a pair nothing declares is
 * refused" has to hold.
 */
#[CoversClass(PositionService::class)]
#[CoversClass(Position::class)]
final class PositionServiceTest extends TestCase
{
    public function testAPositionIsCreatedByNameAlone(): void
    {
        $position = self::service()->create('Analyst');

        self::assertSame('Analyst', $position->getName());
    }

    /**
     * THE NAME IS UNIQUE ACROSS THE ORGANIZATION. There is one Analyst, not
     * one per department, so the second one is refused wherever it is written
     * from. The unique index is what actually refuses; what is asked here is
     * that the service carries the driver's violation out as a fact about the
     * org chart, which is the only form a caller can word.
     */
    public function testASecondPositionOfTheSameNameAnywhereIsRefused(): void
    {
        $service = new PositionService(self::entityManagerThatRefusesTheSecondWrite(), self::catalogue(), self::holders());
        $service->create('Analyst');

        $this->expectException(NameNotUniqueException::class);

        $service->create('Analyst');
    }

    /** A NEW POSITION IS BORN EMPTY, and the day it was born is the day it fell vacant. */
    public function testANewPositionGrantsNothingAndHasStoodEmptySinceItWasWritten(): void
    {
        $position = self::service()->create('Analyst');

        self::assertSame([], $position->getGrantValues());
        self::assertNotNull($position->getVacantSince());
    }

    public function testRenamingChangesOnlyTheName(): void
    {
        $position = self::service()->create('Analyst');
        self::service()->setGrants($position, [self::CONFIGURE_POSITIONS]);

        self::service()->rename($position, 'Senior Analyst');

        self::assertSame('Senior Analyst', $position->getName());
        self::assertSame([self::CONFIGURE_POSITIONS], $position->getGrantValues());
    }

    public function testRenamingOntoANameTheOrganizationAlreadyUsesIsRefused(): void
    {
        $service = new PositionService(self::entityManagerThatRefusesTheSecondWrite(), self::catalogue(), self::holders());
        $position = $service->create('Analyst');

        $this->expectException(NameNotUniqueException::class);

        $service->rename($position, 'Ranger');
    }

    public function testTheGrantIsReplacedWholesaleBecauseWhatIsAbsentWasRevoked(): void
    {
        $position = self::service()->create('Analyst');

        self::service()->setGrants($position, [self::CONFIGURE_POSITIONS]);
        self::assertSame([self::CONFIGURE_POSITIONS], $position->getGrantValues());

        self::service()->setGrants($position, []);
        self::assertSame([], $position->getGrantValues());
    }

    public function testAPairNoInstalledModuleDeclaresIsRefusedRatherThanStored(): void
    {
        $position = self::service()->create('Analyst');

        $this->expectException(UnknownGrantException::class);

        self::service()->setGrants($position, ['sightings.record']);
    }

    /**
     * AND A PAIR WHOSE CONCERN IS DECLARED BUT WHOSE VERB IS NOT. Positions
     * supports read and configure; a `positions.delete` nothing declares is a
     * grant that would mean nothing, and the write refuses it for the same
     * reason the matrix draws no cell for it.
     */
    public function testAVerbTheConcernDoesNotDeclareIsRefusedToo(): void
    {
        $position = self::service()->create('Analyst');

        $this->expectException(UnknownGrantException::class);

        self::service()->setGrants($position, [TeamConcerns::POSITIONS.'.delete']);
    }

    // ─── HOW MANY MAY HOLD IT ────────────────────────────────────────────

    /** Null is unlimited, and it is the shape a position is written in. */
    public function testAPositionSeatsAsManyPeopleAsTheWorkNeedsUntilSomebodySaysOtherwise(): void
    {
        $position = self::service()->create('Analyst');

        self::assertNull($position->getSeatCount());
        self::assertTrue($position->hasUnlimitedSeats());

        $position->setSeatCount(2);
        self::assertSame(2, $position->getSeatCount());
        self::assertFalse($position->hasUnlimitedSeats());
    }

    /**
     * A POST WITH NO SEATS IS NOT A POST. Closing one is deactivating it, and
     * seating nobody would read as "nobody may hold this" while leaving it on
     * every picker.
     */
    public function testAPositionWithNoSeatAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Position()->setSeatCount(0);
    }

    public function testANegativeNumberOfSeatsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Position()->setSeatCount(-1);
    }

    // ─── WHICH KINDS OF PLACEMENT IT OFFERS ──────────────────────────────

    public function testAPositionOffersTheGroundItWasWrittenFor(): void
    {
        $position = new Position()->setAllowedKinds([ScopeKind::Area]);

        self::assertSame([ScopeKind::Area], $position->getAllowedKinds());
        self::assertTrue($position->allows(ScopeKind::Area));
        self::assertFalse($position->allows(ScopeKind::Organization), 'A local post cannot be widened by mistake when somebody is assigned.');
    }

    /** A position nobody can be placed at is a position nobody can hold. */
    public function testAPositionThatAllowsNoKindOfPlacementAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Position()->setAllowedKinds([]);
    }

    /**
     * A PLACEMENT IS MADE AT THE ORGANIZATION OR AT NAMED AREAS, and those are
     * the only two kinds a position gates. A department is the placement's
     * OTHER dimension — somebody is placed against departments, not at one —
     * so a position that claimed to allow it would be gating a thing it has no
     * say over.
     */
    public function testADepartmentIsNotAKindOfGroundAPositionCanAllow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Position()->setAllowedKinds([ScopeKind::Department]);
    }

    /** And "own" is a scope a CONCERN offers, never a way of placing somebody. */
    public function testOwnIsNotAKindOfPlacementEither(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Position()->setAllowedKinds([ScopeKind::Own]);
    }

    /** The pair this suite grants: composing what a position grants. */
    private const string CONFIGURE_POSITIONS = TeamConcerns::POSITIONS.'.configure';

    private static function service(): PositionService
    {
        return new PositionService(self::createStub(EntityManagerInterface::class), self::catalogue(), self::holders());
    }

    /**
     * NOBODY HOLDS ANYTHING, which is the state every case here is about: a
     * position's name and its grant are written the same way whether or not
     * somebody stands in it. The seat floor and the refusal to retire a held
     * position are the two writes that DO ask, and they are driven through
     * the real repository in the integration suite.
     */
    private static function holders(): UserRepository
    {
        $users = self::createStub(UserRepository::class);
        $users->method('findActiveHolders')->willReturn([]);

        return $users;
    }

    /**
     * A FRESH INSTALLATION: the team's own declaration and no module. The
     * catalogue walks its sources rather than holding a list, so this is the
     * real one with one real source in it.
     */
    private static function catalogue(): ConcernCatalogue
    {
        return new ConcernCatalogue([new TeamConcerns()]);
    }

    /**
     * ONE NAME GOES IN, THE SECOND HITS THE INDEX — the storage layer played
     * by the only thing it contributes to this question: a unique-constraint
     * violation on the write that repeats a name.
     */
    private static function entityManagerThatRefusesTheSecondWrite(): EntityManagerInterface
    {
        $written = 0;
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(static function () use (&$written): void {
            if (++$written > 1) {
                throw new UniqueConstraintViolationException(self::createStub(DriverException::class), null);
            }
        });

        return $entityManager;
    }
}
