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

namespace Uhifadhi\Core\Tests\Core;

use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/areas/mine` — everything a handset caches at sign-in so it can work
 * with no network afterwards: the areas the account may work in, their size, the
 * roster it may name on a record, and the boundary geometry it draws.
 *
 * "MINE" IS DECIDED BY THE PLATFORM'S OWN AUTHORITY and not by a query written
 * for this endpoint: an area is in the answer when `area.view` is granted FOR
 * THAT AREA, which is the same question every area screen asks. So an account
 * confined to one area is handed one area, and an account that may see nothing
 * is handed an empty list rather than the installation's whole estate.
 */
final class FieldAreasEndpointTest extends FieldApiTestCase
{
    private const string ENDPOINT = '/api/areas/mine';

    public function testNoTokenIsUnauthorized(): void
    {
        $this->get(self::ENDPOINT);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testARefusalCarriesTheApiErrorDocument(): void
    {
        $body = $this->get(self::ENDPOINT);

        self::assertSame(['code', 'message', 'retryable', 'details'], array_keys($body));
        self::assertSame('unauthorized', self::leaf($body, 'code'));
        self::assertFalse(self::leaf($body, 'retryable'));
    }

    public function testTheAnswerIsTheContractsExactDocument(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $ranger = $this->ranger();

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['areas', 'postedAreaId'], array_keys($body));
        self::assertCount(1, self::nested($body, 'areas'));

        $sent = self::nested($body, 'areas', 0);
        self::assertSame(['id', 'name', 'areaKm2', 'stations', 'team', 'boundary', 'posted'], array_keys($sent));

        // The public address, never the sequential key: an area is a UUID
        // everywhere in this product, so a client round-trips a UUID.
        self::assertSame($area->getUuidString(), $sent['id']);
        self::assertSame('Northern Conservation Reserve', $sent['name']);
    }

    /**
     * MEASURED ON THE SPHEROID, in the database — the way a surveyor measures
     * ground rather than by multiplying degrees. The rectangle every area here is
     * drawn from is roughly 9,900 km², asserted as a range because the exact
     * figure is PostGIS's spheroid and not a number worth pinning to six digits.
     */
    public function testTheAreaIsMeasuredGeodesicallyInSquareKilometres(): void
    {
        $this->area('Northern Conservation Reserve');
        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        $km2 = self::leaf($body, 'areas', 0, 'areaKm2');

        self::assertIsFloat($km2);
        self::assertGreaterThan(9_000.0, $km2);
        self::assertLessThan(11_000.0, $km2);
    }

    /**
     * THE AREA'S POSTS, IN THE SHAPE `/stations?near=` ALREADY HANDS OVER.
     *
     * This was published empty while the platform had no station record. It
     * has one now, and a phone whose cache said "no posts" had to be online
     * to learn the names of the posts it works at — which is the one thing
     * this endpoint exists to prevent.
     *
     * ONE SHAPE FOR ONE THING: the picker and the confirm screen already read
     * these rows from the near-endpoint, and a second shape would be two
     * parsers kept in step by hand.
     */
    public function testStationsAreTheAreasPostsInTheShapeTheNearEndpointUses(): void
    {
        $area = $this->area('Northern Conservation Reserve');
        $this->station($area, 'Eastgate Post', -29.5, -3.2, 300, 'ST-01');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));
        $stations = self::nested($body, 'areas', 0, 'stations');

        self::assertCount(1, $stations);

        $post = $stations[0];
        self::assertIsArray($post);
        self::assertSame(['uuid', 'name', 'code', 'lat', 'lon', 'catchmentM'], array_keys($post));
        self::assertSame('Eastgate Post', $post['name']);
        self::assertSame('ST-01', $post['code'], 'what a ranger says on the radio, beside the name');
        self::assertSame(300, $post['catchmentM']);
    }

    /**
     * THE PHONE OPENS WHERE THE PERSON WORKS, not the first name in the
     * alphabet.
     *
     * `postedAreaId` is the area of their standing posting — posting →
     * station → area, read through the posting and never from what they have
     * recorded lately — and the entry it names carries `posted: true`.
     * Somebody covering a shift elsewhere for a week would otherwise have the
     * phone open the wrong ground for a month afterwards.
     */
    public function testTheAnswerNamesTheAreaThePersonIsPostedIn(): void
    {
        $first = $this->area('Aardvark Reserve');
        $second = $this->area('Zebra Reserve');
        $ranger = $this->ranger();
        $this->postTo($this->station($second, 'Eastgate Post', -29.5, -3.2), $ranger);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame($second->getUuidString(), $body['postedAreaId']);
        self::assertSame(
            [false, true],
            array_map(static fn (mixed $a): mixed => \is_array($a) ? $a['posted'] : null, self::nested($body, 'areas')),
            'the alphabet still orders the list; the posting says which one to open',
        );
        self::assertNotSame($first->getUuidString(), $body['postedAreaId']);
    }

    /**
     * SOMEBODY WHO STANDS NOWHERE GETS NULL, and that is an ordinary state —
     * an analyst, a coordinator, somebody between postings. The client opens
     * its picker rather than a guess.
     */
    public function testSomebodyWhoStandsNowhereIsPostedNowhere(): void
    {
        $this->area('Northern Conservation Reserve');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertNull($body['postedAreaId']);
        self::assertSame(
            [false],
            array_map(static fn (mixed $a): mixed => \is_array($a) ? $a['posted'] : null, self::nested($body, 'areas')),
        );
    }

    /**
     * A POSTING INTO GROUND THEY MAY NOT VIEW POINTS AT NOTHING — and the
     * ruling is that PERMISSION WINS.
     *
     * The pointer only ever points into this payload: an area the person may
     * not view is not in the list, so naming it would hand the client an id
     * it cannot open, and would tell somebody an area exists that they are
     * not allowed to see — which is the one thing an endpoint handing out a
     * list must not do. A posting is where they WORK, but this endpoint
     * answers "what may this account open", and a posting is not a grant. If
     * it should be, that is a change to the permission model and not to a
     * cache.
     */
    public function testAPostingIntoGroundTheyMayNotViewNamesNothing(): void
    {
        $theirs = $this->area('Aardvark Reserve');
        $elsewhere = $this->area('Zebra Reserve');

        // Their authority is confined to one area by their department's scope.
        $ranger = $this->ranger(department: $this->areaDepartment('Aardvark Wardens', $theirs));
        $this->postTo($this->station($elsewhere, 'Eastgate Post', -29.5, -3.2), $ranger);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(
            ['Aardvark Reserve'],
            array_map(static fn (mixed $a): mixed => \is_array($a) ? $a['name'] : null, self::nested($body, 'areas')),
            'the list is what they may view, and that has not changed',
        );
        self::assertNull($body['postedAreaId'], 'a pointer into this payload, or nothing at all');
    }

    /** An area with no posts says so as an empty list, which is a real answer. */
    public function testAnAreaWithNoPostsCarriesAnEmptyList(): void
    {
        $this->area('Northern Conservation Reserve');
        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertSame([], self::nested($body, 'areas', 0, 'stations'));
    }

    /**
     * The roster, by name, with the identifier a handset sends back on a record:
     * the service number where there is one, the address otherwise. Whatever this
     * list calls somebody is exactly what a record's `team` may name.
     */
    public function testTheRosterNamesEverybodyByTheIdentifierARecordCarries(): void
    {
        $this->area('Northern Conservation Reserve');
        $this->officeStaff('Naomi', 'Kileo');
        $ranger = $this->ranger();

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame([
            ['id' => 'n.kileo@example.test', 'name' => 'Naomi Kileo'],
            ['id' => 'sl-0142', 'name' => 'Witness Mbise'],
        ], self::nested($body, 'areas', 0, 'team'));
    }

    /**
     * GeoJSON as an OBJECT in lon/lat order (RFC 7946), which is also PostGIS's
     * order.
     *
     * EITHER SPELLING OF THE RING IS CORRECT. Ground is stored as a MultiPolygon
     * because a gazetted edge is regularly more than one piece, and PostGIS hands
     * back a Polygon when it is only one; both are valid RFC 7946 and a client
     * accepts both, so pinning one here would be pinning an accident of the
     * fixture rather than the contract.
     */
    public function testTheBoundaryIsGeoJsonInLonLatOrder(): void
    {
        $this->area('Northern Conservation Reserve');
        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        $boundary = self::nested($body, 'areas', 0, 'boundary');

        self::assertContains($boundary['type'], ['Polygon', 'MultiPolygon']);

        // Longitude first: -30 is the ring's west edge, -3.6 its south. Compared
        // numerically because PostGIS writes a whole degree without a fraction and
        // JSON has one number type, so the west edge arrives as -30 and the south
        // as -3.6 — a difference of notation and not of value.
        $ring = 'Polygon' === $boundary['type']
            ? self::nested($boundary, 'coordinates', 0, 0)
            : self::nested($boundary, 'coordinates', 0, 0, 0);
        self::assertEqualsWithDelta(-30.0, $ring[0], 0.0001);
        self::assertEqualsWithDelta(-3.6, $ring[1], 0.0001);
    }

    /** An area whose edge has not been imported yet: null, not an empty object. */
    public function testAnAreaWithNoBoundaryIsSentANullBoundary(): void
    {
        $this->areaWithoutBoundary('Southern Block');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertNull(self::leaf($body, 'areas', 0, 'boundary'));
        self::assertSame(0.0, self::leaf($body, 'areas', 0, 'areaKm2'));
    }

    /** BY NAME, because a handset's picker must not reorder between two syncs. */
    public function testTheAreasAreOrderedByName(): void
    {
        $this->area('Western Corridor');
        $this->area('Eastern Escarpment');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertSame(
            ['Eastern Escarpment', 'Western Corridor'],
            array_column(self::nested($body, 'areas'), 'name'),
        );
    }

    /**
     * AN ACCOUNT CONFINED TO ONE AREA IS HANDED ONE AREA. Authority is the scope
     * of the position's department, and the voter compares it against each area
     * in turn — so this endpoint narrows exactly where every screen narrows.
     */
    public function testAnAreaLevelAccountIsHandedOnlyItsOwnArea(): void
    {
        $mine = $this->area('Northern Conservation Reserve');
        $this->area('Southern Block');

        $ranger = $this->ranger(department: $this->areaDepartment('Rangers', $mine));

        $body = $this->get(self::ENDPOINT, $this->tokenFor($ranger));

        self::assertSame(['Northern Conservation Reserve'], array_column(self::nested($body, 'areas'), 'name'));
    }

    /** Org-level authority is the absence of a boundary: every area. */
    public function testAnOrgLevelAccountIsHandedEveryArea(): void
    {
        $this->area('Northern Conservation Reserve');
        $this->area('Southern Block');

        $body = $this->get(self::ENDPOINT, $this->tokenFor($this->ranger()));

        self::assertCount(2, self::nested($body, 'areas'));
    }

    /**
     * AN EMPTY LIST IS AN ANSWER. An account that may see no area is told so
     * rather than refused: the contract has no "you have no areas" failure, and a
     * handset that met a 403 here would stop syncing instead of showing an empty
     * picker.
     */
    public function testAnAccountThatMaySeeNoAreaIsHandedAnEmptyList(): void
    {
        $this->area('Northern Conservation Reserve');
        $blind = $this->ranger('sl-0777', grants: ['modules.read']);

        $body = $this->get(self::ENDPOINT, $this->tokenFor($blind));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([], self::nested($body, 'areas'));
    }
}
