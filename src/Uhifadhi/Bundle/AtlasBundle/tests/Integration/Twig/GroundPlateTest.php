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
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\Ground;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;

/**
 * THE GROUND AS A BROWSER IS SERVED IT: the first legend group is "The
 * area", the boundary row then the zones row with its count, and the zones
 * layer is the first one the plate is told to draw.
 */
final class GroundPlateTest extends TestCase
{
    private const string BOUNDARY = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.4,-3.2],[-29.4,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';
    private const string ZONE = '{"type":"Polygon","coordinates":[[[-29.5,-3.2],[-29.45,-3.2],[-29.45,-3.1],[-29.5,-3.1],[-29.5,-3.2]]]}';

    public function testTheLegendOpensOnTheAreaGroupWithTheZonesCounted(): void
    {
        $page = new Crawler(self::render(static function (AtlasMap $map): void {
            $map->addLayer(new GeoJsonLayer(id: 'sightings', label: 'sightings', features: ['type' => 'FeatureCollection', 'features' => []], group: 'Sightings'));
            $map->ground(Ground::fromGeoJson(self::BOUNDARY, [
                ['name' => 'North', 'geom' => self::ZONE],
                ['name' => 'South', 'geom' => self::ZONE],
            ]));
        }));

        $group = $page->filter('.map-legend .grp')->first();
        self::assertSame('The area', trim($group->filter('b')->text()));

        $rows = $group->filter('.lay');
        self::assertSame(2, $rows->count());
        self::assertSame('atlas.boundary', $rows->eq(0)->attr('data-uhifadhi--atlas-bundle--map-plate-layer-param'));
        self::assertStringStartsWith('Boundary', trim($rows->eq(0)->text()));
        self::assertSame(Ground::ZONES_LAYER_ID, $rows->eq(1)->attr('data-uhifadhi--atlas-bundle--map-plate-layer-param'));
        self::assertStringStartsWith('Zones', trim($rows->eq(1)->text()));
        self::assertSame('2', $rows->eq(1)->filter('em')->text());
        self::assertSame('true', $rows->eq(1)->attr('aria-pressed'));
        self::assertStringContainsString('sw ln', (string) $rows->eq(1)->filter('.sw')->attr('class'));
        self::assertSame('color:var(--plate-dim)', $rows->eq(1)->filter('.sw')->attr('style'));

        self::assertSame('Sightings', trim($page->filter('.map-legend .grp')->eq(1)->filter('b')->text()));
    }

    public function testAnAreaWithNoZonesServesZonesNoughtSwitchedOff(): void
    {
        $page = new Crawler(self::render(static function (AtlasMap $map): void {
            $map->ground(Ground::fromGeoJson(self::BOUNDARY, []));
        }));

        $zones = $page->filter('.map-legend .grp .lay')->eq(1);
        self::assertSame('0', $zones->filter('em')->text());
        self::assertSame('false', $zones->attr('aria-pressed'));
        self::assertStringContainsString('off', (string) $zones->attr('class'));
    }

    private static function render(\Closure $arrange): string
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var MapBuilderInterface $builder */
        $builder = $container->get('test.atlas.map_builder');
        $map = $builder->createMap();
        $arrange($map);

        /** @var Environment $twig */
        $twig = $container->get('test.twig');
        $html = $twig->createTemplate('{{ render_map(map) }}')->render(['map' => $map]);

        $kernel->shutdown();

        return $html;
    }
}
