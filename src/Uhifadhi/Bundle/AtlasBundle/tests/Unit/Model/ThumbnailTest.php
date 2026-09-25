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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

/**
 * THE CARD FACE, PROJECTED. Two halves are pinned here: the boundary outline,
 * projected into the face's viewBox, and the real Esri World Imagery snippet the
 * outline is drawn over — a keyless `<img>` export for the boundary's bbox, no
 * static-tile stitch and no cache (see {@see Thumbnail} and
 * {@see \Uhifadhi\Bundle\AreaBundle\Service\AreaThumbnailer}). Both, plus their honest
 * fallbacks for an area with no drawable geometry.
 */
final class ThumbnailTest extends TestCase
{
    private const string A_POLYGON = '{"type":"Polygon","coordinates":[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]}';

    public function testAnAreaWithNoBoundaryFallsBackToTheNeutralGround(): void
    {
        $thumb = Thumbnail::neutral();

        self::assertFalse($thumb->hasBoundary);
        self::assertNull($thumb->path);
        // No boundary, no bbox to frame — the card face is the neutral ground
        // alone, with no satellite snippet to draw.
        self::assertNull($thumb->imageUrl);
    }

    public function testNullOrEmptyGeometryIsTheNeutralGround(): void
    {
        self::assertFalse(Thumbnail::fromGeoJson(null)->hasBoundary);
        self::assertFalse(Thumbnail::fromGeoJson('')->hasBoundary);
        self::assertNull(Thumbnail::fromGeoJson(null)->imageUrl);
    }

    /**
     * THE SATELLITE SNIPPET IS A REAL ESRI EXPORT for the boundary's bbox — a
     * plain keyless `<img>` URL the browser renders on demand, so there is no
     * static-tile stitch and nothing to cache. The bbox is padded and fitted to
     * the face's aspect so the export is not distorted.
     */
    public function testABoundaryCarriesAnEsriSatelliteUrlForItsBbox(): void
    {
        $thumb = Thumbnail::fromGeoJson(self::A_POLYGON);

        self::assertNotNull($thumb->imageUrl);
        self::assertStringStartsWith(Thumbnail::ESRI_EXPORT, $thumb->imageUrl);
        // The World Imagery export, asked for as an image of the boundary's bbox.
        self::assertStringContainsString('World_Imagery', $thumb->imageUrl);
        self::assertStringContainsString('bbox=', $thumb->imageUrl);
        self::assertStringContainsString('f=image', $thumb->imageUrl);
        // The bbox is given in lon/lat degrees (bboxSR 4326).
        self::assertStringContainsString('bboxSR=4326', $thumb->imageUrl);
    }

    public function testUnparseableGeometryFallsBackRatherThanThrows(): void
    {
        self::assertFalse(Thumbnail::fromGeoJson('not json')->hasBoundary);
    }

    /** A point and a line have no shape to frame — the card shows the neutral ground. */
    public function testAGeometryWithNoAreaIsTheNeutralGround(): void
    {
        self::assertFalse(Thumbnail::fromGeoJson('{"type":"Point","coordinates":[-30.0,-3.6]}')->hasBoundary);
    }

    public function testABoundaryBecomesAClosedSvgPath(): void
    {
        $thumb = Thumbnail::fromGeoJson(self::A_POLYGON);

        self::assertTrue($thumb->hasBoundary);
        self::assertNotNull($thumb->path);
        // A ring opens with a move, draws with lines and closes.
        self::assertStringStartsWith('M', $thumb->path);
        self::assertStringContainsString('L', $thumb->path);
        self::assertStringContainsString('Z', $thumb->path);
    }

    /**
     * THE OUTLINE IS FRAMED INSIDE THE FACE. Every projected coordinate lands
     * within the viewBox, so the shape never spills past the card's edge.
     */
    public function testTheProjectedOutlineStaysInsideTheViewBox(): void
    {
        $path = Thumbnail::fromGeoJson(self::A_POLYGON)->path;
        self::assertNotNull($path);

        preg_match_all('/-?\d+(?:\.\d+)?/', $path, $matches);
        $numbers = array_map('floatval', $matches[0]);
        self::assertNotEmpty($numbers);

        // The pairs alternate x, y; both stay within the box on their own axis.
        foreach ($numbers as $i => $n) {
            $limit = 0 === $i % 2 ? Thumbnail::WIDTH : Thumbnail::HEIGHT;
            self::assertGreaterThanOrEqual(0, $n);
            self::assertLessThanOrEqual($limit, $n);
        }
    }

    /** A MultiPolygon with an enclave keeps both rings, so the hole is drawn too. */
    public function testAMultiPolygonDrawsEveryRing(): void
    {
        $twoRings = '{"type":"MultiPolygon","coordinates":['
            .'[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]],'
            .'[[[-29.7,-3.3],[-29.5,-3.3],[-29.5,-3.1],[-29.7,-3.1],[-29.7,-3.3]]]'
            .']}';

        $path = Thumbnail::fromGeoJson($twoRings)->path;
        self::assertNotNull($path);
        // Two rings means two move commands and two closes.
        self::assertSame(2, substr_count($path, 'M'));
        self::assertSame(2, substr_count($path, 'Z'));
    }
}
