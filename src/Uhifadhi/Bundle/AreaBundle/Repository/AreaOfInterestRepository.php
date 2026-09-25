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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use UtafitiLabs\PostGISBundle\Repository\SpatialEntityRepository;

/**
 * Extends the PostGIS bundle's repository base rather than
 * `ServiceEntityRepository` — the extend-when-you-need-it rule. The `St` methods
 * (`stAreaKm2()`, `findStIntersecting()`) just exist, so this bundle ships no
 * DQL and no SQL of its own for the two things everything holding an area asks:
 * how big is it, and what does it touch.
 *
 * The one method here is the OTHER thing everything asks — find it by the
 * identifier the product actually uses.
 *
 * @extends SpatialEntityRepository<AreaOfInterest>
 */
class AreaOfInterestRepository extends SpatialEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AreaOfInterest::class);
    }

    /**
     * An area by its public identifier — the one in URLs and API responses,
     * never the sequential key.
     *
     * A STRING THAT IS NOT A UUID IS A MISS, NOT A CRASH. The argument comes off
     * a route or a request, so a typo is an ordinary event: it means no such
     * area, and the caller renders a 404 the same way it does for a well-formed
     * identifier nobody owns. Letting Uid's exception out would turn the two
     * into different failures for no reason a visitor could act on.
     */
    /**
     * The boundary as GeoJSON, simplified for a register card's face — or null
     * for an area with no geometry, or one simplified away to nothing.
     *
     * SIMPLIFIED IN THE DATABASE, not in PHP. A gazetted boundary can carry tens
     * of thousands of vertices; a ~320px card face resolves none of them, so
     * ST_Simplify drops the ones that would never show before the ring ever
     * reaches PHP — which is what keeps a wall of cards' inline paths small.
     * ST_SimplifyPreserveTopology so a boundary is never torn into a self-
     * crossing outline by the coarseness.
     *
     * The geometry travels as GeoJSON text exactly as everywhere else in this
     * bundle; {@see \Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail} projects it into the
     * card's viewBox.
     */
    public function stSimplifiedBoundary(int $id, float $tolerance): ?string
    {
        $geojson = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tol))'
            .' FROM area_of_interest WHERE id = :id AND geom IS NOT NULL',
            ['id' => $id, 'tol' => $tolerance],
        );

        return \is_string($geojson) ? $geojson : null;
    }

    /**
     * EVERY AREA, BY NAME — what a cross-area board offers as choices, and the
     * order every list of areas in the product is read in.
     *
     * @return list<AreaOfInterest>
     */
    public function findAllOrdered(): array
    {
        /** @var list<AreaOfInterest> $areas */
        $areas = $this->createQueryBuilder('a')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $areas;
    }

    /**
     * WHERE THE AREA ROUGHLY IS — the boundary's centroid, as a pair of
     * degrees.
     *
     * THE DATABASE ANSWERS IT. A centroid averaged from a ring's vertices in
     * PHP is wrong for every polygon whose vertices are not evenly spaced,
     * which is every real boundary; `ST_Centroid` is right and costs one
     * round trip on a page that already made several.
     *
     * NULL WHERE THERE IS NO BOUNDARY, which is an ordinary state: an area is
     * gazetted and named before its edge is imported.
     *
     * @return array{0: float, 1: float}|null latitude, then longitude — the order it is read out in
     */
    public function stCentroid(int $id): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lon'
            .' FROM area_of_interest WHERE id = :id AND geom IS NOT NULL',
            ['id' => $id],
        );

        if (false === $row || !is_numeric($row['lat'] ?? null) || !is_numeric($row['lon'] ?? null)) {
            return null;
        }

        return [(float) $row['lat'], (float) $row['lon']];
    }

    public function findOneByUuid(string $uuid): ?AreaOfInterest
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['uuid' => Uuid::fromString($uuid)]);
    }
}
