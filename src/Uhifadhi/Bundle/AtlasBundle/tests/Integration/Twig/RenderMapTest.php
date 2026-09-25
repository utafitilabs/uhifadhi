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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Integration\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\Boundary;
use Uhifadhi\Bundle\AtlasBundle\Model\FeaturePopup;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Bundle\AtlasBundle\Model\PointPick;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Bundle\AtlasBundle\Twig\MapPlateRuntime;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * `render_map()` THROUGH THE REAL RENDERER. Not a string built in a unit test:
 * a booted kernel with UX Map, its Leaflet bridge and Stimulus, so what this
 * asserts is the markup a browser is actually served.
 *
 * The plate is what a module never writes: the wrapper that owns fullscreen,
 * the map element the bridge's controller mounts on, the filter row and the
 * legend. A module writes one Twig call.
 */
final class RenderMapTest extends TestCase
{
    private const array BOUNDARY = [
        'type' => 'Polygon',
        'coordinates' => [[[-29.5, -3.2], [-29.4, -3.2], [-29.4, -3.1], [-29.5, -3.1], [-29.5, -3.2]]],
    ];

    public function testThePlateWrapsTheMapElementAndCarriesTheAtlasController(): void
    {
        $html = self::render();

        self::assertStringContainsString('class="map-plate"', $html);
        self::assertStringContainsString(MapPlateRuntime::CONTROLLER, $html);
        self::assertStringContainsString('map-canvas', $html);
    }

    /**
     * The bridge's own controller must still be on the map element — the atlas
     * extends UX Map, it does not replace it.
     */
    public function testTheUxMapControllerStillMountsTheMap(): void
    {
        self::assertStringContainsString('symfony--ux-leaflet-map--map', self::render());
    }

    public function testTheAtlasPayloadReachesTheBrowserUnderItsOwnKey(): void
    {
        $html = self::render(static function (AtlasMap $map): void {
            $map->boundary(new Boundary(self::BOUNDARY));
        });

        self::assertStringContainsString('&quot;atlas&quot;', $html);
        self::assertStringContainsString('&quot;boundary&quot;', $html);
    }

    public function testALayerRendersALegendRowThatTogglesIt(): void
    {
        $html = self::render(static function (AtlasMap $map): void {
            $map->addLayer(new GeoJsonLayer(
                id: 'sightings.recent',
                label: 'Recent sightings',
                url: '/sightings.geojson',
                shape: LayerShape::Point,
                count: 4,
            ));
        });

        self::assertStringContainsString('map-legend', $html);
        self::assertStringContainsString('Recent sightings', $html);
        self::assertStringContainsString('sightings.recent', $html);
        self::assertStringContainsString('#toggleLayer', $html);
    }

    /**
     * A stated row is a key, not a switch: no toggle action, because there is
     * no layer behind it to switch.
     */
    public function testAStatedLegendRowIsNotAToggle(): void
    {
        $html = self::render(static function (AtlasMap $map): void {
            $map->addLegendItem(new LegendItem(label: 'Boundary only', swatch: PlatePalette::ACCENT));
        });

        self::assertStringContainsString('Boundary only', $html);
        self::assertStringNotContainsString('#toggleLayer', $html);
    }

    /**
     * A PICKING PLATE CARRIES ITS OWN CAPTION under the legend, in the
     * design's words and its three states — at rest, adding, moving — with
     * the readout and the commit hidden until there is a point; and its
     * legend ends with the pin, drawn as one.
     */
    public function testAPickingPlateCarriesTheCaptionInItsThreeStatesAndThePinsKeyRow(): void
    {
        $html = self::render(static function (AtlasMap $map): void {
            $map->pickPoint(new PointPick('station-add', 'the new station'));
        });
        $plate = new Crawler($html);
        $controller = MapPlateRuntime::CONTROLLER;

        $caption = $plate->filter('.map-plate > .pickcap');
        self::assertCount(1, $caption);
        self::assertSame('Pick the point · click the ground', $caption->filter('[data-atlas-pick-state="rest"]')->text());
        self::assertNull($caption->filter('[data-atlas-pick-state="rest"]')->attr('hidden'));
        self::assertStringStartsWith('Adding', $caption->filter('[data-atlas-pick-state="add"]')->text());
        self::assertNotNull($caption->filter('[data-atlas-pick-state="add"]')->attr('hidden'));
        self::assertStringEndsWith('· drag the pin', $caption->filter('[data-atlas-pick-state="move"]')->text());
        self::assertCount(2, $caption->filter('[data-atlas-pick-named]'));
        self::assertNotNull($caption->filter('[data-atlas-pick-point]')->attr('hidden'));
        self::assertSame($controller.'#usePoint', $caption->filter('button[data-atlas-pick-use]')->attr('data-action'));
        self::assertSame('Use this point', $caption->filter('button[data-atlas-pick-use]')->text());

        $pin = $plate->filter('.map-legend .lay')->last();
        self::assertStringContainsString('The pin', $pin->text());
        self::assertSame('being placed', $pin->filter('em')->text());
        self::assertCount(1, $pin->filter('.sw.pin'));
    }

    /** A plate that picks nothing has no caption. */
    public function testAPlateThatPicksNothingHasNoCaption(): void
    {
        self::assertStringNotContainsString('pickcap', self::render());
    }

    public function testAMapWithNoLegendRendersNoLegend(): void
    {
        self::assertStringNotContainsString('map-legend', self::render());
    }

    /**
     * The filter row is one row ABOVE the map, inside the plate, so it comes
     * along into fullscreen.
     */
    public function testTheFilterSlotRendersAboveTheMap(): void
    {
        $html = self::render(filters: '<span class="chip">This week</span>');

        self::assertStringContainsString('map-filters', $html);
        self::assertLessThan(
            strpos($html, 'map-canvas') ?: \PHP_INT_MAX,
            strpos($html, 'map-filters') ?: \PHP_INT_MAX,
            'The filter row must precede the map, or it cannot be a row above it.',
        );
    }

    /**
     * THE LEGEND IS DRAWN UNDER THE MAP, as a child of the plate that FOLLOWS
     * the map body — not inside the body the plate's height sizes, and not
     * absolutely positioned over the imagery, where on a short plate it covers
     * the ground it describes. The design draws it this way too: a `.maplegend`
     * after the `.viewer` it explains.
     */
    public function testTheLegendIsRenderedBelowTheMapBodyInsideThePlate(): void
    {
        $plate = (new Crawler(self::render(static function (AtlasMap $map): void {
            $map->addLayer(new GeoJsonLayer(id: 'zones', label: 'Zones', features: []));
        })))->filter('.map-plate');

        self::assertCount(1, $plate->children('.map-body'), 'The map body is the plate\'s own child.');
        self::assertCount(1, $plate->children('.map-legend'), 'And so is the legend — a sibling of the body, not a child of it.');
        self::assertCount(1, $plate->filter('.map-body > .viewer'), 'The imagery frame is inside the body the height sizes.');
        self::assertCount(0, $plate->filter('.map-body .map-legend'));
        self::assertSame(
            ['map-body', 'map-legend'],
            $plate->children()->each(static fn (Crawler $child): string => (string) $child->attr('class')),
            'The legend comes after the map it explains, and nothing else is in the plate.',
        );
    }

    public function testAttributesReachTheMapElement(): void
    {
        $html = self::render(attributes: ['aria-label' => 'The area and its zones', 'role' => 'img']);

        self::assertStringContainsString('aria-label="The area and its zones"', $html);
        self::assertStringContainsString('role="img"', $html);
    }

    /**
     * THE STYLE RULES TRAVEL AS DATA. What used to be a `style(feature)`
     * callback in a module's own controller is a property name, the values that
     * satisfy it and the style they earn — which is a thing a page can carry.
     */
    public function testALayersStyleRulesReachTheBrowser(): void
    {
        $html = self::render(static function (AtlasMap $map): void {
            $map->addLayer(new GeoJsonLayer(
                id: 'sightings.recent',
                label: 'Recent sightings',
                url: '/sightings.geojson',
                shape: LayerShape::Point,
                rules: [StyleRule::when('status', 'closed')->fill(false)],
                tooltip: 'title',
                popup: FeaturePopup::of('title', 'href'),
                featureId: 'reference',
            ));
        });

        self::assertStringContainsString('&quot;rules&quot;', $html);
        self::assertStringContainsString('&quot;property&quot;:&quot;status&quot;', $html);
        self::assertStringContainsString('&quot;tooltip&quot;:&quot;title&quot;', $html);
        self::assertStringContainsString('&quot;featureId&quot;:&quot;reference&quot;', $html);
    }

    /**
     * A CUSTOM PROPERTY HANDED TO render_map() SIZES THE PLATE, not the canvas
     * inside it — so a screen states its own map height without restating one
     * word of the plate's own layout.
     */
    public function testACustomPropertyInTheAttributesSizesThePlate(): void
    {
        $html = self::render(attributes: ['--map-plate-height' => 'min(46vh,440px)', 'role' => 'img']);

        self::assertStringContainsString('<div class="map-plate" style="--map-plate-height:min(46vh,440px)"', $html);
        self::assertStringNotContainsString('--map-plate-height', substr($html, strpos($html, 'map-canvas') ?: 0));
    }

    /**
     * THE PLATE IS ADDRESSABLE IN A PAGE, and that is what makes a filter change
     * survive fullscreen: the plate fetches the same address with the new query
     * and has to find ITS OWN plate in the answer, then the three subtrees of it
     * that a filter can change. All four are reachable from the hook, in a
     * document nothing but the markup was handed to.
     */
    public function testThePlatesSubtreesAreAddressableInAServedPage(): void
    {
        $page = new Crawler(self::render(
            static function (AtlasMap $map): void {
                $map->addLayer(new GeoJsonLayer(id: 'zones', label: 'Zones', features: []));
            },
            filters: '<form method="get"><button name="week" value="this">This week</button></form>',
        ));

        $plate = $page->filter('['.MapPlateRuntime::PLATE_HOOK.']');

        self::assertCount(1, $plate);
        self::assertCount(1, $plate->filter('.map-filters form'));
        self::assertCount(1, $plate->filter('.map-canvas'));
        self::assertCount(1, $plate->filter('.map-legend'));
    }

    /**
     * @param \Closure(AtlasMap): void|null $arrange
     * @param array<string, bool|string>    $attributes
     */
    private static function render(?\Closure $arrange = null, array $attributes = [], ?string $filters = null): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var MapBuilderInterface $builder */
        $builder = $container->get('test.atlas.map_builder');
        $map = $builder->createMap();
        $arrange?->__invoke($map);

        /** @var Environment $twig */
        $twig = $container->get('test.twig');
        $html = $twig->createTemplate('{{ render_map(map, attributes, filters) }}')->render([
            'map' => $map,
            'attributes' => $attributes,
            'filters' => $filters,
        ]);

        $kernel->shutdown();

        return $html;
    }
}
