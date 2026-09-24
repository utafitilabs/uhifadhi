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

namespace Uhifadhi\Bundle\AreaBundle\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Api\FieldRoster;
use Uhifadhi\Bundle\AreaBundle\ApiResource\AreasMine;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Service\DutyStationService;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * Answers `GET /api/areas/mine`: builds a field client's offline cache.
 *
 * "MINE" IS THE PLATFORM'S OWN AUTHORITY QUESTION, ASKED ONCE PER AREA, and not a
 * query written for this endpoint. An area is in the answer when `areas.read` is
 * granted FOR THAT AREA — the same question every area screen asks — so the
 * narrowing is the permission model's and can never drift from it: a tier holds
 * every area, an org-level position holds every area, and somebody whose
 * department is confined to one area is handed that one.
 *
 * THE AREA IS PASSED AS THE SUBJECT, which is the whole reason the voter takes
 * one. Asking without it would answer "does this person have authority anywhere?",
 * which is a different and much weaker question, and it is the one an endpoint that
 * hands out a list must not ask.
 *
 * AN EMPTY LIST IS AN ANSWER, NOT A REFUSAL. The contract has no "you have no
 * areas" failure, and a client that met a 403 here would stop syncing instead of
 * showing an empty picker.
 *
 * THE PAIR IS WRITTEN AS A STRING, as every gated screen in this bundle writes
 * it: concern and verb are the platform's published vocabulary, and an enum
 * constant from elsewhere would make the package that owns the account a hard
 * dependency of owning ground.
 *
 * @implements ProviderInterface<AreasMine>
 *
 * @see https://api-platform.com/docs/core/state-providers/ — a provider implements ProviderInterface and an operation names it in `provider:`
 * @see vendor/api-platform/core/src/State/ProviderInterface.php — the one method; the 'api_platform.state_provider' tag and its `key` are written by hand in config/field_api.php, a reusable bundle being unautoconfigured
 */
final readonly class AreasMineProvider implements ProviderInterface
{
    /** Seeing an area and everything recorded inside it — the Areas concern, read. */
    private const string PERMISSION = 'areas.read';

    /**
     * Roughly 55 m on the ground. A client caches the boundary on a phone and
     * draws it at zooms where a vertex every few metres is invisible, so the full
     * survey geometry would be megabytes spent on nothing. PreserveTopology, not
     * plain Simplify: a ring that self-intersects after thinning draws as a torn
     * shape.
     */
    private const float FIELD_SIMPLIFY_DEGREES = 0.0005;

    public function __construct(
        private AreaOfInterestRepository $areas,
        private FieldRoster $roster,
        private AuthorizationCheckerInterface $authorization,
        private DutyStationService $stations,
        private PostingRepository $postings,
        private TokenStorageInterface $tokens,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AreasMine
    {
        // Read once, outside the loop: the roster is the same list whichever
        // piece of ground somebody is standing on.
        $team = $this->roster->members();
        $posted = $this->postedArea();

        $areas = [];
        // BY NAME, because a client's picker must not reorder between two syncs;
        // insertion order is a fact nobody outside the database can see.
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $area) {
            if (!$this->authorization->isGranted(self::PERMISSION, $area)) {
                continue;
            }

            $areas[] = [
                // The public address, never the sequential key: an area is a
                // UUID everywhere in this product, and a client only ever
                // round-trips the value.
                'id' => (string) $area->getUuidString(),
                'name' => (string) $area->getName(),
                'areaKm2' => $this->areaKm2($area),
                /*
                 * THE AREA'S POSTS, IN THE SAME SHAPE `/stations?near=` HANDS
                 * THEM OVER — uuid, name, code, lat, lon, catchment.
                 *
                 * This was published EMPTY, with a note saying the platform
                 * had no station record. It has one now, and a phone that
                 * cached an empty list at sign-in had to be online to learn
                 * the names of the posts it works at — which is the one thing
                 * this endpoint exists to prevent.
                 *
                 * ONE SHAPE FOR ONE THING. The near-endpoint's rows are what
                 * the picker and the confirm screen already read, so handing
                 * the cache a different shape would make a client parse the
                 * same post two ways and keep them in step by hand.
                 *
                 * UNSORTED BY DISTANCE, because there is no standing to be
                 * near: the cache is taken at sign-in and the near query is
                 * asked from wherever somebody is at the time.
                 */
                'stations' => $this->stations->listFor($area),
                'team' => $team,
                'boundary' => $this->boundary($area),
                'posted' => null !== $posted && $posted === $area->getUuidString(),
            ];
        }

        /*
         * THE POINTER ONLY EVER POINTS INTO THIS PAYLOAD. An area the person
         * may not view is not in the list above, so naming it here would hand
         * a client an id it cannot open — and would tell somebody an area
         * exists that they are not allowed to see, which is the one thing an
         * endpoint that hands out a list must not do.
         *
         * SO PERMISSION WINS OVER THE POSTING, and that is the ruling for the
         * odd case: somebody posted into ground they may not view gets their
         * viewable areas and no pointer, exactly as if they stood nowhere.
         * The posting is where they WORK, but this endpoint answers "what may
         * this account open", and a posting is not a grant — if it should be,
         * that is a change to the permission model and not to a cache.
         */
        $reachable = null !== $posted && [] !== array_filter($areas, static fn (array $one): bool => $one['id'] === $posted);

        return new AreasMine($areas, $reachable ? $posted : null);
    }

    /**
     * THE AREA THIS PERSON WORKS IN — read through the posting, never by
     * recorder.
     *
     * A posting is the statement of where somebody works: posting → station →
     * area, one hop each, and somebody stands at one post at a time (ruled),
     * so there is one answer or there is none. What a person has RECORDED
     * lately is a different question with a different answer — somebody
     * covering a shift somewhere else for a week would have the phone open
     * the wrong ground for a month afterwards.
     *
     * NULL WHERE THEY STAND NOWHERE, which is an ordinary state: an analyst,
     * a coordinator, somebody between postings. The client opens its picker
     * rather than a guess.
     */
    private function postedArea(): ?string
    {
        $person = $this->tokens->getToken()?->getUser();
        if (!$person instanceof UserInterface) {
            return null;
        }

        $standing = $this->postings->findStandingByPerson($person);

        return [] === $standing ? null : $standing[0]->getStation()?->getArea()?->getUuidString();
    }

    /**
     * MEASURED ON THE SPHEROID, IN THE DATABASE — the way a surveyor measures
     * ground rather than by multiplying degrees. Rounded to a tenth, which is
     * finer than any figure a handset prints and coarser than the noise between
     * two spheroid models.
     *
     * An area whose edge has not been imported measures nothing, and says so as
     * 0.0 rather than as a null the contract does not describe for this field.
     */
    private function areaKm2(AreaOfInterest $area): float
    {
        $id = $area->getId();

        return null === $id ? 0.0 : round($this->areas->stAreaKm2(['id' => $id]), 1);
    }

    /**
     * The edge as GeoJSON, already thinned, as an OBJECT: PostGIS hands it back
     * as text and in lon/lat, which is the order the contract wants.
     *
     * NULL FOR AN AREA WITH NO EDGE, and for one thinned away to nothing — an area
     * is created from its name and its boundary is imported now or later, so
     * "no geometry" is an ordinary state and not a fault.
     *
     * @return array<string, mixed>|null
     */
    private function boundary(AreaOfInterest $area): ?array
    {
        $id = $area->getId();
        if (null === $id || !$area->hasBoundary()) {
            return null;
        }

        $text = $this->areas->stSimplifiedBoundary($id, self::FIELD_SIMPLIFY_DEGREES);
        if (null === $text || '' === $text) {
            return null;
        }

        $decoded = json_decode($text, true);

        /** @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }
}
