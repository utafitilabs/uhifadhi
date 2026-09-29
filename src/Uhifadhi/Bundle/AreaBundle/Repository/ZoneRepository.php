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

namespace Uhifadhi\Bundle\AreaBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use UtafitiLabs\PostGISBundle\Repository\SpatialEntityRepository;

/**
 * Zone lookups, including the two predicates the zone invariant is built on.
 * Both are DE-9IM / coverage questions the PostGIS bundle's DQL surface does not
 * expose, so they are expressed as native SQL — here, in the repository, and
 * nowhere else in the bundle.
 *
 * @extends SpatialEntityRepository<Zone>
 */
class ZoneRepository extends SpatialEntityRepository
{
    /**
     * "The interiors of A and B intersect" — the first cell of the DE-9IM
     * matrix, every other cell free. This is exactly the zone rule: two zones
     * sharing only boundary (adjacent zones) score F in that cell and pass,
     * while an overlap, a containment and an identical footprint all score T and
     * fail. ST_Overlaps alone would not do: PostGIS defines it as FALSE when one
     * geometry contains the other, which is a case the invariant must catch.
     */
    private const string INTERIORS_INTERSECT = 'T********';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Zone::class);
    }

    /**
     * Every zone of one area, by name. An area with no zones — the default
     * state — is an empty list, not an error.
     *
     * @return list<Zone>
     */
    public function zonesFor(AreaOfInterest $area): array
    {
        /** @var list<Zone> $result */
        $result = $this->createQueryBuilder('z')
            ->where('z.area = :area')
            ->setParameter('area', $area)
            ->orderBy('z.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countFor(AreaOfInterest $area): int
    {
        return (int) $this->createQueryBuilder('z')
            ->select('COUNT(z.id)')
            ->where('z.area = :area')
            ->setParameter('area', $area)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForName(AreaOfInterest $area, string $name): ?Zone
    {
        return $this->findOneBy(['area' => $area, 'name' => $name]);
    }

    /**
     * EVERY SIBLING ZONE WHOSE INTERIOR THE GIVEN GEOMETRY WOULD SHARE, in the
     * deterministic order — not the first one.
     *
     * ALL OF THEM, BECAUSE SHARING IS NO LONGER THE WHOLE QUESTION. How much is
     * shared decides whether it is a sliver or an overlap, and a caller handed
     * only the first conflict would accept a sliver against one zone while a
     * real overlap with the next went unmeasured.
     *
     * $ignore excludes the zone being re-drawn from its own check — otherwise
     * every edit would collide with itself.
     *
     * @return list<Zone>
     */
    public function findStInteriorConflicts(AreaOfInterest $area, string $geoJson, ?Zone $ignore = null): array
    {
        $areaId = $area->getId();
        if (null === $areaId) {
            return [];
        }

        $parameters = ['area' => $areaId, 'geom' => $geoJson, 'pattern' => self::INTERIORS_INTERSECT];
        // The && bounding-box test is the index-using prefilter; ST_Relate then decides.
        $sql = 'SELECT z.id FROM zone z WHERE z.area_id = :area'
            .' AND z.geom && ST_GeomFromGeoJSON(:geom)'
            .' AND ST_Relate(z.geom, ST_GeomFromGeoJSON(:geom), :pattern)';
        $ignoreId = $ignore?->getId();
        if (null !== $ignoreId) {
            $sql .= ' AND z.id <> :ignore';
            $parameters['ignore'] = $ignoreId;
        }
        $sql .= ' ORDER BY z.name ASC, z.id ASC';

        $zones = [];
        foreach ($this->getEntityManager()->getConnection()->fetchFirstColumn($sql, $parameters) as $id) {
            $zone = is_numeric($id) ? $this->find((int) $id) : null;
            if (null !== $zone) {
                $zones[] = $zone;
            }
        }

        return $zones;
    }

    /**
     * The zone covering the point, or null where the area is unzoned. ST_Covers
     * includes the boundary, so a point on an edge two zones share matches BOTH;
     * the ordering below settles it deterministically — lowest name, then lowest
     * id — so repeated calls agree.
     */
    public function findStCovering(AreaOfInterest $area, float $lon, float $lat): ?Zone
    {
        $areaId = $area->getId();
        if (null === $areaId) {
            return null;
        }

        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT z.id FROM zone z WHERE z.area_id = :area'
            .' AND ST_Covers(z.geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))'
            .' ORDER BY z.name ASC, z.id ASC LIMIT 1',
            ['area' => $areaId, 'lon' => $lon, 'lat' => $lat],
        );

        return is_numeric($id) ? $this->find((int) $id) : null;
    }

    /**
     * A SLIVER THIS SMALL IS ARITHMETIC, NOT GROUND. A ring traced along the
     * area's own edge comes back from any exporter as a ROUNDED copy of that
     * edge — GeoJSON writes nine decimals — so a few vertices land a tenth of a
     * millimetre outside. Measured on the real thing that is about a square
     * metre against four thousand square kilometres, and a page that announced
     * it would be announcing a rounding error.
     *
     * The tolerance is RELATIVE with an absolute floor: a part per million of
     * the ring, but never less than a hundred square metres, which is smaller
     * than anything anybody draws on purpose.
     */
    private const float OUTSIDE_FLOOR_M2 = 100.0;
    private const float OUTSIDE_SHARE = 0.000001;

    /**
     * HOW FAR THIS RING REACHES PAST THE AREA'S BOUNDARY, in square kilometres,
     * or zero when it does not.
     *
     * A ZONE MAY LIE OUTSIDE, so this is not a refusal and never was a question
     * about permission: a gazetted edge and an operational subdivision are
     * drawn by different people from different sources, and a sector that runs
     * a kilometre past the line is a fact about the ground. What the number is
     * for is SAYING SO, on the row, so nobody has to discover it from a map.
     *
     * AN AREA WITH NO BOUNDARY HAS NOTHING TO BE OUTSIDE OF, and answers zero.
     */
    public function stBeyondTheBoundaryKm2(AreaOfInterest $area, string $geoJson): float
    {
        $areaId = $area->getId();
        if (null === $areaId || !$area->hasBoundary()) {
            return 0.0;
        }

        $outside = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Area(ST_Difference(ST_GeomFromGeoJSON(:geom), a.geom)::geography)'
            .' FROM area_of_interest a WHERE a.id = :area',
            ['area' => $areaId, 'geom' => $geoJson],
        );

        if (!is_numeric($outside)) {
            return 0.0;
        }

        $tolerance = max(self::OUTSIDE_FLOOR_M2, $this->stGeometryKm2($geoJson) * 1000000.0 * self::OUTSIDE_SHARE);

        return (float) $outside <= $tolerance ? 0.0 : (float) $outside / 1000000.0;
    }

    /**
     * THE WHOLE OF THE GROUND THIS AREA ACCOUNTS FOR — its boundary and its
     * zones together, as one geometry, measured once.
     *
     * THE UNION, BECAUSE A ZONE MAY LIE OUTSIDE. "Zoned of area" was the right
     * fraction while every zone was inside the line; now that one need not be,
     * the same fraction reads over a hundred percent, which is a page saying
     * something impossible. The union is the honest denominator: everything
     * this area has said is its, counted once however many rings cover it.
     *
     * NULL WHERE THERE IS NOTHING TO MEASURE — no boundary and no zones — so a
     * page states sizes and withholds shares rather than dividing by nothing.
     */
    public function stGroundKm2(AreaOfInterest $area): ?float
    {
        $areaId = $area->getId();
        if (null === $areaId) {
            return null;
        }

        $km2 = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Area(ST_Union(g.geom)::geography) / 1000000.0 FROM ('
            .'   SELECT a.geom FROM area_of_interest a WHERE a.id = :area AND a.geom IS NOT NULL'
            .'   UNION ALL'
            .'   SELECT z.geom FROM zone z WHERE z.area_id = :area'
            .') g',
            ['area' => $areaId],
        );

        return is_numeric($km2) ? (float) $km2 : null;
    }

    /**
     * THE GROUND A CANDIDATE RING COVERS, on the spheroid, before it is stored.
     * The preview prints a size beside every feature, and a size computed from
     * degrees in PHP is wrong by a factor that grows with latitude — so the
     * database answers, exactly as it does for a zone that already exists.
     */
    public function stGeometryKm2(string $geoJson): float
    {
        $km2 = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Area(ST_GeomFromGeoJSON(:geom)::geography) / 1000000.0',
            ['geom' => $geoJson],
        );

        return is_numeric($km2) ? (float) $km2 : 0.0;
    }

    /**
     * HOW MUCH GROUND A CANDIDATE RING WOULD TAKE FROM A ZONE THAT IS ALREADY
     * THERE. "Overlaps Lone Hills" is a refusal; "overlaps Lone Hills by 41 km²" is a
     * refusal somebody can act on, because it says whether the file is wrong or
     * the stored zone is.
     */
    public function stOverlapKm2(Zone $zone, string $geoJson): float
    {
        $id = $zone->getId();
        if (null === $id) {
            return 0.0;
        }

        $km2 = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Area(ST_Intersection(z.geom, ST_GeomFromGeoJSON(:geom))::geography) / 1000000.0'
            .' FROM zone z WHERE z.id = :id',
            ['id' => $id, 'geom' => $geoJson],
        );

        return is_numeric($km2) ? (float) $km2 : 0.0;
    }

    /**
     * THE GROUND TWO CANDIDATE RINGS SHARE, neither of them stored yet — what
     * an import needs to size a clash between two features of one file the way
     * it sizes one against a zone that is already there.
     */
    public function stIntersectionKm2(string $firstGeoJson, string $secondGeoJson): float
    {
        $km2 = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_Area(ST_Intersection(ST_GeomFromGeoJSON(:first), ST_GeomFromGeoJSON(:second))::geography) / 1000000.0',
            ['first' => $firstGeoJson, 'second' => $secondGeoJson],
        );

        return is_numeric($km2) ? (float) $km2 : 0.0;
    }
}
