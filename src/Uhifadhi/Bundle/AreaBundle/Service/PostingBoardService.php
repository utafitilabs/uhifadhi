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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Model\PostingFacets;
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Model\PostingRow;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\People\PersonFacet;

/**
 * THE PEOPLE POSTED SOMEWHERE, AS A BOARD: the lines, what the filters offer,
 * and how many each would leave.
 *
 * THE FACETS ARE COUNTED AGAINST THE WHOLE BOARD and the lines are what
 * survives the filter — two different questions of the same set, which is why
 * both come out of one pass here rather than out of two queries that could
 * disagree about what "the board" is.
 *
 * FILTERED IN PHP, NOT IN SQL, and that is a size judgement rather than a
 * shortcut: a post has five people, an area has thirty, and the role and the
 * department are not columns of this bundle at all — they arrive from a seam,
 * keyed by uuid, so a WHERE clause could not see them. The day a board is
 * thousands of rows this is the thing to revisit, and the query object is
 * already the shape that would move into SQL.
 *
 * THE SEAM MAY ANSWER FOR NOBODY. An installation with no provider gets rows
 * with no role and no department, empty facets, and a board that draws its
 * lines without those two columns — which {@see PostingFacets::knowsRoles()}
 * is how the template asks.
 */
final readonly class PostingBoardService
{
    public function __construct(
        private PostingRepository $postings,
        private PersonFacetService $people,
    ) {
    }

    /**
     * @return array{rows: list<PostingRow>, facets: PostingFacets, total: int, leader: ?PostingRow}
     */
    public function at(Station $station, PostingQuery $query): array
    {
        return $this->board($this->postings->findStandingByStation($station), $query);
    }

    /**
     * @param list<Posting> $postings
     *
     * @return array{rows: list<PostingRow>, facets: PostingFacets, total: int, leader: ?PostingRow}
     */
    public function board(array $postings, PostingQuery $query): array
    {
        $facts = $this->people->facetsFor(array_values(array_filter(array_map(
            static fn (Posting $p): ?string => $p->getPerson()?->getUuidString(),
            $postings,
        ))));

        $all = [];
        foreach ($postings as $posting) {
            $row = self::describe($posting, $facts);
            if (null !== $row) {
                $all[] = $row;
            }
        }

        $rows = array_values(array_filter($all, static fn (PostingRow $row): bool => self::answers($row, $query)));
        usort($rows, static fn (PostingRow $a, PostingRow $b): int => self::order($a, $b, $query->sort));

        $leader = null;
        foreach ($all as $row) {
            if ($row->leader) {
                $leader = $row;
                break;
            }
        }

        return ['rows' => $rows, 'facets' => self::facetsOf($all), 'total' => \count($all), 'leader' => $leader];
    }

    /** @param array<string, PersonFacet> $facts */
    private static function describe(Posting $posting, array $facts): ?PostingRow
    {
        $person = $posting->getPerson();
        $uuid = $person?->getUuidString();
        $since = $posting->getSince();
        $source = $posting->getSource();

        if (null === $person || null === $uuid || null === $since || null === $source) {
            return null;
        }

        $facet = $facts[$uuid] ?? null;

        $station = $posting->getStation()?->getUuidString();
        if (null === $station) {
            return null;
        }

        return new PostingRow(
            postingUuid: (string) $posting->getUuidString(),
            personUuid: $uuid,
            stationUuid: (string) $station,
            name: $person->getFullName(),
            initials: self::initialsOf($person->getFullName()),
            role: $facet?->position,
            department: $facet?->department,
            source: $source,
            since: $since,
            leader: $posting->isLeader(),
        );
    }

    /**
     * AT MOST TWO LETTERS, from the first and last word of whatever the
     * contract calls them. An avatar is a shape with a hint in it, not an
     * abbreviation scheme; three letters in that circle is a smudge.
     */
    private static function initialsOf(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if ([] === $words) {
            return '?';
        }

        $first = mb_substr($words[0], 0, 1);

        return 1 === \count($words) ? mb_strtoupper($first) : mb_strtoupper($first.mb_substr(end($words), 0, 1));
    }

    private static function answers(PostingRow $row, PostingQuery $query): bool
    {
        if (null !== $query->role && $row->role !== $query->role) {
            return false;
        }

        if (null !== $query->department && $row->department !== $query->department) {
            return false;
        }

        if (null !== $query->source && $row->source->value !== $query->source) {
            return false;
        }

        // THE SEARCH READS THE NAME AND NOTHING ELSE, because that is what
        // somebody standing at a board is looking for. Case-insensitive, and
        // anywhere in the name: people are found by their surname as often as
        // by the initial the list is sorted on.
        return '' === $query->search
            || str_contains(mb_strtolower($row->name), mb_strtolower($query->search));
    }

    private static function order(PostingRow $a, PostingRow $b, string $sort): int
    {
        // THE LEAD IS FIRST WHATEVER THE ORDER. The board is read to find who
        // is in charge as often as to find a name, and the design puts them
        // at the top in both.
        if ($a->leader !== $b->leader) {
            return $a->leader ? -1 : 1;
        }

        return match ($sort) {
            PostingQuery::BY_SINCE => $a->since <=> $b->since,
            PostingQuery::BY_NEWEST => $b->since <=> $a->since,
            PostingQuery::BY_ROLE => strcasecmp($a->role ?? "\u{10FFFF}", $b->role ?? "\u{10FFFF}") ?: strcasecmp($a->name, $b->name),
            default => strcasecmp($a->name, $b->name),
        };
    }

    /** @param list<PostingRow> $rows */
    private static function facetsOf(array $rows): PostingFacets
    {
        $roles = [];
        $departments = [];
        $sources = [];

        foreach ($rows as $row) {
            if (null !== $row->role) {
                $roles[$row->role] = ($roles[$row->role] ?? 0) + 1;
            }
            if (null !== $row->department) {
                $departments[$row->department] = ($departments[$row->department] ?? 0) + 1;
            }
            $sources[$row->source->value] = ($sources[$row->source->value] ?? 0) + 1;
        }

        return new PostingFacets(self::ranked($roles), self::ranked($departments), self::ranked($sources));
    }

    /**
     * MOST FIRST, THEN ALPHABETICALLY. A board of thirty is read by finding
     * the big groups, and a tie broken by the name keeps the list stable
     * between two renders of the same data.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function ranked(array $counts): array
    {
        uksort($counts, static fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcasecmp($a, $b));

        return $counts;
    }
}
