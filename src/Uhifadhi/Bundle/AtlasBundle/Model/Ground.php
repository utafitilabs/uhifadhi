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

use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE GROUND A PLATE STANDS ON — the area's boundary and the area's zones —
 * drawn the one way the platform draws it on every plate.
 *
 * A plate is handed the geometry and nothing else: the atlas reads no
 * repository and knows no entity. Whoever owns the area answers "what is its
 * ground"; this says how that ground looks:
 *
 *   - the boundary, with its {@see Boundary} treatment and scrim, and a legend
 *     row "Boundary" that switches it;
 *   - the zones, as ONE quiet line layer in the dim token, each zone wearing
 *     its name, under every mark a module adds;
 *   - both rows under the heading "The area", the boundary row above the
 *     zones row, and the zones row present even where the area has no zones:
 *     "Zones · 0" is an answer, a missing row is a question.
 *
 * {@see AtlasMap::ground()} puts it on a plate.
 */
final readonly class Ground
{
    /** The legend heading the ground's rows sit under, and a module's own area rows may join. */
    public const string GROUP = 'The area';

    /** The zones layer's id, which is also what its legend row switches. */
    public const string ZONES_LAYER_ID = 'area.zones';

    public const string BOUNDARY_LABEL = 'Boundary';
    public const string ZONES_LABEL = 'Zones';

    /** The edge of the ground is what the plate is about. */
    public const string BOUNDARY_SWATCH = PlatePalette::ACCENT;

    /** A zone is context: present, and not what the reader came for. */
    public const string ZONES_SWATCH = PlatePalette::DIM;

    /**
     * @param array<string, mixed>|null                                 $boundary a GeoJSON geometry; null for ground with no outline
     * @param list<array{name: string, geometry: array<string, mixed>}> $zones    each zone's name and GeoJSON geometry
     * @param bool                                                      $scrim    whether the outside of the boundary starts dimmed
     */
    public function __construct(
        public ?array $boundary,
        public array $zones = [],
        public bool $scrim = true,
    ) {
    }

    /**
     * The ground from GeoJSON text, as a geometry column returns it
     * (ST_AsGeoJSON). Anything that will not parse is not drawn: a boundary
     * that fails is ground without a boundary, a zone that fails is one zone
     * fewer, never a page that fails.
     *
     * @param iterable<array{name: string|null, geom: string|null}> $zones
     */
    public static function fromGeoJson(?string $boundary, iterable $zones, bool $scrim = true): self
    {
        $shapes = [];
        foreach ($zones as $zone) {
            $geometry = self::decode($zone['geom']);
            if (null !== $geometry) {
                // A zone with no name is drawn without a caption rather than
                // left off: the outline is the fact, the name is the caption.
                $shapes[] = ['name' => $zone['name'] ?? '', 'geometry' => $geometry];
            }
        }

        return new self(self::decode($boundary), $shapes, $scrim);
    }

    /**
     * The boundary's legend row, keyed by the id the plate keeps the drawn
     * boundary under; null where there is no boundary to switch.
     */
    public function boundaryRow(): ?LegendItem
    {
        if (null === $this->boundary) {
            return null;
        }

        return new LegendItem(
            label: self::BOUNDARY_LABEL,
            swatch: self::BOUNDARY_SWATCH,
            shape: LayerShape::Line,
            group: self::GROUP,
            layerId: AtlasMap::BOUNDARY_LAYER_ID,
        );
    }

    /**
     * The zones as the one layer they are drawn as — built empty and hidden
     * where there are none, so the row still counts them.
     */
    public function zonesLayer(): GeoJsonLayer
    {
        $features = array_map(
            static fn (array $zone): array => ['type' => 'Feature', 'properties' => ['label' => $zone['name']], 'geometry' => $zone['geometry']],
            $this->zones,
        );

        return new GeoJsonLayer(
            id: self::ZONES_LAYER_ID,
            label: self::ZONES_LABEL,
            features: ['type' => 'FeatureCollection', 'features' => $features],
            swatch: self::ZONES_SWATCH,
            shape: LayerShape::Line,
            visible: [] !== $features,
            count: \count($features),
            group: self::GROUP,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $geoJson): ?array
    {
        if (null === $geoJson || '' === $geoJson) {
            return null;
        }

        try {
            $decoded = json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_string($decoded['type'] ?? null)) {
            return null;
        }

        $geometry = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $geometry[$key] = $value;
            }
        }

        return $geometry;
    }
}
