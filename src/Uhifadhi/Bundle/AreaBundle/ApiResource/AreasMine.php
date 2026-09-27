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

namespace Uhifadhi\Bundle\AreaBundle\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Uhifadhi\Bundle\AreaBundle\Api\State\AreasMineProvider;

/**
 * `GET /api/areas/mine` — EVERYTHING A FIELD CLIENT CACHES AT SIGN-IN so it can
 * work with no network afterwards: the areas the account may work in, how big each
 * one is, the roster it may name on a record, and the boundary it draws.
 *
 * IT LIVES IN THIS BUNDLE BECAUSE THE GROUND DOES. An area, its gazetted edge and
 * the measurement of it are this bundle's; a module that served them would be
 * answering for data it does not own, and every other module would then need its
 * own copy of the same endpoint.
 *
 * THE CLASS IS FOUND WITHOUT REGISTRATION. `ApiResource/` in a registered bundle
 * is a mapped resource path, read off `kernel.bundles_metadata` — so neither this
 * bundle nor an installation writes a `mapping.paths` line, and writing one would
 * DISABLE the project-dir defaults an installation's own resources rely on.
 *
 * @see https://api-platform.com/docs/symfony/state-providers/
 * @see vendor/api-platform/core/src/Symfony/Bundle/DependencyInjection/ApiPlatformExtension.php — `getBundlesResourcesPaths()` adds "<bundle path>/ApiResource"
 */
#[ApiResource(
    shortName: 'AreasMine',
    operations: [
        new Get(
            uriTemplate: '/areas/mine',
            description: 'The areas this account may work in, with their roster and boundaries.',
            /*
             * A KEY THE CONTRACT DESCRIBES IS ALWAYS SENT, even when its
             * answer is null. API Platform drops null properties by default,
             * which would make `postedAreaId` a field that exists only for
             * somebody who is posted — and a typed client reading a document
             * whose shape changes with the data has to treat every field as
             * optional. Null is an ANSWER here ("this person stands
             * nowhere"), not an absence.
             */
            normalizationContext: ['skip_null_values' => false],
            provider: AreasMineProvider::class,
        ),
    ],
)]
final class AreasMine
{
    /**
     * HAND-BUILT ASSOCIATIVE ARRAYS rather than nested objects: these key names
     * are an external contract a released client reads, and an array says exactly
     * what goes across without depending on serializer naming conventions.
     *
     * `posted` IS TRUE ON AT MOST ONE ENTRY — the area the person's standing
     * posting is in, and the same one `postedAreaId` names. Somebody stands at
     * one post at a time (ruled), so there is one or there is none.
     *
     * `stations` IS THE AREA'S POSTS, in the same shape `/stations?near=` hands
     * them over — uuid, name, code, lat, lon, catchment — because that is what
     * the picker and the confirm screen already read, and a second shape for one
     * thing is two parsers kept in step by hand. It was published empty while the
     * platform had no station record; it has one now, and a phone whose cache
     * said "no posts" had to be online to learn the names of the posts it works
     * at. `boundary` is GeoJSON
     * in lon/lat order (RFC 7946), a MultiPolygon or a Polygon depending on
     * whether the ground is one piece, and null for an area whose edge has not
     * been imported.
     *
     * @param list<array{
     *     id: string,
     *     name: string,
     *     areaKm2: float,
     *     stations: list<array{uuid: string, name: string, code: string|null, lat: float|null, lon: float|null, catchmentM: int|null}>,
     *     team: list<array{id: string, name: string}>,
     *     boundary: array<string, mixed>|null,
     *     posted: bool
     * }> $areas
     * @param string|null $postedAreaId WHERE THIS PERSON WORKS — the area of
     *                                  their standing posting, or null where
     *                                  they stand nowhere. A client opens
     *                                  THIS one; without it the phone opened
     *                                  whichever area came first in the
     *                                  alphabet, which is nobody's answer.
     *                                  It always names an entry in `areas`
     *                                  above, so it is a pointer into this
     *                                  payload and never a dangling id
     */
    public function __construct(
        public array $areas = [],
        public ?string $postedAreaId = null,
        /*
         * THE STATION OF THE STANDING POSTING, beside its area (ruled
         * 2026-09-27): check-in and patrol start ask no station — the handset
         * uses this one. Null exactly when postedAreaId is null.
         */
        public ?string $postedStationId = null,
    ) {
    }
}
