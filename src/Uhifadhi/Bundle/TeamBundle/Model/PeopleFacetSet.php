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

namespace Uhifadhi\Bundle\TeamBundle\Model;

/**
 * THE PEOPLE REGISTER'S SEAM FACETS, READ ONCE FOR ONE REQUEST — the
 * station dropdown, the dropdowns modules contributed, and who each option
 * leaves, so the bar's counts and the rows' filter come from one reading.
 */
final readonly class PeopleFacetSet
{
    /**
     * @param list<Facet>                                $contributed the modules' dropdowns, in the order the container yields them
     * @param array<string, array<string, list<string>>> $people      facet key → option value → the people carrying it
     */
    public function __construct(
        public Facet $station,
        public array $contributed,
        private array $people,
    ) {
    }

    /** @return list<string> the query keys the contributed dropdowns write */
    public function keys(): array
    {
        return array_map(static fn (Facet $facet): string => $facet->key, $this->contributed);
    }

    /**
     * THE QUERY WITH THE CHOSEN PEOPLE WRITTEN IN: each chosen seam facet
     * leaves its option's people, several choices intersect, and a value no
     * option carries leaves nobody — a stale link is a link to nobody, never
     * to everybody.
     */
    public function narrow(RosterQuery $query): RosterQuery
    {
        $chosen = $query->facets;
        if (null !== $query->station) {
            $chosen[RosterQuery::STATION] = $query->station;
        }
        if ([] === $chosen) {
            return $query;
        }

        $only = null;
        foreach ($chosen as $key => $value) {
            $leaves = $this->people[$key][$value] ?? [];
            $only = null === $only ? $leaves : array_values(array_intersect($only, $leaves));
        }

        return $query->narrowedTo($only);
    }
}
