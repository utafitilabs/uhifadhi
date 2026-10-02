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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Identity;

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * ONE PERSON, ONE POSITION — AND ONE PLACEMENT BESIDE IT.
 *
 * Multi-position was rejected: a union of grants across two positions is a
 * different model with its own open verdicts. The reason usually offered for
 * wanting a second position — somebody who serves two departments — is not a
 * reason any more. A department used to hang off the position, so serving two
 * meant holding two; the ruling moved the department onto the person's
 * {@see Placement}, where several are allowed. So the Analyst supporting
 * Ecology and Protection is one person, one position, one placement naming
 * two departments.
 *
 * These tests lock both single associations in as decisions, so a later
 * collection would fail loudly here rather than silently widen the model.
 */
final class OnePositionPerPersonTest extends IntegrationTestCase
{
    private function person(): User
    {
        return new User()
            ->setEmail('dw@example.test')
            ->setFirstName('Daniel')->setLastName('Wanjala')
            ->setPassword('x')->setVerified(true);
    }

    /**
     * THE ASSOCIATION IS SINGLE-VALUED — a to-one, not a to-many. This is the
     * structural fact the ruling rests on, asserted against the mapping so a
     * refactor to a collection cannot pass unnoticed.
     */
    public function testThePositionAssociationIsToOne(): void
    {
        $metadata = $this->em->getClassMetadata(User::class);

        self::assertTrue($metadata->hasAssociation('position'));
        self::assertTrue(
            $metadata->isSingleValuedAssociation('position'),
            'A person holds exactly one position — the association must stay single-valued.',
        );
        self::assertFalse($metadata->isCollectionValuedAssociation('position'));
    }

    /**
     * AND SO IS THE PLACEMENT. Breadth lives inside the one row — several
     * areas, several departments — rather than in several rows, so that
     * "where does this person reach?" has one place to read and one place to
     * edit.
     */
    public function testThePlacementAssociationIsAlsoToOne(): void
    {
        $metadata = $this->em->getClassMetadata(User::class);

        self::assertTrue($metadata->hasAssociation('placement'));
        self::assertTrue(
            $metadata->isSingleValuedAssociation('placement'),
            'A person is placed once; the placement itself holds the breadth.',
        );
        self::assertFalse($metadata->isCollectionValuedAssociation('placement'));
    }

    /** Assigning a second position REPLACES the first — there is no accumulation. */
    public function testAssigningAPositionReplacesThePrevious(): void
    {
        $analyst = new Position()->setName('Analyst');
        $ranger = new Position()->setName('Ranger');
        foreach ([$analyst, $ranger] as $entity) {
            $this->em->persist($entity);
        }

        $person = $this->person()->setPosition($analyst);
        $this->em->persist($person);
        $this->em->flush();

        $person->setPosition($ranger);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneBy(['email' => 'dw@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertInstanceOf(Position::class, $stored->getPosition());
        self::assertSame('Ranger', $stored->getPosition()->getName());
    }

    /**
     * A PERSON'S DEPARTMENTS FOLLOW THEIR PLACEMENT, NOT THEIR POSITION. This
     * used to be the other way round — the department was read through
     * `position.department` — and the consequence was that changing somebody's
     * job silently moved them between departments. Now the two facts are
     * independent: the same placement survives a change of position, and
     * several departments at once is an ordinary state rather than an
     * impossible one.
     */
    public function testTheDepartmentsFollowThePlacementAndSurviveAChangeOfPosition(): void
    {
        $ecology = new Department()->setName('Ecology');
        $protection = new Department()->setName('Protection Service');
        $ecologist = new Position()->setName('Ecologist');
        $analyst = new Position()->setName('Analyst');
        foreach ([$ecology, $protection, $ecologist, $analyst] as $entity) {
            $this->em->persist($entity);
        }

        $placement = new Placement()->acrossTheOrganization()->inDepartment($ecology)->supporting([$protection]);
        $this->em->persist($placement);

        $person = $this->person()->setPosition($ecologist)->setPlacement($placement);
        $this->em->persist($person);
        $this->em->flush();

        self::assertSame(['Ecology', 'Protection Service'], $this->departmentNames($person));

        $person->setPosition($analyst);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneBy(['email' => 'dw@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Analyst', $stored->getPosition()?->getName());
        self::assertSame(
            ['Ecology', 'Protection Service'],
            $this->departmentNames($stored),
            'the job changed; where the person is placed did not',
        );
    }

    /**
     * UNPLACED IS A STATE, AND IT IS EMPTY RATHER THAN OPEN: no department,
     * nothing supported, and the label has nothing to say.
     */
    public function testAPersonWhoHasNotBeenPlacedIsInNoDepartment(): void
    {
        $person = $this->person();
        $this->em->persist($person);
        $this->em->flush();

        self::assertNull($person->getPosition());
        self::assertNull($person->getPlacement());
        self::assertNull($person->getDepartment());
        self::assertSame([], $person->getSupportedDepartments());
        self::assertNull($person->getDepartmentLabel());
    }

    /**
     * Their department, then the ones they support.
     *
     * @return list<string>
     */
    private function departmentNames(User $user): array
    {
        return array_map(
            static fn (Department $d): string => (string) $d->getName(),
            [...(null === $user->getDepartment() ? [] : [$user->getDepartment()]), ...$user->getSupportedDepartments()],
        );
    }
}
