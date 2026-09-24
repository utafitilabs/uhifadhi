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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;

/**
 * EVERY PLATE SAYS WHAT IT IS ABOUT.
 *
 * A plate framed on everything it drew is a plate framed on an accident: on
 * the zones tab the boundary overflowed its card by a hundred pixels a side,
 * and on a zone's page the zone came out at a quarter of the plate's width
 * with the rest of the park around it. What a page is about is the page's to
 * state, so every surface states it — and a test says so, because a new page
 * that forgets renders a map that looks fine until somebody measures it.
 *
 * THE SUBJECT IS NOT THE ONLY THING DRAWN. A zone's page still draws the
 * whole area, the other zones and every post: that is the context a zone is
 * read against. The subject is what the plate OPENS on.
 */
#[CoversNothing]
final class PlateSubjectTest extends WebTestCase
{
    public function testTheOverviewOpensOnTheArea(): void
    {
        self::assertSubject($this->plateOf(''), self::AREA);
    }

    public function testTheZonesTabOpensOnTheArea(): void
    {
        self::assertSubject($this->plateOf('/zones', zoned: true), self::AREA);
    }

    public function testTheStationsTabOpensOnTheArea(): void
    {
        self::assertSubject($this->plateOf('/stations', posted: true), self::AREA);
    }

    public function testTheConfigureSectionsOpenOnTheArea(): void
    {
        self::assertSubject($this->plateOf('/zones/settings'), self::AREA);
        self::assertSubject($this->plateOf('/stations/settings'), self::AREA);
    }

    /** A zone's page is about the zone; the area around it is context. */
    public function testAZonesRecordOpensOnTheZone(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area);

        $subject = self::subjectIn($this->body('/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString()));

        self::assertNotNull($subject);
        self::assertNotSame(json_encode(self::areaGeometry()), json_encode($subject['geojson']), 'the record opened on the whole area');
        self::assertNull($subject['zoom'], 'a zone has an extent, so no zoom is stated');
    }

    /**
     * A POST HAS NO EXTENT, so the plate cannot be fitted to it: the design's
     * own window over the park is what the zoom says.
     */
    public function testAStationsRecordOpensOnThePostAtTheDesignsZoom(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2);

        $subject = self::subjectIn($this->body('/areas/'.$area->getUuidString().'/stations/'.$station->getUuidString()));

        self::assertNotNull($subject);
        self::assertSame('Point', $subject['geojson']['type'] ?? null);
        self::assertSame(12, $subject['zoom']);
    }

    private const string AREA = 'the area';

    /** @param array{geojson: array<string, mixed>, zoom: int|null}|null $subject */
    private static function assertSubject(?array $subject, string $what): void
    {
        self::assertNotNull($subject, \sprintf('a plate that says nothing about what it is about opens on %s by accident', $what));
        // COMPARED AS JSON: the geometry makes the round trip through the
        // payload, where -30.0 comes back as -30, and a coordinate is the
        // same place either way.
        self::assertSame(json_encode(self::areaGeometry()), json_encode($subject['geojson']));
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    /**
     * The subject of the plate one of this area's pages renders — with the
     * ground and the posts a page needs before it draws one at all.
     *
     * @return array{geojson: array<string, mixed>, zoom: int|null}|null
     */
    private function plateOf(string $tail, bool $zoned = false, bool $posted = false): ?array
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        if ($zoned) {
            $this->aZone($area);
        }

        if ($posted) {
            /** @var StationService $stations */
            $stations = static::getContainer()->get('test_public.area.stations');
            $stations->add($area, 'Eastgate Post', -29.75, -3.2);
        }

        return self::subjectIn($this->body('/areas/'.$area->getUuidString().$tail));
    }

    /** @return array{geojson: array<string, mixed>, zoom: int|null}|null */
    private static function subjectIn(string $body): ?array
    {
        // THE PAYLOAD RIDES ON UX MAP'S OWN ELEMENT, which is where the
        // plate controller reads it off `event.detail.extra.atlas`.
        preg_match('/-map-extra-value="([^"]*)"/', $body, $found);
        self::assertSame(2, \count($found), 'the page renders a map plate');

        /** @var array{atlas?: array{subject?: array{geojson: array<string, mixed>, zoom: int|null}|null}} $extra */
        $extra = json_decode(html_entity_decode($found[1] ?? '', \ENT_QUOTES), true);

        return $extra['atlas']['subject'] ?? null;
    }

    /** @return array<string, mixed> */
    private static function areaGeometry(): array
    {
        /** @var array<string, mixed> $geometry */
        $geometry = json_decode(self::A_BOUNDARY, true);

        return $geometry;
    }
}
