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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\FilterOption;
use Uhifadhi\Bundle\AreaBundle\Model\StationQuery;
use Uhifadhi\Bundle\AreaBundle\Model\StationRegister;
use Uhifadhi\Bundle\AreaBundle\Model\StationRow;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneRow;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;

/**
 * THE AREA'S POSTS AS ONE FLAT, FILTERED, PAGED LIST.
 *
 * FILTERED IN PHP, AND THAT IS A SIZE JUDGEMENT rather than a shortcut: an
 * area has tens of posts, the page draws every facet count against the same
 * set, and one walk over one list is both cheaper and impossible to make
 * disagree with itself. The query object is already the shape that would
 * move into SQL the day an area has thousands.
 *
 * EVERY PANEL COUNTS AGAINST THE OTHER FILTERS, not against its own. Picking
 * "Lone Hills" should tell you how many posts Lone Hills has *given* that you are
 * looking at the active ones — so each facet is counted with its own filter
 * lifted and the rest standing, which is what makes the number a prediction
 * of what picking it would do.
 *
 * THE HUES ARE THE ZONE SET'S OWN. The dot beside a zone in the filter is the
 * colour that zone is drawn in on every plate in the product, because both
 * come from the same walk over the same ordered set.
 */
final readonly class StationRegisterService
{
    public function __construct(
        private StationRepository $stations,
        private PostingRepository $postings,
        private ZoneSetService $set,
    ) {
    }

    /**
     * @param string|null $holding a row the caller must be able to see — the
     *                             card a deep link named. Where the filters
     *                             and the order put it on another page, THAT
     *                             page is the one returned: a link into a
     *                             register that answered with a page not
     *                             containing the thing it named is a link
     *                             that silently does nothing, which is what
     *                             happened to every station from the ninth
     *                             on. A row the filters exclude is not found
     *                             and the asked-for page stands — the filters
     *                             are part of the address too.
     */
    public function register(AreaOfInterest $area, StationQuery $query, int $perPage = StationRegister::PER_PAGE, ?string $holding = null): StationRegister
    {
        $cats = [];
        $zoneNames = [];
        foreach ($this->set->view($area)->rows as $zone) {
            /** @var ZoneRow $zone */
            $cats[$zone->uuid] = $zone->cat;
            $zoneNames[$zone->uuid] = $zone->name;
        }

        $counts = $this->postings->countStandingPerStation($area);
        $led = $this->postings->ledStationUuids($area);

        $all = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $uuid = (string) $station->getUuidString();
            $zone = $station->getZone();
            $zoneUuid = null === $zone ? null : (string) $zone->getUuidString();

            $all[] = StationRow::of(
                $station,
                $counts[$uuid] ?? 0,
                isset($led[$uuid]),
                null === $zoneUuid ? null : ($cats[$zoneUuid] ?? null),
            );
        }

        $matching = array_values(array_filter($all, static fn (StationRow $row): bool => self::answers($row, $query, null)));
        usort($matching, static fn (StationRow $a, StationRow $b): int => self::order($a, $b, $query->sort));

        $pages = max(1, (int) ceil(\count($matching) / $perPage));
        $page = max(1, min($query->page, $pages));

        /*
         * THE PAGE A NAMED ROW IS ACTUALLY ON — decided here, where the
         * filtering and the ordering have just happened and the answer is
         * therefore free. A caller computing it would be a second place that
         * had to know the sort, the filters and the page size, and would be
         * wrong the day any of the three changed.
         */
        if (null !== $holding) {
            $at = self::indexOf($matching, $holding);
            if (null !== $at) {
                $page = intdiv($at, $perPage) + 1;
            }
        }

        return new StationRegister(
            rows: \array_slice($matching, ($page - 1) * $perPage, $perPage),
            total: \count($matching),
            // THE SCOPE IS THE ACTIVE FILTER'S, so "3 of 12" means three of the
            // twelve the register is currently about.
            scope: \count(array_filter($all, static fn (StationRow $row): bool => self::withinScope($row, $query))),
            inactive: \count(array_filter($all, static fn (StationRow $row): bool => !$row->active)),
            zones: self::zoneOptions($all, $query, $cats, $zoneNames),
            activity: self::activityOptions($all, $query),
            posted: self::postedOptions($all, $query),
            lead: self::leadOptions($all, $query),
            page: $page,
            pages: $pages,
            perPage: $perPage,
        );
    }

    /**
     * Where a row sits in the filtered, ordered set, or null when it is not
     * in it at all.
     *
     * @param list<StationRow> $matching
     */
    private static function indexOf(array $matching, string $uuid): ?int
    {
        foreach ($matching as $at => $row) {
            if ($row->uuid === $uuid) {
                return $at;
            }
        }

        return null;
    }

    /**
     * THE SCOPE EVERY COUNT IS "OF" — the activity filter alone. "3 of 12
     * stations" while the register shows the active ones means three of the
     * twelve active ones; measuring it against the whole table would make the
     * larger number mean something the sentence does not say.
     */
    private static function withinScope(StationRow $row, StationQuery $query): bool
    {
        return null === $query->active || (StationQuery::YES === $query->active) === $row->active;
    }

    /**
     * WHETHER A ROW SURVIVES THE QUESTION, optionally with one filter lifted
     * so that a panel can count what picking one of its options would leave.
     */
    private static function answers(StationRow $row, StationQuery $query, ?string $lifted = null): bool
    {
        if (StationQuery::ACTIVE !== $lifted && !self::withinScope($row, $query)) {
            return false;
        }

        if (StationQuery::ZONE !== $lifted && null !== $query->zone && $row->zoneValue() !== $query->zone) {
            return false;
        }

        if (StationQuery::POSTED !== $lifted && null !== $query->posted
            && (StationQuery::YES === $query->posted) !== ($row->posted > 0)) {
            return false;
        }

        if (StationQuery::LEAD !== $lifted && null !== $query->lead
            && (StationQuery::YES === $query->lead) !== $row->led) {
            return false;
        }

        return $row->matches($query->search);
    }

    /**
     * EVERY ZONE OF THE AREA IS OFFERED, even one with no post in it: a filter
     * that only listed the zones that happen to be staffed could not be used
     * to find out that a zone is empty.
     *
     * @param list<StationRow>     $all
     * @param array<string, int>   $cats
     * @param array<string,string> $names
     *
     * @return list<FilterOption>
     */
    private static function zoneOptions(array $all, StationQuery $query, array $cats, array $names): array
    {
        $counts = [];
        foreach ($all as $row) {
            if (self::answers($row, $query, StationQuery::ZONE)) {
                $counts[$row->zoneValue()] = ($counts[$row->zoneValue()] ?? 0) + 1;
            }
        }

        $options = [];
        foreach ($names as $uuid => $name) {
            $options[] = new FilterOption($uuid, $name, $counts[$uuid] ?? 0, $cats[$uuid] ?? null);
        }

        // UNZONED IS LAST, because it is the ground left over rather than one
        // more zone, and a reader scanning names should not trip over it.
        $options[] = new FilterOption(StationQuery::UNZONED, 'Unzoned', $counts[StationQuery::UNZONED] ?? 0);

        return $options;
    }

    /**
     * @param list<StationRow> $all
     *
     * @return list<FilterOption>
     */
    private static function activityOptions(array $all, StationQuery $query): array
    {
        $active = 0;
        $inactive = 0;
        foreach ($all as $row) {
            if (!self::answers($row, $query, StationQuery::ACTIVE)) {
                continue;
            }

            $row->active ? ++$active : ++$inactive;
        }

        return [
            new FilterOption(StationQuery::YES, 'Active', $active),
            new FilterOption(StationQuery::NO, 'Inactive', $inactive),
        ];
    }

    /**
     * @param list<StationRow> $all
     *
     * @return list<FilterOption>
     */
    private static function postedOptions(array $all, StationQuery $query): array
    {
        $staffed = 0;
        $empty = 0;
        foreach ($all as $row) {
            if (!self::answers($row, $query, StationQuery::POSTED)) {
                continue;
            }

            $row->posted > 0 ? ++$staffed : ++$empty;
        }

        return [
            new FilterOption(StationQuery::YES, 'Somebody posted', $staffed),
            new FilterOption(StationQuery::NO, 'Nobody posted', $empty),
        ];
    }

    /**
     * @param list<StationRow> $all
     *
     * @return list<FilterOption>
     */
    private static function leadOptions(array $all, StationQuery $query): array
    {
        $led = 0;
        $unled = 0;
        foreach ($all as $row) {
            if (!self::answers($row, $query, StationQuery::LEAD)) {
                continue;
            }

            $row->led ? ++$led : ++$unled;
        }

        return [
            new FilterOption(StationQuery::YES, 'Lead appointed', $led),
            new FilterOption(StationQuery::NO, 'No lead', $unled),
        ];
    }

    /** A tie is broken by the name, so two renders of one set read the same. */
    private static function order(StationRow $a, StationRow $b, string $sort): int
    {
        return match ($sort) {
            StationQuery::BY_POSTED => ($b->posted <=> $a->posted) ?: strcasecmp($a->name, $b->name),
            // UNZONED GROUPS LAST: it is the ground left over, not a zone
            // whose name happens to sort first.
            StationQuery::BY_ZONE => ((null === $a->zoneName) <=> (null === $b->zoneName))
                ?: (strcasecmp((string) $a->zoneName, (string) $b->zoneName) ?: strcasecmp($a->name, $b->name)),
            default => strcasecmp($a->name, $b->name),
        };
    }
}
