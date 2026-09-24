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

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Exception\UnknownGrantException;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THERE IS ONE WRITE PATH ONTO A POSITION, AND IT VALIDATES.
 *
 * A typed setter over some fixed list of powers is the obvious thing to reach
 * for and is deliberately absent: it would SILENTLY DISCARD every
 * module-declared pair, because a module's concern is not something this
 * bundle owns. An administrator ticking a module's row and saving would watch
 * it come back unticked, with nothing anywhere saying why.
 *
 * The one that exists is the pair-string surface, and it takes the live
 * catalogue as a second required argument, so it cannot be called without one.
 * The accepted set is:
 *
 *     the live catalogue  ∪  the pairs this position already holds
 *
 * The union is the whole design. The left half is what makes an unknown NEW
 * pair fail loudly instead of being quietly dropped. The right half is the
 * prune-not-purge ruling in code: a module uninstalled last week left grants
 * behind in positions' JSON, those pairs are in nobody's catalogue now, and
 * saving an unrelated change to the position must not silently strip them.
 * Editing a position is not a migration.
 */
final class PositionGrantTest extends IntegrationTestCase
{
    /** @return list<string> */
    private function catalogue(): array
    {
        return $this->service(ConcernCatalogue::class)->pairs();
    }

    /**
     * THERE IS NO TYPED SETTER. Asserted by name, because "not this one, on
     * purpose" is the fact worth keeping — and because one introduced later
     * would pass every other test in this suite.
     */
    public function testThereIsNoTypedSetter(): void
    {
        // Through reflection rather than method_exists(): static analysis knows
        // the literal answer to the latter and narrows the assertion away.
        self::assertFalse(
            new \ReflectionClass(Position::class)->hasMethod('setPermissions'),
            'A typed setter silently discarded every module-declared pair. It does not come back.',
        );
    }

    public function testACoreGrantRoundTrips(): void
    {
        $position = (new Position())->setName('Analyst');
        $position->setGrantValues(['directory.read', 'directory.manage'], $this->catalogue());

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst']);
        self::assertInstanceOf(Position::class, $stored);

        self::assertSame(['directory.read', 'directory.manage'], $stored->getGrantValues());
    }

    /** An invented pair is refused, loudly, and it names itself in the message. */
    public function testAnUnknownNewPairIsRefused(): void
    {
        $position = (new Position())->setName('Analyst');

        $this->expectException(UnknownGrantException::class);
        $this->expectExceptionMessageMatches('/invented\.power/');

        $position->setGrantValues(['directory.read', 'invented.power'], $this->catalogue());
    }

    /**
     * PRUNE, DO NOT PURGE. `vegetation.record` was granted by a module that has
     * since been uninstalled: it is in this position's JSON and in nobody's
     * catalogue. Saving an unrelated change keeps it.
     */
    public function testAnOrphanedGrantSurvivesASaveThatDoesNotTouchIt(): void
    {
        $position = (new Position())->setName('Botanist');
        // How the grant got there: the module was installed at the time.
        $position->setGrantValues(
            ['directory.read', 'vegetation.record'],
            [...$this->catalogue(), 'vegetation.record'],
        );

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Botanist']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertContains('vegetation.record', $stored->getGrantValues());

        // The module is gone now — the catalogue no longer offers the pair. An
        // administrator adds one of the platform's own and saves.
        $stored->setGrantValues(
            ['directory.read', 'vegetation.record', 'positions.read'],
            $this->catalogue(),
        );

        self::assertSame(
            ['directory.read', 'vegetation.record', 'positions.read'],
            $stored->getGrantValues(),
            'An orphan already on the position is accepted; only an unknown NEW pair is refused.',
        );
    }

    /** An orphan can still be taken away — it is a grant, not a fixture. */
    public function testAnOrphanedGrantCanBeRevoked(): void
    {
        $position = (new Position())->setName('Botanist');
        $position->setGrantValues(
            ['directory.read', 'vegetation.record'],
            [...$this->catalogue(), 'vegetation.record'],
        );

        $position->setGrantValues(['directory.read'], $this->catalogue());

        self::assertSame(['directory.read'], $position->getGrantValues());
    }

    /** Ticking the same box twice is one grant. */
    public function testDuplicatesCollapse(): void
    {
        $position = (new Position())->setName('Analyst');
        $position->setGrantValues(['directory.read', 'directory.read'], $this->catalogue());

        self::assertSame(['directory.read'], $position->getGrantValues());
    }
}
