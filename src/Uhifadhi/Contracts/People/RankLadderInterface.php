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
 * WHERE A PERSON STANDS ON THE ORGANIZATION'S RANK LADDER — one number per
 * person, 1 for the most senior place. The ladder is every active rank of
 * every active scale, the scales in the order the Ranks page lists them and
 * the ranks of a scale from most senior to most junior, so two people can be
 * compared across scales with one comparison.
 *
 * A person with no rank is absent from the answer: having no place on the
 * ladder is itself the fact a caller acts on.
 *
 * Asked for a set of people at once, because the caller is a page or a
 * stream drawing many people, and one question per person would be one query
 * per mark.
 */
interface RankLadderInterface
{
    /**
     * @param list<string> $personUuids
     *
     * @return array<string, int> each ranked person's place, keyed by person uuid; 1 is the most senior
     */
    public function placesOf(array $personUuids): array;

    /** How many places the ladder has; 0 when the organization uses no ranks. */
    public function length(): int;
}
