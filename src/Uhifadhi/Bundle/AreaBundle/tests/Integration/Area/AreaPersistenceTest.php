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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Area;

use Symfony\Component\Uid\UuidV7;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * WHAT AN AREA ACTUALLY IS, once, against a real PostGIS database: a name, a
 * boundary, where the boundary came from, and a public identifier that is not
 * the primary key.
 */
final class AreaPersistenceTest extends IntegrationTestCase
{
    public function testABoundaryRoundTripsThroughPostGis(): void
    {
        $area = $this->anArea('Northern Conservation Reserve');
        $id = $area->getId();
        self::assertNotNull($id);

        $this->em->clear();
        $reloaded = $this->em->find(AreaOfInterest::class, $id);

        self::assertInstanceOf(AreaOfInterest::class, $reloaded);
        self::assertSame('Northern Conservation Reserve', $reloaded->getName());
        self::assertSame('WDPA', $reloaded->getSource());

        $geom = $reloaded->getGeom();
        self::assertNotNull($geom);
        /** @var array{type?: string, coordinates?: array<mixed>} $decoded */
        $decoded = json_decode($geom, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('MultiPolygon', $decoded['type'] ?? null);
    }

    /**
     * ADDRESSED BY UUID, NEVER BY THE SEQUENTIAL KEY. Every URL and every API
     * response names an area by its uuid; the integer exists so foreign keys are
     * cheap and never leaves the database.
     */
    public function testItIsStampedWithATimeOrderedUuidOnFirstPersist(): void
    {
        $area = $this->anArea();

        $uuid = $area->getUuid();
        // v7 specifically: the identifier sorts by creation time, so a listing
        // ordered by it is chronological without a second column.
        self::assertInstanceOf(UuidV7::class, $uuid);
        self::assertSame($uuid->toRfc4122(), $area->getUuidString());
    }

    public function testTwoAreasCannotShareAUuid(): void
    {
        self::assertTrue(
            (bool) $this->em->getClassMetadata(AreaOfInterest::class)->getFieldMapping('uuid')->unique,
            'the public identifier is the one an installation hands out; a duplicate is a wrong page',
        );
    }

    public function testItIsTimestampedOnPersistAndOnUpdate(): void
    {
        $area = $this->anArea();

        $created = $area->getCreatedAt();
        self::assertNotNull($created);
        self::assertEquals($created, $area->getUpdatedAt());

        // A second flush with a real change: PreUpdate moves the second stamp
        // and leaves the first alone.
        $area->setName('Renamed');
        $area->setUpdatedAt(new \DateTimeImmutable('1999-01-01'));
        $this->em->flush();

        self::assertEquals($created, $area->getCreatedAt());
        self::assertGreaterThan(new \DateTimeImmutable('2000-01-01'), $area->getUpdatedAt());
    }

    /**
     * THE WDPA FIELDS ARE OPTIONAL, and that is the honest default: an
     * installation that drew its own boundary has no IUCN category and no
     * gazettement year, and must not be asked to invent them.
     */
    public function testTheRegistryFieldsAreOptionalAndRoundTripWhenGiven(): void
    {
        $bare = $this->anArea('Drawn by hand');
        self::assertNull($bare->getIucnCategory());
        self::assertNull($bare->getEstablishedYear());

        $bare->setIucnCategory('VI')->setEstablishedYear(1959);
        $this->em->flush();

        $id = $bare->getId();
        self::assertNotNull($id);
        $this->em->clear();

        $reloaded = $this->em->find(AreaOfInterest::class, $id);
        self::assertInstanceOf(AreaOfInterest::class, $reloaded);
        self::assertSame('VI', $reloaded->getIucnCategory());
        self::assertSame(1959, $reloaded->getEstablishedYear());
    }

    /**
     * AN AREA CARRIES NO OTHER MODULE'S READING.
     *
     * A figure like tree cover is one ingestion-shaped module's reading, and
     * persisting it on the entity every other module is filed under would make
     * this bundle know what that module measures — precisely the coupling the
     * contribution contracts exist to prevent.
     *
     * It is absent rather than deprecated: a field nobody may use is still a
     * field everybody has to read past, and a column is one line of migration to be
     * rid of. When an ingestion module lands it will OWN this figure and
     * contribute it the way a module already contributes an overview widget.
     *
     * This test is the shape of the rule, not just the removal of one column: no
     * property on this entity may be another module's measurement.
     *
     * A SETTING IS NOT A MEASUREMENT. `zoneOverlapTolerancePct` and
     * `pingIntervalMinutes` are here for the opposite reason `treeCoverPct` is
     * not: neither is a figure somebody else computed about this ground. They
     * are this area's own answers to questions only it can answer — how
     * carefully its zone scheme was drawn, and what its handsets may spend on
     * battery — and the zones and the posts they govern are this bundle's.
     */
    public function testTheAreaHoldsNoForeignModulesFigure(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            new \ReflectionClass(AreaOfInterest::class)->getProperties(),
        );

        self::assertNotContains('treeCoverPct', $properties);
        self::assertSame(
            [
                'id', 'name', 'geom', 'source', 'iucnCategory', 'establishedYear',
                'zoneOverlapTolerancePct', 'pingIntervalMinutes', 'staleAfterMinutes', 'createdAt', 'updatedAt', 'uuid',
            ],
            $properties,
            'an area is a name, a boundary, where it came from, its registry facts and how its own ground is read — nothing else',
        );

        // And the column is gone from the mapping, not merely from the class.
        self::assertNotContains(
            'tree_cover_pct',
            $this->em->getClassMetadata(AreaOfInterest::class)->getColumnNames(),
        );
    }
}
