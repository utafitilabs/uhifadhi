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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * THE FACE OF A REGISTER CARD — the area's boundary, framed to its own bounding
 * box, drawn as an SVG outline over a real satellite snippet.
 *
 * TWO HALVES, BOTH REAL NOW. The graduated design asks for a static satellite
 * snippet framed to the boundary's bbox with the boundary outlined over it:
 *
 *   - THE SATELLITE GROUND is a plain keyless `<img>` pointing at Esri's World
 *     Imagery export endpoint for the boundary's padded bbox ({@see thumbnailUrl}).
 *     Esri renders the raster on demand, so there is no static-tile stitch to run
 *     and nothing to cache — the template writes one `<img src>` and the browser
 *     fetches it. The technique is ported verbatim from the area page's own
 *     AreaCardService, which already does exactly this.
 *   - THE BOUNDARY OUTLINE is projected from the stored geometry into the card's
 *     viewBox and drawn over that ground.
 *
 * An area with no drawable boundary carries neither — no path and no image URL —
 * and the card falls back to the neutral imagery-toned ground alone.
 *
 * THE PATH IS PRE-PROJECTED IN PHP so the template writes one `<path d>` and
 * knows no geometry. Longitudes are compressed by the cosine of the mid-latitude
 * so the outline is not stretched east-west, the boundary is fitted into the box
 * with its aspect kept, and the y axis is flipped (SVG grows downward, latitude
 * grows upward) so the shape is not drawn upside down. The Esri bbox, by
 * contrast, is in plain lon/lat degrees — the export projects it itself.
 */
final readonly class Thumbnail
{
    /** The card face's coordinate space — a shallow landscape strip, the height of `.ax-thumb`. */
    public const int WIDTH = 320;
    public const int HEIGHT = 118;
    public const string VIEW_BOX = '0 0 320 118';

    /**
     * Esri's keyless World Imagery export endpoint. It renders a satellite raster
     * for a bbox on demand — no token, no tile stitch, no cache — which is why the
     * card face can be a single `<img>` rather than a live map per card.
     */
    public const string ESRI_EXPORT = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/export';

    /** Breathing room inside the frame, in viewBox units, so the outline never touches the edge. */
    private const float PAD = 12.0;

    /**
     * The satellite export's pixel size — twice the face's CSS size so it stays
     * crisp on a retina display — and how far its bbox is padded past the boundary
     * so the shape is not jammed against the frame.
     */
    private const int IMG_WIDTH = 640;
    private const int IMG_HEIGHT = 236;
    private const float IMG_PAD = 1.12;

    private function __construct(
        public bool $hasBoundary,
        public ?string $path,
        public ?string $imageUrl = null,
    ) {
    }

    /** An area with no geometry on file: the neutral ground, no outline, no snippet. */
    public static function neutral(): self
    {
        return new self(false, null, null);
    }

    /**
     * The outline for a stored boundary, or the neutral ground when there is
     * nothing drawable — no geometry, unparseable text, or a boundary with no
     * area (a point or a line has no shape to frame).
     */
    public static function fromGeoJson(?string $geojson): self
    {
        if (null === $geojson || '' === $geojson) {
            return self::neutral();
        }

        try {
            $decoded = json_decode($geojson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::neutral();
        }

        $rings = \is_array($decoded) ? self::ringsOf($decoded) : [];
        if ([] === $rings) {
            return self::neutral();
        }

        return self::project($rings);
    }

    /**
     * Every linear ring in a Polygon or MultiPolygon, as lists of [lon, lat].
     * Holes are kept: an enclave cut from the middle of a park is part of its
     * shape. Anything that is not a ring of coordinate pairs is skipped rather
     * than fought — a thumbnail draws what it can and leaves the rest.
     *
     * @param array<mixed> $geometry
     *
     * @return list<list<array{float, float}>>
     */
    private static function ringsOf(array $geometry): array
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return [];
        }

        // A MultiPolygon is a list of polygons; a Polygon is one polygon. Reduce
        // both to a flat list of polygons, then a polygon to its rings.
        $polygons = match ($type) {
            'MultiPolygon' => $coordinates,
            'Polygon' => [$coordinates],
            default => [],
        };

        $rings = [];
        foreach ($polygons as $polygon) {
            if (!\is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (!\is_array($ring)) {
                    continue;
                }
                $points = [];
                foreach ($ring as $point) {
                    if (\is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)) {
                        $points[] = [(float) $point[0], (float) $point[1]];
                    }
                }
                if (\count($points) >= 3) {
                    $rings[] = $points;
                }
            }
        }

        return $rings;
    }

    /**
     * @param list<list<array{float, float}>> $rings
     */
    private static function project(array $rings): self
    {
        $minLon = $minLat = \PHP_FLOAT_MAX;
        $maxLon = $maxLat = -\PHP_FLOAT_MAX;
        foreach ($rings as $ring) {
            foreach ($ring as [$lon, $lat]) {
                $minLon = min($minLon, $lon);
                $maxLon = max($maxLon, $lon);
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
            }
        }

        $midLat = ($minLat + $maxLat) / 2;
        $cos = max(0.01, cos(deg2rad($midLat)));
        $spanX = ($maxLon - $minLon) * $cos;
        $spanY = $maxLat - $minLat;
        if ($spanX <= 0.0 || $spanY <= 0.0) {
            return self::neutral();
        }

        $availW = self::WIDTH - 2 * self::PAD;
        $availH = self::HEIGHT - 2 * self::PAD;
        $scale = min($availW / $spanX, $availH / $spanY);
        $offsetX = (self::WIDTH - $spanX * $scale) / 2;
        $offsetY = (self::HEIGHT - $spanY * $scale) / 2;

        $path = '';
        foreach ($rings as $ring) {
            $command = 'M';
            foreach ($ring as [$lon, $lat]) {
                $x = $offsetX + ($lon - $minLon) * $cos * $scale;
                // Flip y: the northern edge (maxLat) sits at the top of the box.
                $y = $offsetY + ($maxLat - $lat) * $scale;
                $path .= \sprintf('%s%s %s ', $command, self::round($x), self::round($y));
                $command = 'L';
            }
            $path .= 'Z ';
        }

        return new self(true, trim($path), self::thumbnailUrl($minLon, $minLat, $maxLon, $maxLat));
    }

    /**
     * A real Esri World Imagery URL for the boundary's bbox — the bbox is padded
     * and fitted to the face's aspect so the export is not stretched. Ported from
     * the area page's AreaCardService; the raster is Esri's to render, so this is a URL
     * and never a fetch.
     */
    private static function thumbnailUrl(float $minLon, float $minLat, float $maxLon, float $maxLat): string
    {
        $cx = ($minLon + $maxLon) / 2;
        $cy = ($minLat + $maxLat) / 2;
        $hw = max(($maxLon - $minLon) / 2, 0.01) * self::IMG_PAD;
        $hh = max(($maxLat - $minLat) / 2, 0.01) * self::IMG_PAD;
        $aspect = self::IMG_WIDTH / self::IMG_HEIGHT;
        if ($hw / $hh < $aspect) {
            $hw = $hh * $aspect;
        } else {
            $hh = $hw / $aspect;
        }
        $bbox = \sprintf('%.5f,%.5f,%.5f,%.5f', $cx - $hw, $cy - $hh, $cx + $hw, $cy + $hh);

        return self::ESRI_EXPORT.'?'.http_build_query([
            'bbox' => $bbox,
            'bboxSR' => 4326,
            'imageSR' => 3857,
            'size' => self::IMG_WIDTH.','.self::IMG_HEIGHT,
            'format' => 'jpg',
            'f' => 'image',
        ]);
    }

    /** One decimal is all a 320-wide face resolves; it keeps the path small. */
    private static function round(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
