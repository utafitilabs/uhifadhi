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

namespace Uhifadhi\Contracts\People;

/**
 * THE CONTRACT a module implements to put ONE DROPDOWN ON THE PEOPLE
 * REGISTER — a fact about people that the module owns and the register
 * does not.
 *
 * WHY IT EXISTS AT ALL. The People register filters by position, department,
 * station, rank and account — all facts whoever owns people can read. Whether
 * somebody is at their post today is the ground's fact, and the ground must
 * not be a dependency of the people: an installation may hold a team without
 * an area. So the register asks, through this seam, "what else may this list
 * be filtered by?", and every tagged provider answers with one dropdown.
 *
 * NOT {@see PersonFacetProviderInterface}. That seam answers ABOUT a person —
 * their position and department — for a list drawn somewhere else. This one
 * contributes A FACET TO the People register itself. The two point in
 * opposite directions and neither could stand in for the other.
 *
 * THE OPTIONS CARRY THE PEOPLE THEY APPLY TO, so the count under an option
 * and the rows the option leaves are ONE FACT: a dropdown whose "41" and
 * whose forty-one rows came from two derivations could disagree, and on a
 * register the count is the promise of what picking it does.
 *
 * ONE CALL FOR THE WHOLE ROSTER. The register counts every option over
 * everybody, never over the filtered set, so the request is the whole set
 * of identifiers and the answer is keyed by them.
 *
 * ANSWERING NOTHING IS LEGITIMATE. A provider that has nothing to say — no
 * ground to read a day from yet — returns null, and the register draws no
 * dropdown for it rather than an empty one.
 *
 * TAGGED EXPLICITLY AT BOTH ENDS, as every seam in this platform is: a
 * reusable bundle is not autoconfigured, so the implementor tags its own
 * service with {@see TAG}, and the register collects them with a tagged
 * iterator. An `#[AutoconfigureTag]` on this interface would be silently
 * dead — PHP does not inherit attributes from an interface, and the only
 * symptom would be a dropdown that quietly never appeared.
 */
interface PeopleFacetProviderInterface
{
    public const string TAG = 'uhifadhi.people_facets';

    /**
     * @param list<string> $userUuids everybody the register counts over
     *
     * @return PeopleFacet|null the dropdown, or null when this provider has nothing to filter by
     */
    public function facetFor(array $userUuids): ?PeopleFacet;
}
