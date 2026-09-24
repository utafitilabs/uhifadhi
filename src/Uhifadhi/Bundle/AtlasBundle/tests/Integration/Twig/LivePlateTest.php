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
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveMarks;
use Uhifadhi\Bundle\AtlasBundle\Tests\Integration\TestKernel;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * THE LIVE MARK ON A REAL PLATE — the layer the browser is handed and the key
 * printed under it, rendered by the actual renderer rather than assembled in a
 * unit test.
 *
 * The mark itself is the shell's primitive; what is asserted here is the part
 * the atlas owns: that a position reaches the page as a live feature carrying
 * its own staleness, and that the key beside it says what the plate is showing
 * dimmed and what it is not showing at all.
 */
final class LivePlateTest extends TestCase
{
    private const string NOW = '2026-09-20 09:00:00';

    /** Fifteen-minute pings, so anything past thirty minutes is stale. */
    private static function presence(): LivePresence
    {
        return new LivePresence(
            positions: [
                self::position('J. Mollel', 4),
                self::position('T. Ndosi', 11),
                self::position('K. Parmuat', 261),
            ],
            pingIntervalMinutes: 15,
            asOf: new \DateTimeImmutable(self::NOW),
        );
    }

    private static function position(string $name, int $minutesAgo): LivePosition
    {
        return new LivePosition(
            personUuid: strtolower(str_replace([' ', '.'], ['-', ''], $name)),
            personName: $name,
            clientRef: 'w-1',
            state: DayState::AtPostVerified,
            latitude: -3.2,
            longitude: -29.5,
            recordedAt: new \DateTimeImmutable(self::NOW.' -'.$minutesAgo.' minutes'),
        );
    }

    /**
     * ONE FEATURE PER POSITION, ON THE WIRE. The plate draws from the atlas
     * payload, so this is the contract between the two halves of the mark: the
     * shape says "live" and each feature says whether anybody should still be
     * believing it.
     */
    public function testEveryPositionReachesThePlateAsALiveFeature(): void
    {
        $atlas = self::payload(static fn (AtlasMap $map) => $map->livePositions(self::presence(), withoutPosition: 2));

        $live = [];
        foreach (self::arr($atlas['layers'] ?? null) as $layer) {
            $layer = self::arr($layer);
            if (LiveMarks::LAYER_ID === ($layer['id'] ?? null)) {
                $live = $layer;
            }
        }

        self::assertNotSame([], $live, 'the live layer is in the payload the plate reads');
        self::assertSame('live', $live['shape']);
        self::assertSame(PlatePalette::ACCENT, $live['swatch']);

        $features = array_map(self::arr(...), self::arr(self::arr($live['features'])['features']));
        self::assertCount(3, $features);
        self::assertSame(
            [false, false, true],
            array_map(static fn (array $f): mixed => self::arr($f['properties'])['stale'], $features),
        );
        self::assertSame(
            ['JM', 'TN', 'KP'],
            array_map(static fn (array $f): mixed => self::arr($f['properties'])['initials'], $features),
        );
        self::assertSame('4 h 21', self::arr($features[2]['properties'])['age']);
    }

    /**
     * THE KEY UNDER THE PLATE, in the design's own group and its own words:
     * the live position, the one nobody should believe, and the people who
     * are not on the ground at all.
     */
    public function testTheKeyNamesTheThreeStatesUnderOneHeading(): void
    {
        $crawler = self::render(static fn (AtlasMap $map) => $map->livePositions(self::presence(), withoutPosition: 2));

        $group = $crawler->filter('.map-legend .grp')->reduce(
            static fn (Crawler $node): bool => LiveMarks::GROUP === trim($node->filter('b')->text()),
        );
        self::assertCount(1, $group, 'one heading, because it is one mark in three states');

        self::assertSame(
            ['Live position2 on', 'Stale · older than two intervals1', 'No position2'],
            $group->filter('.lay')->each(static fn (Crawler $n): string => trim(preg_replace('/\s+/', ' ', $n->text()) ?? '')),
        );
    }

    /** Each of the three rows wears the shell's dot, in its own state. */
    public function testEachKeyRowWearsTheLiveDotPrimitive(): void
    {
        $crawler = self::render(static fn (AtlasMap $map) => $map->livePositions(self::presence(), withoutPosition: 2));

        self::assertSame(
            ['livedot', 'livedot stale', 'livedot none'],
            $crawler->filter('.map-legend .lay .sw.dot i')->each(
                static fn (Crawler $n): string => (string) $n->attr('class'),
            ),
        );
    }

    /**
     * AND THE ABSENT ROW IS DRAWN OFF. There is nothing on the ground it could
     * turn off, and a row reading as on would claim the plate is showing the
     * people it is precisely not showing.
     */
    public function testTheRowForNobodyIsDrawnOff(): void
    {
        $crawler = self::render(static fn (AtlasMap $map) => $map->livePositions(self::presence(), withoutPosition: 2));

        $rows = $crawler->filter('.map-legend .lay .sw.dot')->each(
            static fn (Crawler $n): string => (string) $n->closest('.lay')?->attr('class'),
        );

        self::assertSame(['lay', 'lay', 'lay off'], $rows);
    }

    /**
     * A CALLER'S OWN MARK JOINS THE SAME HEADING, AND KEEPS ITS PLACE. The
     * posts are the roster's layer, not the atlas's, and the design draws
     * them in this group and LAST — after the three states of the live mark.
     * Rows come out in the order they were added, so writing them in the
     * design's order is all a caller has to do.
     */
    public function testALayerOfTheCallersOwnCanShareTheHeading(): void
    {
        $crawler = self::render(static function (AtlasMap $map): void {
            $map->livePositions(self::presence());
            $map->addLayer(new GeoJsonLayer(
                id: 'roster.posts',
                label: 'Post',
                features: ['type' => 'FeatureCollection', 'features' => []],
                swatch: PlatePalette::ACCENT,
                shape: LayerShape::Point,
                group: LiveMarks::GROUP,
            ));
        });

        $group = $crawler->filter('.map-legend .grp')->reduce(
            static fn (Crawler $node): bool => LiveMarks::GROUP === trim($node->filter('b')->text()),
        );

        self::assertSame(
            ['Live position2 on', 'Stale · older than two intervals1', 'No position0', 'Post on'],
            $group->filter('.lay')->each(
                static fn (Crawler $n): string => trim(preg_replace('/\s+/', ' ', $n->text()) ?? ''),
            ),
            'One heading, and the rows in the order the caller wrote them.',
        );
    }

    /** A plate with no live layer on it prints no live key. */
    public function testAPlateWithoutLivePositionsSaysNothingAboutThem(): void
    {
        $crawler = self::render(null);

        self::assertCount(0, $crawler->filter('.map-legend .sw.dot'));
    }

    /**
     * Whatever came back out of the JSON, as an array — the payload crosses
     * the wire as text, so every step down into it is a claim this makes
     * loudly rather than a nullsafe that hides a shape change.
     *
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * The atlas payload the plate controller reads off the map element.
     *
     * @return array<array-key, mixed>
     */
    private static function payload(?\Closure $arrange): array
    {
        $crawler = self::render($arrange);
        $extra = $crawler->filter('[data-symfony--ux-leaflet-map--map-extra-value]')
            ->attr('data-symfony--ux-leaflet-map--map-extra-value');

        $decoded = self::arr(json_decode((string) $extra, true, 512, \JSON_THROW_ON_ERROR));

        return self::arr($decoded[AtlasMap::EXTRA_KEY] ?? null);
    }

    private static function render(?\Closure $arrange): Crawler
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
        $html = $twig->createTemplate('{{ render_map(map) }}')->render(['map' => $map]);

        $kernel->shutdown();

        return new Crawler($html);
    }
}
