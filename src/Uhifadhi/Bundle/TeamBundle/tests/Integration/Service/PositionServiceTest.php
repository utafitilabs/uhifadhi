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
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT REACHES THE TABLE when a position is shaped — and, above all, WHERE its
 * name has to be unique.
 *
 * That last one is the whole reason this suite has a database. THE INDEX USED
 * TO BE ON (department, name), so the same word in two departments was two
 * different jobs; the ruling took the department off the position, and the
 * index is now on the name alone. There is one Analyst in the organization,
 * and no amount of reasoning about objects proves which of those the schema
 * believes.
 */
#[CoversClass(PositionService::class)]
final class PositionServiceTest extends IntegrationTestCase
{
    public function testACreatedPositionIsStoredUnderItsBareName(): void
    {
        $this->positions()->create('Analyst');

        $stored = $this->stored()->findAllOrdered();
        self::assertCount(1, $stored);
        self::assertSame('Analyst', $stored[0]->getName());
    }

    /** The organization has one of each name, and the index is what says so. */
    public function testTheSameNameTwiceAnywhereInTheOrganizationIsRefused(): void
    {
        $this->positions()->create('Analyst');

        $this->expectException(NameNotUniqueException::class);

        $this->positions()->create('Analyst');
    }

    public function testTwoPositionsOfDifferentNamesBothStand(): void
    {
        $this->positions()->create('Analyst');
        $this->positions()->create('Ranger');

        self::assertSame(['Analyst', 'Ranger'], array_map(
            static fn (Position $p): ?string => $p->getName(),
            $this->stored()->findAllOrdered(),
        ));
    }

    public function testRenamingIsStored(): void
    {
        $position = $this->positions()->create('Analyst');

        $this->positions()->rename($position, 'Senior Analyst');

        self::assertSame('Senior Analyst', $this->stored()->findAllOrdered()[0]->getName());
    }

    public function testRenamingOntoANameTheOrganizationAlreadyUsesIsRefused(): void
    {
        $this->positions()->create('Ranger');
        $moving = $this->positions()->create('Analyst');

        $this->expectException(NameNotUniqueException::class);

        $this->positions()->rename($moving, 'Ranger');
    }

    /** A grant is a (concern, verb) pair, and the JSON column holds it as written. */
    public function testAGrantIsStoredAndReadBack(): void
    {
        $position = $this->positions()->create('Analyst');
        $pair = TeamConcerns::POSITIONS.'.read';

        $this->positions()->setGrants($position, [$pair]);

        self::assertSame([$pair], $this->stored()->findAllOrdered()[0]->getGrantValues());
    }

    /**
     * HOW MANY MAY HOLD IT SURVIVES THE ROUND TRIP — a JSON-free integer
     * column, but one whose null means unlimited rather than unset, and a
     * mapping that lost that distinction would read every singular post as
     * uncapped.
     */
    public function testTheSeatCountIsStoredAndNullStillMeansUnlimited(): void
    {
        $head = $this->positions()->create('Head of Protection');
        $head->setSeatCount(1);
        $this->positions()->create('Data Analyst');
        $this->em->flush();

        $stored = $this->stored()->findAllOrdered();
        $byName = [];
        foreach ($stored as $position) {
            $byName[(string) $position->getName()] = $position;
        }

        self::assertSame(1, $byName['Head of Protection']->getSeatCount());
        self::assertTrue($byName['Data Analyst']->hasUnlimitedSeats());
    }

    private function positions(): PositionService
    {
        return $this->service(PositionService::class);
    }

    private function stored(): PositionRepository
    {
        $this->em->clear();

        return $this->service(PositionRepository::class);
    }
}
