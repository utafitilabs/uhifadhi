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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;

/**
 * Symfony-standard kernel testing: KernelTestCase booting {@see TestKernel} with
 * debug=true, so the container self-invalidates when test config changes. The
 * kernel is named here rather than through a KERNEL_CLASS env var, because one
 * repository holding several packages has more than one kernel to name.
 *
 * The schema is rebuilt per test against the REAL PostGIS database, so every
 * assertion is about what was actually stored — a module whose boundary column
 * was only ever asserted against a mock would be a module nobody has proved
 * persists.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        // ONE DATABASE CARRIES EVERY PACKAGE'S SUITE, and a suite that ran
        // before this one may have left tables this kernel does not map — a
        // table with a foreign key into one this kernel does map blocks the
        // metadata-driven drop and the create then collides. So the schema is
        // taken back to the state a test database starts in: nothing but PostGIS.
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        // Anything the boot left managed belongs to the schema that has just
        // been dropped; ids restart at 1 and a stale object would collide with
        // the first row this test writes.
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        // THE GEOMETRY TABLES DO NOT OUTLIVE THIS SUITE. One database carries
        // every package's suite, and a kernel without the PostGIS bundle in it
        // cannot introspect a `geometry` column — so a table left behind here
        // breaks a sibling's suite the moment somebody runs it on its own.
        new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test and
        // never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /** The NCA-shaped rectangle every spatial assertion here is measured on. */
    protected const string A_BOUNDARY = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';

    /*
     * FOUR SUBDIVISIONS OF THAT RECTANGLE, chosen so the zone invariant can be
     * stated in geometry rather than in prose. West and East split it at -29.5
     * and so SHARE AN EDGE — the legal case that a naive overlap test would
     * wrongly reject. The straddler crosses that line and so shares interior
     * with both. The inner square sits wholly within West, which ST_Overlaps
     * calls false and the invariant calls a conflict.
     */

    /** -30.0–-29.5: the western half. */
    protected const string A_WEST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.5,-3.6],[-29.5,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';

    /** -29.5–-29.0: the eastern half, meeting the western along -29.5. */
    protected const string A_EAST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}';

    /** -29.75–-29.25: crosses the registry, so it shares interior with both halves. */
    protected const string A_STRADDLING_MIDDLE = '{"type":"MultiPolygon","coordinates":[[[[-29.75,-3.6],[-29.25,-3.6],[-29.25,-2.8],[-29.75,-2.8],[-29.75,-3.6]]]]}';

    /** Wholly inside the western half — containment is a conflict, not a nesting. */
    protected const string A_INSIDE_WEST = '{"type":"MultiPolygon","coordinates":[[[[-29.9,-3.5],[-29.6,-3.5],[-29.6,-2.9],[-29.9,-2.9],[-29.9,-3.5]]]]}';

    protected function anArea(string $name = 'Sample Area', string $source = 'WDPA'): AreaOfInterest
    {
        $area = new AreaOfInterest()
            ->setName($name)
            ->setGeom(self::A_BOUNDARY)
            ->setSource($source);

        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /**
     * A zone persisted DIRECTLY, bypassing the invariant in
     * {@see \Uhifadhi\Bundle\AreaBundle\Service\ZoneService} — for the tests that are about
     * storage (the table, the cascade, the unique name) rather than about the
     * rule. Tests of the rule go through the service, which is the only
     * supported way to give a zone a geometry.
     */
    protected function aZone(AreaOfInterest $area, string $name, string $geom): Zone
    {
        $zone = new Zone()
            ->setArea($area)
            ->setName($name)
            ->setGeom($geom);

        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }

    /**
     * A FIXTURE THAT WRITES PINGS STRAIGHT TO THE TABLE FOLDS THEM INTO THEIR
     * ROWS the way the write service does — the reads judge from the row.
     */
    protected function foldPings(PersonPosition ...$pings): void
    {
        $this->presenceFacts()->recordPings(array_values($pings));
    }

    /** And a claim's own position, set on the row by hand. */
    protected function foldClaimFix(CheckIn $checkIn): void
    {
        $this->presenceFacts()->recordClaimFix($checkIn);
    }

    /** And a correction written by hand: the watch is re-measured. */
    protected function remeasure(CheckIn $checkIn): void
    {
        $this->presenceFacts()->remeasure($checkIn);
    }

    private function presenceFacts(): PresenceFactsService
    {
        $facts = static::getContainer()->get('test_public.area.presence_facts');
        \assert($facts instanceof PresenceFactsService);

        return $facts;
    }
}
