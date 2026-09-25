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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\TeamBundle\Model\Facet;
use Uhifadhi\Bundle\TeamBundle\Model\FacetGroup;
use Uhifadhi\Bundle\TeamBundle\Model\FilterOption;
use Uhifadhi\Bundle\TeamBundle\Model\PeopleFacetSet;
use Uhifadhi\Bundle\TeamBundle\Model\RosterQuery;
use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetProviderInterface;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;

/**
 * THE PEOPLE REGISTER'S DROPDOWNS THAT COME THROUGH A SEAM — the station,
 * read from whoever owns the ground, and whatever a module contributes.
 *
 * THE STATION FACET LISTS WHERE PEOPLE STAND, not every station there is:
 * this bundle holds no ground, so the stations it can name are the ones
 * somebody is posted to. Grouped by area once postings span several, in
 * name order, and "Not stationed" last for everybody with no standing post.
 *
 * A CONTRIBUTED FACET IS DRAWN AS THE HOUSE DROPDOWN, one Model\Facet per
 * provider, opening to the left as the bar's right-hand controls do. Its
 * key becomes a query parameter, so a key this register already owns is
 * refused loudly: two facets writing one parameter would each read the
 * other's choice.
 *
 * READ ONCE PER REQUEST. Both seams answer for the whole roster in one
 * call, and the answer carries the people behind every option, so the
 * counts in the bar and the rows the choice leaves are one reading.
 */
final readonly class PeopleFacetService
{
    /** The query keys the register writes itself; a contributed facet may not take one. */
    public const array RESERVED = ['q', 'tier', 'position', 'state', 'department', 'rank', 'page', RosterQuery::STATION];

    /**
     * @param iterable<PersonPostingProviderInterface> $postings
     * @param iterable<PeopleFacetProviderInterface>   $providers
     */
    public function __construct(
        private iterable $postings,
        private iterable $providers,
    ) {
    }

    /** @param list<string> $userUuids everybody on the register — the counts are the whole set's */
    public function read(array $userUuids): PeopleFacetSet
    {
        $people = [];
        $station = $this->station($userUuids, $people);

        $contributed = [];
        foreach ($this->providers as $provider) {
            $facet = $provider->facetFor($userUuids);
            if (null === $facet) {
                continue;
            }
            if (\in_array($facet->key, self::RESERVED, true) || isset($people[$facet->key])) {
                throw new \LogicException(\sprintf('A contributed people facet may not write "%s": the People register already owns that query parameter.', $facet->key));
            }
            $contributed[] = self::dropdown($facet, \count($userUuids), $people);
        }

        return new PeopleFacetSet($station, $contributed, $people);
    }

    /**
     * @param list<string>                               $userUuids
     * @param array<string, array<string, list<string>>> $people
     */
    private function station(array $userUuids, array &$people): Facet
    {
        /** @var array<string, array{name: string, stations: array<string, array{name: string, people: list<string>}>}> $areas */
        $areas = [];
        $stationed = [];
        foreach ($this->postings as $provider) {
            foreach ($provider->postingsFor($userUuids) as $uuid => $postings) {
                foreach ($postings as $posting) {
                    $areas[$posting->areaUuid]['name'] = $posting->areaName;
                    $areas[$posting->areaUuid]['stations'][$posting->stationUuid]['name'] = $posting->stationName;
                    $areas[$posting->areaUuid]['stations'][$posting->stationUuid]['people'][] = $uuid;
                    $stationed[$uuid] = true;
                }
            }
        }

        uasort($areas, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $several = \count($areas) > 1;

        $groups = [];
        foreach ($areas as $area) {
            $stations = $area['stations'];
            uasort($stations, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            $options = [];
            foreach ($stations as $stationUuid => $station) {
                $carrying = array_values(array_unique($station['people']));
                $people[RosterQuery::STATION][$stationUuid] = $carrying;
                $options[] = new FilterOption($stationUuid, $station['name'], \count($carrying));
            }
            $groups[] = new FacetGroup($several ? $area['name'] : null, $options);
        }

        $nowhere = array_values(array_filter($userUuids, static fn (string $uuid): bool => !isset($stationed[$uuid])));
        $people[RosterQuery::STATION][RosterQuery::NO_STATION] = $nowhere;

        return new Facet(RosterQuery::STATION, 'station', 'any station', \count($userUuids), $groups, new FilterOption(RosterQuery::NO_STATION, 'Not stationed', \count($nowhere)));
    }

    /** @param array<string, array<string, list<string>>> $people */
    private static function dropdown(PeopleFacet $facet, int $total, array &$people): Facet
    {
        $groups = [];
        foreach ($facet->groups as $group) {
            $options = [];
            foreach ($group->options as $option) {
                $people[$facet->key][$option->value] = $option->userUuids;
                $options[] = new FilterOption($option->value, $option->label, $option->count());
            }
            $groups[] = new FacetGroup($group->head, $options);
        }

        return new Facet($facet->key, $facet->label, 'any '.$facet->label, $total, $groups, opensLeft: true);
    }
}
