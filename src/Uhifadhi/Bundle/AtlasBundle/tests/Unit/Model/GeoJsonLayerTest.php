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
use Uhifadhi\Bundle\AtlasBundle\Exception\LayerException;
use Uhifadhi\Bundle\AtlasBundle\Model\FeaturePopup;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * A layer is either features the server already has or a url the browser
 * fetches. The two ways of saying "nothing to draw" — neither source, or both —
 * are refused where the module wrote them rather than in a browser at 3am.
 */
final class GeoJsonLayerTest extends TestCase
{
    private const array FEATURES = ['type' => 'FeatureCollection', 'features' => []];

    public function testAnInlineLayerCarriesItsFeatureCollection(): void
    {
        $layer = new GeoJsonLayer(id: 'sightings.recent', label: 'Recent sightings', features: self::FEATURES);

        self::assertSame([
            'id' => 'sightings.recent',
            'features' => self::FEATURES,
            'url' => null,
            'swatch' => GeoJsonLayer::DEFAULT_SWATCH,
            'shape' => 'fill',
            'visible' => true,
            'style' => [],
            'rules' => [],
            'tooltip' => null,
            'popup' => null,
            'featureId' => null,
            'labels' => true,
            'sheet' => null,
        ], $layer->toArray());
    }

    public function testAUrlLayerCarriesTheUrlTheControllerFetches(): void
    {
        $layer = new GeoJsonLayer(
            id: 'sightings.all',
            label: 'Every sighting',
            url: '/sightings/features.geojson',
            swatch: PlatePalette::ACCENT,
            shape: LayerShape::Point,
            visible: false,
        );

        self::assertSame([
            'id' => 'sightings.all',
            'features' => null,
            'url' => '/sightings/features.geojson',
            'swatch' => PlatePalette::ACCENT,
            'shape' => 'point',
            'visible' => false,
            'style' => [],
            'rules' => [],
            'tooltip' => null,
            'popup' => null,
            'featureId' => null,
            'labels' => true,
            'sheet' => null,
        ], $layer->toArray());
    }

    /**
     * THE WHOLE OF WHAT A MODULE SAYS ABOUT HOW A LAYER LOOKS, and all of it is
     * data: a base style, rules keyed on the features' own properties, the
     * property a hover reads, the properties a popup reads, and the property
     * that identifies a feature. No callback crosses the wire, which is what
     * lets one controller draw every module's layers.
     */
    public function testALayerCarriesItsStyleRulesTooltipPopupAndFeatureId(): void
    {
        $layer = new GeoJsonLayer(
            id: 'sightings.recent',
            label: 'Recent sightings',
            features: self::FEATURES,
            shape: LayerShape::Point,
            style: new LayerStyle(radius: 6.0, weight: 1.5),
            rules: [
                StyleRule::when('status', 'closed')->fill(false),
                StyleRule::when('severity', ['high', 'critical'])->dashArray('4 3'),
            ],
            tooltip: 'label',
            popup: FeaturePopup::of('title', 'href'),
            featureId: 'reference',
        );

        $payload = $layer->toArray();

        self::assertSame(['weight' => 1.5, 'radius' => 6.0], $payload['style']);
        self::assertSame([
            ['property' => 'status', 'values' => ['closed'], 'style' => ['fill' => false]],
            ['property' => 'severity', 'values' => ['high', 'critical'], 'style' => ['dashArray' => '4 3']],
        ], $payload['rules']);
        self::assertSame('label', $payload['tooltip']);
        self::assertSame(['title' => 'title', 'lines' => [], 'href' => 'href', 'linkLabel' => null], $payload['popup']);
        self::assertSame('reference', $payload['featureId']);
    }

    /**
     * A LAYER MAY DECLINE ITS LABELS: where its shapes are the ground under
     * another subject, the names it carries are for the legend, not the
     * imagery — and the controller reads that as one flag.
     */
    public function testALayerMayDeclineItsLabels(): void
    {
        $layer = new GeoJsonLayer(id: 'zones', label: 'Zones', features: self::FEATURES, labels: false);

        self::assertFalse($layer->toArray()['labels']);
        self::assertTrue(new GeoJsonLayer(id: 'zones', label: 'Zones', features: self::FEATURES)->toArray()['labels'], 'On by default: a shape that names itself wears its name.');
    }

    /** A base style on its own is a whole statement; rules are optional on top of it. */
    public function testALayerMayDeclareTheStyleWithoutTheRest(): void
    {
        $payload = new GeoJsonLayer(
            id: 'sightings.recent',
            label: 'Recent sightings',
            features: self::FEATURES,
            style: new LayerStyle()->opacity(0.4),
        )->toArray();

        self::assertSame(['opacity' => 0.4], $payload['style']);
        self::assertSame([], $payload['rules']);
    }

    public function testALayerWithNoSourceIsRefused(): void
    {
        $this->expectException(LayerException::class);

        new GeoJsonLayer(id: 'sightings.recent', label: 'Recent sightings');
    }

    public function testALayerWithTwoSourcesIsRefused(): void
    {
        $this->expectException(LayerException::class);

        new GeoJsonLayer(
            id: 'sightings.recent',
            label: 'Recent sightings',
            features: self::FEATURES,
            url: '/sightings/features.geojson',
        );
    }

    /**
     * The legend row a layer states about itself: the same id the controller
     * keys the drawn layer by, so clicking the row reaches the layer.
     */
    public function testALayerStatesItsOwnLegendRow(): void
    {
        $item = new GeoJsonLayer(
            id: 'sightings.recent',
            label: 'Recent sightings',
            features: self::FEATURES,
            count: 12,
            group: 'Sightings',
        )->legendItem();

        self::assertSame('sightings.recent', $item->layerId);
        self::assertSame('Recent sightings', $item->label);
        self::assertSame(12, $item->count);
        self::assertSame('Sightings', $item->group);
        self::assertTrue($item->visible);
    }
}
