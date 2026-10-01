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

use Uhifadhi\Bundle\AtlasBundle\Exception\LayerException;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * A body of GeoJSON drawn on a plate, and the legend row that switches it.
 *
 * WHY THE ATLAS MODELS THIS AND UX MAP DOES NOT. UX Map models the shapes a
 * person places one at a time — a marker, a polygon, a circle — and those are
 * passed straight through to it. What a module actually has is a
 * FeatureCollection out of a geometry column: hundreds of features that share
 * one colour, one meaning and one switch. That is a LAYER, and it is what this
 * class is.
 *
 * A layer names exactly ONE source:
 *
 *   - `features` — a decoded FeatureCollection the server already had. It
 *     travels in the page, so the plate draws on the first paint.
 *   - `url` — an endpoint the plate fetches once it is mounted. For a
 *     collection too large to put in a document, or one that is only worth
 *     asking for when someone switches the layer on.
 *
 * Naming both is refused, and naming neither is refused: both are a layer that
 * draws nothing, and a plate that renders and shows nothing is a fault with no
 * message.
 */
final readonly class GeoJsonLayer
{
    /** The atlas's own line colour, so a layer that states no colour is still legible. */
    public const string DEFAULT_SWATCH = PlatePalette::ACCENT;

    /**
     * @param string                    $id        the id the legend row and the drawn layer share; a
     *                                             module namespaces it with its own word ("patrol.tracks")
     * @param array<string, mixed>|null $features  a decoded GeoJSON FeatureCollection
     * @param string|null               $url       an endpoint answering with one, fetched by the plate
     * @param string                    $swatch    the layer's colour, in the legend and on the map, as a
     *                                             TOKEN NAME and never a value ({@see PlatePalette}); a
     *                                             feature may override it with its own `color` property
     * @param bool                      $visible   whether the layer starts drawn — an invisible layer is
     *                                             still built, so its first switch costs no round trip
     * @param int|null                  $count     how many features the legend row states
     * @param string|null               $group     the legend heading this row sits under
     * @param LayerStyle|null           $style     what every feature is drawn with, over the shape's own answer
     * @param list<StyleRule>           $rules     per-feature statements, applied in the order they are written
     * @param string|null               $tooltip   the feature property a hover reads — a floating label, not the
     *                                             permanent halo a feature's own `label` property still wears
     * @param FeaturePopup|null         $popup     which properties a click opens, and where its link goes
     * @param string|null               $featureId the property that identifies a feature, so an element elsewhere
     *                                             on the page can spotlight it by name
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?array $features = null,
        public ?string $url = null,
        public string $swatch = self::DEFAULT_SWATCH,
        public LayerShape $shape = LayerShape::Fill,
        public bool $visible = true,
        public ?int $count = null,
        public ?string $group = null,
        public ?LayerStyle $style = null,
        public array $rules = [],
        public ?string $tooltip = null,
        public ?FeaturePopup $popup = null,
        public ?string $featureId = null,
        /** Whether a feature that names itself (`properties.label`) wears its name as a permanent label — off where the shapes are the ground under another subject. */
        public bool $labels = true,
        /**
         * A SHEET A CLICK OPENS (ruled 30 Sep, #16 C): the address of a JSON
         * answer about one feature, `{id}` standing for the feature's id. The
         * plate flies to the feature and draws the answer as a bounded sheet at
         * its foot - title, subtitle, rows and doors (`AtlasSheet`) - so the
         * plate knows the shape of a sheet and nothing of what it is about.
         */
        public ?string $sheet = null,
    ) {
        if (null === $features && null === $url) {
            throw new LayerException(\sprintf('The layer "%s" names no source: give it either "features" or "url".', $id));
        }

        if (null !== $features && null !== $url) {
            throw new LayerException(\sprintf('The layer "%s" names two sources: give it either "features" or "url", not both.', $id));
        }

        /*
         * A TOKEN AND NEVER A VALUE. A plate's palette is picked to survive
         * satellite ground and turns over with the theme, so a colour
         * published here would be right on one basemap and wrong on the
         * next with nothing on the page to say so.
         */
        if (!PlatePalette::isToken($swatch)) {
            throw new LayerException(\sprintf('The layer "%s" is coloured "%s". A layer names a TOKEN — PlatePalette::OK, or PlatePalette::category($position) for one of a set — and the plate resolves it where it draws.', $id, $swatch));
        }
    }

    /**
     * The legend row this layer states about itself, keyed by the same id the
     * plate keys the drawn layer by — which is what makes the row a switch.
     */
    public function legendItem(): LegendItem
    {
        return new LegendItem(
            label: $this->label,
            swatch: $this->swatch,
            shape: $this->shape,
            group: $this->group,
            count: $this->count,
            layerId: $this->id,
            visible: $this->visible,
        );
    }

    /**
     * What the plate controller is told. The legend's own words — the label,
     * the count, the heading — are rendered by the server and are not repeated
     * here.
     *
     * @return array{
     *     id: string,
     *     features: array<string, mixed>|null,
     *     url: string|null,
     *     swatch: string,
     *     shape: string,
     *     visible: bool,
     *     style: array<string, bool|float|int|string>,
     *     rules: list<array{property: string, values: list<bool|float|int|string>, style: array<string, bool|float|int|string>}>,
     *     tooltip: string|null,
     *     popup: array{title: string, lines: list<string>, href: string|null, linkLabel: string|null}|null,
     *     featureId: string|null,
     *     labels: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'features' => $this->features,
            'url' => $this->url,
            'swatch' => $this->swatch,
            'shape' => $this->shape->value,
            'visible' => $this->visible,
            'style' => $this->style?->toArray() ?? [],
            'rules' => array_map(static fn (StyleRule $rule) => $rule->toArray(), $this->rules),
            'tooltip' => $this->tooltip,
            'popup' => $this->popup?->toArray(),
            'featureId' => $this->featureId,
            'labels' => $this->labels,
            'sheet' => $this->sheet,
        ];
    }
}
