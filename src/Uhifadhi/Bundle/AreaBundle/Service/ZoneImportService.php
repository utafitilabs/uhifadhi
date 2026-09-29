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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Entity\ZoneImport;
use Uhifadhi\Bundle\AreaBundle\Exception\ZoneImportException;
use Uhifadhi\Bundle\AreaBundle\Exception\ZoneOverlapException;
use Uhifadhi\Bundle\AreaBundle\Model\ImportedName;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneFeaturePlan;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneImportPlan;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneImportResult;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;

/**
 * A FILE BECOMES AN AREA'S ZONING SCHEME — the sibling of {@see BoundaryImport},
 * and the one supported way a whole subdivision arrives at once.
 *
 * ONE FEATURECOLLECTION PER AREA, ONE FEATURE PER ZONE. That is the shape a
 * desktop GIS exports and the shape the ruling fixes, so it is the shape read
 * here: the geometry of each feature becomes a zone's MultiPolygon, and the
 * zone's name comes out of the feature's properties.
 *
 * NOBODY IS ASKED TO CLEAN A FILE. A scheme converted from KMZ carries
 * description, timestamp, begin, end, altitudeMode, tessellate, extrude,
 * visibility, drawOrder and icon on every feature; a layer merge adds `layer`
 * and `path`; and every position carries an altitude the column cannot hold.
 * None of that is an error and none of it is a reason to send somebody back to
 * a text editor: the name is taken from the first of {@see NAME_PROPERTIES}
 * that the file actually uses, the third ordinate is dropped by
 * {@see GeoJsonNormalizer}, and everything else is READ PAST and then NAMED in
 * the summary — so what was ignored is stated rather than silently lost.
 *
 * WGS84 OR NOTHING, AND A MISSING `crs` IS WGS84. RFC 7946 defines GeoJSON as
 * WGS84 and removed the `crs` member, so a file without one is right and a file
 * carrying the CRS84 urn is saying the same thing twice. A file that DECLARES a
 * projected system is a file whose numbers are metres: importing it would put
 * the scheme somewhere off the coast of Africa, so it is refused by name and
 * the person is told to export as 4326.
 *
 * ADDITIVE, AND NEVER OVERWRITING. An empty area takes every valid feature; an
 * area that already has zones takes every feature whose name is free and whose
 * ring shares interior with nothing. The rest are FLAGGED with their reason and
 * left where they are — a partial import is legitimate precisely because
 * nothing was destroyed to make room for it, and the person chose the subset.
 *
 * TWO STEPS, ONE DECISION. {@see plan()} reads the file and states a verdict per
 * feature; {@see apply()} writes the subset it is given, in one transaction,
 * re-checking each feature against the area as it stands at that moment.
 *
 * THE FILE IS NOT KEPT. It is read, validated, turned into geometry and let go;
 * what survives is the geometry in PostGIS and one {@see ZoneImport} row of
 * provenance beside it.
 */
final readonly class ZoneImportService
{
    /**
     * THE ACCEPTED SPELLINGS, IN PRECEDENCE ORDER. Every tool in the chain names
     * the same column differently — QGIS keeps the source's case, ogr2ogr
     * upper-cases, a KML conversion writes `name`, a merge writes `layer` — and
     * the file is read rather than the exporter interrogated. The FIRST of these
     * that names every feature in the file is the one used, and the summary says
     * which it was.
     *
     * `layer` is last on purpose: it is a merge artefact that happens to be
     * usable, so it answers only when nothing better does.
     */
    public const array NAME_PROPERTIES = ['name', 'Name', 'NAME', 'zone', 'Zone', 'title', 'layer'];

    /**
     * THE GEOMETRY TYPES A ZONE CAN BE. Anything else in the collection is
     * something other than a zone — a station point, a track — and is counted
     * and named rather than refused: one export often carries them all.
     */
    private const array AREAL_GEOMETRIES = ['Polygon', 'MultiPolygon'];

    /** RFC 7946's own media type, plus what a browser sends for a `.geojson`. */
    private const array EXTENSIONS = ['geojson', 'json'];

    /**
     * WGS84, WRITTEN THE FOUR WAYS FILES WRITE IT. CRS84 is WGS84 with the axes
     * in longitude/latitude order, which is what GeoJSON uses; EPSG:4326 names
     * the same datum. Anything else is a declaration that the numbers are not
     * degrees.
     */
    private const array WGS84 = [
        'urn:ogc:def:crs:ogc:1.3:crs84',
        'urn:ogc:def:crs:ogc::crs84',
        'urn:ogc:def:crs:epsg::4326',
        'epsg:4326',
        'crs84',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ZoneService $zones,
        private ZoneRepository $zoneRepository,
        private ZoneOverlapService $overlaps,
        private ZoneEventService $events,
    ) {
    }

    /**
     * WHAT THIS FILE WOULD DO TO THIS AREA — every feature, with a verdict, and
     * nothing written.
     *
     * A WHOLE FILE IS REFUSED FOR THREE REASONS AND NO OTHERS: it cannot be read
     * as GeoJSON, it carries no property that names every feature, or its
     * coordinates are projected rather than degrees. Those are files nobody can
     * act on feature by feature. Everything else — a name the area already has,
     * a ring over a zone that is already there, ground outside the boundary, a
     * feature that is not a polygon at all — is a line in the preview with its
     * reason beside it, because the rest of the file still arrives.
     *
     * THE FILE IS READ HERE AND LET GO. The plan carries geometry as strings, so
     * the confirm that follows needs neither the document nor a copy of it.
     *
     * @throws ZoneImportException when the file cannot be read at all
     */
    public function plan(AreaOfInterest $area, File $file, string $originalName, ?string $preferred = null, ?string $actor = null): ZoneImportPlan
    {
        /*
         * A REFUSED FILE IS AN EVENT, AND THE VERB IS WHAT KNOWS IT HAPPENED.
         * Written here rather than by whoever called: a console importer and
         * a screen both refuse files, and a line written on one path is a
         * history with a hole in it on the other.
         */
        try {
            $features = $this->featuresOf($file, $originalName);
        } catch (ZoneImportException $e) {
            $this->events->refused($area, $e->getMessage(), $actor);

            throw $e;
        }

        $skippedGeometries = [];
        $areal = [];
        foreach ($features as $feature) {
            $type = $this->geometryTypeOf($feature);

            /*
             * A POINT IS NOT A SMALL ZONE. A scheme exported beside its stations
             * carries them in the same collection, and counting them is the
             * honest answer: they are stated in the summary and never offered as
             * something to import. A feature with NO geometry is a different
             * thing — a zone name with nothing behind it — and stays a flagged
             * line, because somebody meant it to be a zone.
             */
            if (null !== $type && !\in_array($type, self::AREAL_GEOMETRIES, true)) {
                $skippedGeometries[$type] = ($skippedGeometries[$type] ?? 0) + 1;

                continue;
            }

            $areal[] = $feature;
        }

        if ([] === $areal) {
            $refusal = ZoneImportException::noFeatures();
            $this->events->refused($area, $refusal->getMessage(), $actor);

            throw $refusal;
        }

        try {
            $nameProperty = $this->namePropertyOf($areal, $preferred);
        } catch (ZoneImportException $e) {
            $this->events->refused($area, $e->getMessage(), $actor);

            throw $e;
        }

        $planned = [];
        foreach ($areal as $feature) {
            $planned[] = $this->verdict($area, $feature, $nameProperty, $planned);
        }

        return new ZoneImportPlan(
            $originalName,
            (int) $file->getSize(),
            $nameProperty,
            $this->ignoredPropertiesOf($areal, $nameProperty),
            $planned,
            $skippedGeometries,
        );
    }

    /**
     * THE SUBSET THE PERSON CONFIRMED, WRITTEN IN ONE TRANSACTION.
     *
     * THE PLAN IS NOT THE AUTHORITY. It was made against the area as it stood in
     * an earlier request, and a zone written since can have taken a name or the
     * ground; so every feature is checked again here, against the database, and
     * one that no longer fits is reported rather than forced.
     *
     * PARTIAL IS NOT FAILURE. Nothing is overwritten to make room, so a run that
     * writes nine of eleven has done exactly what was asked of it. The
     * transaction is there so that a run either writes its whole subset or
     * writes none of it — never half a confirm.
     *
     * $importedBy is the identifier of whoever is importing where that is known
     * — a screen knows, a fixture loader does not — and is recorded as
     * provenance rather than used for anything.
     *
     * @param list<string> $names the features to write, by the names the plan lists
     */
    public function apply(AreaOfInterest $area, ZoneImportPlan $plan, array $names, ?string $importedBy = null): ZoneImportResult
    {
        $wanted = [];
        foreach ($plan->arriving() as $feature) {
            if (\in_array($feature->name, $names, true)) {
                $wanted[] = $feature;
            }
        }

        $added = [];
        $skipped = [];
        $wasEmpty = 0 === $this->zoneRepository->countFor($area);

        if ([] !== $wanted) {
            $this->entityManager->wrapInTransaction(function () use ($area, $plan, $wanted, $importedBy, &$added, &$skipped): void {
                $import = new ZoneImport()
                    ->setArea($area)
                    ->setFileName($plan->fileName)
                    ->setImportedAt(new \DateTimeImmutable())
                    ->setImportedBy($importedBy)
                    ->setZoneCount(0)
                    ->setNameProperty($plan->nameProperty);
                $this->entityManager->persist($import);

                foreach ($wanted as $feature) {
                    $geom = (string) $feature->geom;

                    $refusal = $this->refusalOf($area, $feature->name, $geom);
                    if (null !== $refusal) {
                        $skipped[$feature->name] = $refusal;

                        continue;
                    }

                    try {
                        $this->zones->create($area, $feature->name, $geom)->setImport($import);
                    } catch (ZoneOverlapException $e) {
                        $skipped[$feature->name] = $e->getMessage();

                        continue;
                    }

                    $added[] = $feature->name;
                }

                $import->setZoneCount(\count($added));
                $this->entityManager->flush();
            });
        }

        $result = new ZoneImportResult(
            $added,
            $skipped,
            $plan->nameProperty,
            $plan->ignoredProperties,
            $plan->fileName,
        );

        /*
         * THE LINE IS WRITTEN HERE, not by the screen. Every caller that can
         * import — a screen, a console command, a fixture loader — leaves the
         * same history, and none of them has to remember to.
         */
        $this->events->imported($area, $result, $importedBy, $wasEmpty);

        return $result;
    }

    /**
     * THE WHOLE FILE, ADDED — what a console importer or a fixture loader wants,
     * where there is nobody to confirm a subset. It is the same additive run the
     * screen performs: the arriving features are written and the flagged ones
     * are reported, never forced.
     *
     * @throws ZoneImportException when the file cannot be read at all
     */
    public function importInto(AreaOfInterest $area, File $file, string $originalName, ?string $importedBy = null): ZoneImportResult
    {
        $plan = $this->plan($area, $file, $originalName);

        $result = $this->apply($area, $plan, $plan->arrivingNames(), $importedBy);

        $skipped = $result->skipped;
        foreach ($plan->flagged() as $feature) {
            $skipped[$feature->name] = $feature->why();
        }

        return new ZoneImportResult(
            $result->added,
            $skipped,
            $result->nameProperty,
            $result->ignoredProperties,
            $result->fileName,
        );
    }

    /**
     * ONE RING, OUT OF A SINGLE-FEATURE FILE, FOR A ZONE THAT ALREADY EXISTS.
     *
     * THE SAME VALIDATION PATH AS AN IMPORT, and that is the whole reason this
     * lives here rather than beside the zone: a ring that arrives one at a time
     * is held to the invariant a ring that arrives eleven at a time is held to
     * — inside the boundary, sharing interior with no sibling — and the zone
     * being redrawn is excluded from its own check, or every edit would collide
     * with itself.
     *
     * A FILE WITH MORE THAN ONE POLYGON IS REFUSED. Replacing one zone with
     * several is not a replacement, and picking one of them for somebody is
     * worse than asking.
     *
     * @return string the MultiPolygon a zone's column takes
     *
     * @throws ZoneImportException when the file is not one usable polygon for this zone
     */
    public function ringFor(Zone $zone, File $file, string $originalName): string
    {
        $area = $zone->getArea();
        if (null === $area) {
            throw new \LogicException('A zone always belongs to an area.');
        }

        $features = array_values(array_filter(
            $this->featuresOf($file, $originalName),
            fn (array $feature): bool => \in_array($this->geometryTypeOf($feature), self::AREAL_GEOMETRIES, true),
        ));

        if (1 !== \count($features)) {
            throw ZoneImportException::notOneRing(\count($features));
        }

        $name = (string) $zone->getName();
        $geom = $this->geometryOf($features[0], $name);

        // A REDRAWN RING MAY CROSS THE LINE TOO. What it may not do is take a
        // sibling's ground, and the area's own tolerance says how much sharing
        // is drawing precision rather than geography.
        $km2 = $this->zoneRepository->stGeometryKm2($geom);
        $tolerance = $this->overlaps->toleranceOf($area);

        foreach ($this->zoneRepository->findStInteriorConflicts($area, $geom, $zone) as $conflict) {
            $shared = $this->zoneRepository->stOverlapKm2($conflict, $geom);
            $theirs = $this->zoneRepository->stGeometryKm2((string) $conflict->getGeom());

            if (!$this->overlaps->isSliver($shared, $theirs, $km2, $tolerance)) {
                throw ZoneImportException::ringOverlaps($conflict->getName() ?? '', (int) round($shared));
            }
        }

        return $geom;
    }

    /**
     * ONE FEATURE'S VERDICT, measured against the area and against the features
     * already planned above it.
     *
     * THE ORDER OF THE CHECKS IS THE ORDER OF THE ANSWERS somebody can act on.
     * A feature whose name is taken AND whose ring overlaps the zone holding
     * that name has one problem, not two, and it is the name: renaming it in
     * the file is what they will do.
     *
     * @param array<array-key, mixed> $feature
     * @param list<ZoneFeaturePlan>   $above   the features planned before this one
     */
    private function verdict(AreaOfInterest $area, array $feature, string $nameProperty, array $above): ZoneFeaturePlan
    {
        $name = $this->nameOf($feature, $nameProperty);
        // What the FILE said, where this is not it — shown in the preview, so
        // somebody reading the list before they commit to it can object.
        $fileSaid = $this->propertyOf($feature, $nameProperty);
        $unusable = ZoneFeaturePlan::arriving($name, '{}', null, $fileSaid);

        /*
         * EVERY FEATURE HERE DECLARES A POLYGON OR NOTHING — a point layer was
         * counted and set aside before this — so there are exactly two ways to
         * fail, and each gets its own short sentence rather than the reader
         * being handed the parser's.
         */
        if (!\is_array($feature['geometry'] ?? null)) {
            return $unusable->noGeometry();
        }

        try {
            $geom = $this->geometryOf($feature, $name);
        } catch (ZoneImportException) {
            return $unusable->notAPolygon();
        }

        $km2 = $this->zoneRepository->stGeometryKm2($geom);
        $planned = ZoneFeaturePlan::arriving($name, $geom, (int) round($km2), $fileSaid);

        /*
         * A ZONE MAY LIE OUTSIDE THE BOUNDARY, so this says so and moves on.
         * It is stated before the refusals below because a feature that is
         * both outside and clashing is refused for the clash — the thing that
         * has to be fixed — while still carrying the fact about the line.
         */
        $beyond = $this->zoneRepository->stBeyondTheBoundaryKm2($area, $geom);
        if ($beyond > 0.0) {
            $planned = $planned->extendingBeyondTheBoundary((int) round($beyond));
        }

        foreach ($above as $earlier) {
            if ($earlier->name === $name) {
                return $planned->nameUsedTwiceInTheFile();
            }
        }

        if (null !== $this->zoneRepository->findOneForName($area, $name)) {
            return $planned->nameAlreadyHere();
        }

        $tolerance = $this->overlaps->toleranceOf($area);

        foreach ($above as $earlier) {
            if (!$earlier->isArriving()) {
                continue;
            }

            $shared = $this->zoneRepository->stIntersectionKm2((string) $earlier->geom, $geom);
            if ($shared > 0.0 && !$this->overlaps->isSliver($shared, (float) ($earlier->km2 ?? 0), $km2, $tolerance)) {
                return $planned->overlapsFeatureInTheFile($earlier->name, (int) round($shared));
            }
        }

        foreach ($this->zoneRepository->findStInteriorConflicts($area, $geom) as $conflict) {
            $shared = $this->zoneRepository->stOverlapKm2($conflict, $geom);
            $theirs = $this->zoneRepository->stGeometryKm2((string) $conflict->getGeom());

            if (!$this->overlaps->isSliver($shared, $theirs, $km2, $tolerance)) {
                return $planned->overlapsZone($conflict->getName() ?? '', (int) round($shared));
            }
        }

        return $planned;
    }

    /**
     * Why this feature cannot be written NOW, or null — the confirm's own check,
     * run against the database inside the transaction rather than against the
     * plan's memory of it.
     */
    private function refusalOf(AreaOfInterest $area, string $name, string $geom): ?string
    {
        // Containment is not one of the questions: a zone may lie outside the
        // boundary, and how far it does is stated rather than refused.
        return null !== $this->zoneRepository->findOneForName($area, $name) ? 'name already here' : null;
    }

    /**
     * The feature's geometry type as the file spells it, or null where the
     * feature carries none.
     *
     * @param array<array-key, mixed> $feature
     */
    private function geometryTypeOf(array $feature): ?string
    {
        $geometry = $feature['geometry'] ?? null;
        $type = \is_array($geometry) ? ($geometry['type'] ?? null) : null;

        return \is_string($type) ? $type : null;
    }

    /**
     * THE FEATURES, IN FILE ORDER. A FeatureCollection is the ruled shape; a
     * bare Feature is a scheme of one and is accepted rather than explained away;
     * anything else is a file exported from the wrong layer and is named.
     *
     * @return list<array<array-key, mixed>>
     */
    private function featuresOf(File $file, string $originalName): array
    {
        /*
         * REFUSED ON THE EXTENSION, BEFORE THE FILE IS READ — the same guard
         * BoundaryImport uses, and for the same reason: a shapefile is not going
         * to become GeoJSON by being json_decode()d, and what somebody needs to
         * be told is the format.
         */
        if (!\in_array(strtolower(pathinfo($originalName, \PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw ZoneImportException::notGeoJson(\sprintf('"%s" is not .geojson or .json', $originalName));
        }

        try {
            $document = json_decode((string) file_get_contents($file->getPathname()), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ZoneImportException::notGeoJson(lcfirst($e->getMessage()), $e);
        }

        if (!\is_array($document)) {
            throw ZoneImportException::notGeoJson('it holds no GeoJSON object');
        }

        $this->assertWgs84($document);

        $type = $document['type'] ?? null;
        if ('Feature' === $type) {
            return [$document];
        }

        if ('FeatureCollection' !== $type) {
            throw ZoneImportException::notAFeatureCollection(\is_string($type) ? $type : 'file with no GeoJSON type');
        }

        $features = $document['features'] ?? null;
        if (!\is_array($features)) {
            throw ZoneImportException::notAFeatureCollection('FeatureCollection with no features');
        }

        $features = array_values(array_filter($features, \is_array(...)));
        if ([] === $features) {
            throw ZoneImportException::noFeatures();
        }

        return $features;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws ZoneImportException when the file declares a system that is not WGS84
     */
    private function assertWgs84(array $document): void
    {
        $crs = $document['crs'] ?? null;
        if (!\is_array($crs)) {
            // RFC 7946 removed `crs` altogether: no member means WGS84.
            return;
        }

        $properties = $crs['properties'] ?? null;
        $name = \is_array($properties) ? ($properties['name'] ?? null) : null;
        if (!\is_string($name) || \in_array(strtolower($name), self::WGS84, true)) {
            return;
        }

        throw ZoneImportException::projectedCrs($name);
    }

    /**
     * WHICH PROPERTY NAMES THE ZONES — decided once for the whole file rather
     * than per feature, because a scheme whose names came out of three different
     * columns is a scheme nobody can check.
     *
     * @param list<array<array-key, mixed>> $features
     */
    private function namePropertyOf(array $features, ?string $preferred = null): string
    {
        /*
         * THE PERSON'S CHOICE WINS WHERE IT WORKS. A file with both `Name` and
         * `layer` in it has two plausible answers, and the one who exported it
         * knows which; a choice that does NOT name every feature is not
         * honoured silently, because a scheme half named out of one column and
         * half out of another is a scheme nobody can check.
         */
        $order = null !== $preferred && \in_array($preferred, self::NAME_PROPERTIES, true)
            ? [$preferred, ...self::NAME_PROPERTIES]
            : self::NAME_PROPERTIES;

        foreach ($order as $property) {
            foreach ($features as $feature) {
                if ('' === $this->propertyOf($feature, $property)) {
                    continue 2;
                }
            }

            return $property;
        }

        // Nothing named every feature. The refusal points at the first feature
        // that no accepted spelling names, since that is the row to go and fix.
        foreach ($features as $position => $feature) {
            foreach (self::NAME_PROPERTIES as $property) {
                if ('' !== $this->propertyOf($feature, $property)) {
                    continue 2;
                }
            }

            throw ZoneImportException::noName($position + 1, self::NAME_PROPERTIES);
        }

        throw ZoneImportException::noName(1, self::NAME_PROPERTIES);
    }

    /** The property as a trimmed string, or '' where it is absent, null or not scalar. */
    private function propertyOf(mixed $feature, string $property): string
    {
        $properties = \is_array($feature) ? ($feature['properties'] ?? null) : null;
        $value = \is_array($properties) ? ($properties[$property] ?? null) : null;

        return \is_string($value) || \is_int($value) || \is_float($value) ? trim((string) $value) : '';
    }

    /**
     * THE NAME THIS FEATURE WILL BE CALLED. A file that shouts every name —
     * which most GIS exports do — is not an installation that decided its
     * zones are called ESCARPMENT, so an entirely upper-case name is title-cased
     * and everything else is left exactly as it arrived.
     * {@see ImportedName} carries the rule and the reason.
     *
     * IT IS DONE HERE, at the one place a feature's name is read, so the
     * preview, the refusals, the duplicate check and the stored zone all
     * speak about the same name. A rule applied on the way to the database
     * only would show the reader one name and save another.
     */
    private function nameOf(mixed $feature, string $property): string
    {
        return ImportedName::of($this->propertyOf($feature, $property));
    }

    /**
     * The feature's geometry as the MultiPolygon string a zone's column takes.
     *
     * @param array<array-key, mixed> $feature
     */
    private function geometryOf(array $feature, string $name): string
    {
        if (!\is_array($feature['geometry'] ?? null)) {
            throw ZoneImportException::noGeometry($name);
        }

        try {
            return new GeoJsonNormalizer()->toMultiPolygon($feature);
        } catch (\InvalidArgumentException $e) {
            throw ZoneImportException::unusableGeometry($name, $e->getMessage(), $e);
        }
    }

    /**
     * EVERY PROPERTY THE FILE CARRIED THAT THE IMPORT DID NOT USE, as one sorted
     * set across the whole file — the summary's second half, so nobody is left
     * wondering whether a description was kept somewhere.
     *
     * @param list<array<array-key, mixed>> $features
     *
     * @return list<string>
     */
    private function ignoredPropertiesOf(array $features, string $nameProperty): array
    {
        $ignored = [];
        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? null;
            if (!\is_array($properties)) {
                continue;
            }

            foreach (array_keys($properties) as $key) {
                if ($nameProperty !== $key) {
                    $ignored[(string) $key] = true;
                }
            }
        }

        $names = array_keys($ignored);
        sort($names);

        return $names;
    }
}
