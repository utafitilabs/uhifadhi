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

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Model\FilterOption;
use Uhifadhi\Bundle\TeamBundle\Model\PostingLine;
use Uhifadhi\Bundle\TeamBundle\Model\PostingQuery;
use Uhifadhi\Bundle\TeamBundle\Model\PostingRow;
use Uhifadhi\Bundle\TeamBundle\Model\PostingStation;
use Uhifadhi\Bundle\TeamBundle\Model\SectionFact;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Area\DirectoryArea;
use Uhifadhi\Contracts\Area\StationDirectoryInterface;

/**
 * WHO IS POSTED WHERE, ACROSS EVERY AREA — read by Team, written by nobody
 * here.
 *
 * TWO HALVES OF ONE ANSWER. Whoever owns the ground publishes the stations and
 * the uuids standing at each; this bundle owns the people, so it says who
 * those uuids are, what rank they hold and which department the rank belongs
 * to. Neither bundle names a class in the other and the join happens here, in
 * values.
 *
 * NO PROVIDER IS A REAL INSTALLATION, not an error. An installation with no
 * area package has no stations, and the board says so rather than failing —
 * which is also what makes the seam optional the way every other one is.
 *
 * THE FILTER COUNTS ARE OF THE WHOLE BOARD, never of what is already filtered:
 * a count that moved as you filtered could not tell you what picking it would
 * do.
 */
final readonly class PostingBoard
{
    /**
     * THE GROUND'S OWN ADDRESSES, named rather than typed into a template.
     * They are mounted by the application and generating one can fail; the
     * board is tolerant of that, which is the only reason it may name them.
     */
    private const string STATION_ROUTE = 'area_station_show';
    private const string STATIONS_ROUTE = 'area_stations';

    /**
     * @param iterable<StationDirectoryInterface> $directories
     */
    public function __construct(
        private iterable $directories,
        private UserRepository $users,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *     facts: list<SectionFact>,
     *     lines: list<PostingLine>,
     *     postings: int,
     *     areaOptions: list<FilterOption>,
     *     zoneOptions: list<FilterOption>,
     *     rankOptions: list<FilterOption>,
     *     postedOptions: list<FilterOption>,
     *     stationsUrl: string|null,
     * }
     */
    public function read(PostingQuery $query): array
    {
        $stations = $this->board();
        $shown = $this->matching($stations, $query);

        return [
            'facts' => $this->facts($stations),
            'lines' => self::lines($shown),
            'postings' => self::countPostings($shown),
            'areaOptions' => $this->areaOptions($stations),
            'zoneOptions' => self::zoneOptions($stations),
            'rankOptions' => self::rankOptions($stations),
            'postedOptions' => self::postedOptions($stations),
            'stationsUrl' => $this->stationsUrl($stations),
        ];
    }

    /**
     * THE WHOLE BOARD, UNFILTERED — every station on the installation with the
     * people standing at it, their rank and their department.
     *
     * ONE READ OF THE PEOPLE FOR THE WHOLE BOARD. The uuids come back from the
     * ground in one call and the accounts behind them in a second; asking per
     * post would be a query a row, and on the one page that draws every post
     * in the installation that is the whole installation in queries.
     *
     * @return list<PostingStation>
     */
    public function board(): array
    {
        $posted = [];
        $uuids = [];
        foreach ($this->directories as $directory) {
            foreach ($directory->stations() as $station) {
                $posted[] = $station;
                foreach ($station->posts as $post) {
                    $uuids[$post->personUuid] = true;
                }
            }
        }

        $people = [];
        foreach ($this->users->findByUuids(array_keys($uuids)) as $person) {
            $people[(string) $person->getUuidString()] = $person;
        }

        $board = [];
        foreach ($posted as $station) {
            $rows = [];
            foreach ($station->posts as $post) {
                $person = $people[$post->personUuid] ?? null;

                /*
                 * A POST POINTING AT NOBODY IS NOT A ROW. The account was
                 * deleted outright, which the model does not do — it
                 * deactivates — so this is the case that cannot happen; drawing
                 * a blank name if it ever did would be worse than the absence.
                 */
                if (!$person instanceof User) {
                    continue;
                }

                $rows[] = new PostingRow(
                    personUuid: $post->personUuid,
                    name: $person->getFullName(),
                    rank: $person->getPosition()?->getName(),
                    department: $person->getDepartmentLabel(),
                    leader: $post->leader,
                );
            }

            $board[] = new PostingStation(
                uuid: $station->uuid,
                name: $station->name,
                code: $station->code,
                areaUuid: $station->areaUuid,
                areaName: $station->areaName,
                zoneName: $station->zoneName,
                rows: $rows,
                url: $this->url(self::STATION_ROUTE, ['uuid' => $station->areaUuid, 'station' => $station->uuid]),
            );
        }

        return $board;
    }

    /**
     * THE FIVE FACTS THE BAND OPENS WITH, and every one of them is of the whole
     * installation rather than of the filtered view: an identity band that
     * changed as you filtered would be a band about the filter.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<SectionFact>
     */
    private function facts(array $stations): array
    {
        $departments = $areasPosted = [];
        $leaders = $empty = 0;
        foreach ($stations as $station) {
            if ($station->isEmpty()) {
                ++$empty;
                continue;
            }

            $areasPosted[$station->areaName] = true;
            foreach ($station->rows as $row) {
                if ($row->leader) {
                    ++$leaders;
                }
                if (null !== $row->department) {
                    $departments[$row->department] = true;
                }
            }
        }
        ksort($departments);
        ksort($areasPosted);

        $allAreas = \count($this->areaDirectory());

        return [
            new SectionFact('Assignments', (string) self::countPostings($stations), \sprintf('%d stations', \count($stations))),
            new SectionFact(
                'Areas',
                (string) \count($areasPosted),
                \sprintf('of %d%s', $allAreas, [] === $areasPosted ? '' : ' · '.implode(', ', array_keys($areasPosted))),
            ),
            new SectionFact('Stations', (string) \count($stations), \sprintf('%d with nobody', $empty)),
            new SectionFact('Leaders', (string) $leaders, 'one per staffed station'),
            new SectionFact(
                'Departments',
                (string) \count($departments),
                [] === $departments ? 'none stationed' : implode(', ', array_keys($departments)),
            ),
        ];
    }

    /**
     * THE BOARD AS THE READER ASKED FOR IT.
     *
     * A STATION FILTER AND A PERSON FILTER ARE NOT THE SAME CUT. Area, zone and
     * the staffed/empty answer are facts about the STATION, so they keep or
     * drop all its rows; rank and the search cut the PEOPLE at it, and a
     * station left with nobody by them is dropped rather than drawn empty — it
     * would read as "nobody is posted here", which is a different fact.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<PostingStation>
     */
    private function matching(array $stations, PostingQuery $query): array
    {
        $term = mb_strtolower($query->search);
        $shown = [];

        foreach ($stations as $station) {
            if (null !== $query->area && $query->area !== $station->areaUuid) {
                continue;
            }
            if (null !== $query->zone && $query->zone !== ($station->zoneName ?? PostingQuery::NO_ZONE)) {
                continue;
            }
            if (PostingQuery::STAFFED === $query->posted && $station->isEmpty()) {
                continue;
            }
            if (PostingQuery::EMPTY === $query->posted && !$station->isEmpty()) {
                continue;
            }

            $stationMatches = '' === $term || str_contains(mb_strtolower($station->name), $term);
            $rows = array_values(array_filter(
                $station->rows,
                static fn (PostingRow $row): bool => (null === $query->rank || $query->rank === $row->rank)
                    && ($stationMatches || str_contains(mb_strtolower($row->name), $term)),
            ));

            if ([] === $rows && !$station->isEmpty()) {
                continue;
            }
            if ($station->isEmpty() && !$stationMatches) {
                continue;
            }
            if ($station->isEmpty() && null !== $query->rank) {
                continue;
            }

            $shown[] = new PostingStation(
                uuid: $station->uuid,
                name: $station->name,
                code: $station->code,
                areaUuid: $station->areaUuid,
                areaName: $station->areaName,
                zoneName: $station->zoneName,
                // A STATION'S ROWS SORT BY THE HEADER the reader asked for;
                // the stations themselves keep the ground's order.
                rows: $query->order($rows),
                url: $station->url,
            );
        }

        return $shown;
    }

    /**
     * EVERY AREA IS AN OPTION, including the ones with no station: that an
     * installation has three areas nobody has built out yet is exactly what
     * the reader is looking at the board to learn.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<FilterOption>
     */
    private function areaOptions(array $stations): array
    {
        $counts = [];
        foreach ($stations as $station) {
            $counts[$station->areaUuid] = ($counts[$station->areaUuid] ?? 0) + \count($station->rows);
        }

        $options = [];
        foreach ($this->areaDirectory() as $area) {
            $options[] = new FilterOption($area->uuid, $area->name, $counts[$area->uuid] ?? 0);
        }

        return $options;
    }

    /** @return list<DirectoryArea> */
    private function areaDirectory(): array
    {
        $areas = [];
        foreach ($this->directories as $directory) {
            foreach ($directory->areas() as $area) {
                $areas[] = $area;
            }
        }

        return $areas;
    }

    /**
     * A POST ON GROUND THAT BELONGS TO NO ZONE IS ITS OWN ANSWER, offered last
     * — zones are presence-driven, so "no zone" is a place a station can be.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<FilterOption>
     */
    private static function zoneOptions(array $stations): array
    {
        $counts = [];
        $unzoned = 0;
        foreach ($stations as $station) {
            if (null === $station->zoneName) {
                $unzoned += \count($station->rows);
                continue;
            }
            $counts[$station->zoneName] = ($counts[$station->zoneName] ?? 0) + \count($station->rows);
        }
        ksort($counts);

        $options = [];
        foreach ($counts as $zone => $count) {
            $options[] = new FilterOption((string) $zone, (string) $zone, $count);
        }
        $options[] = new FilterOption(PostingQuery::NO_ZONE, 'No zone', $unzoned);

        return $options;
    }

    /**
     * RANK IS THE POSITION the person holds, counted over the board rather
     * than over the roster: a position nobody posted appears on no station.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<FilterOption>
     */
    private static function rankOptions(array $stations): array
    {
        $counts = [];
        foreach ($stations as $station) {
            foreach ($station->rows as $row) {
                if (null !== $row->rank) {
                    $counts[$row->rank] = ($counts[$row->rank] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        $options = [];
        foreach ($counts as $rank => $count) {
            $options[] = new FilterOption((string) $rank, (string) $rank, $count);
        }

        return $options;
    }

    /**
     * THE TWO ANSWERS COUNT STATIONS AND NOT PEOPLE, because that is what the
     * question is about — "with people posted · 10" is ten stations, and ten
     * people would be a different and wrong number.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<FilterOption>
     */
    private static function postedOptions(array $stations): array
    {
        $empty = \count(array_filter($stations, static fn (PostingStation $s): bool => $s->isEmpty()));

        return [
            new FilterOption(PostingQuery::STAFFED, 'With people stationed', \count($stations) - $empty),
            new FilterOption(PostingQuery::EMPTY, 'Nobody posted', $empty),
        ];
    }

    /**
     * THE DOOR TO WHERE A POSTING IS MADE — the station register of the area
     * this board is about.
     *
     * ONE AREA OR NONE. The control names "the area", and with stations in two
     * of them there is no single area it could mean; an installation with
     * several needs a door per area, which is a drawn decision and not one to
     * invent here. Until it is drawn, the control is absent rather than wrong.
     *
     * @param list<PostingStation> $stations
     */
    private function stationsUrl(array $stations): ?string
    {
        $areas = [];
        foreach ($stations as $station) {
            $areas[$station->areaUuid] = true;
        }

        if (1 !== \count($areas)) {
            return null;
        }

        return $this->url(self::STATIONS_ROUTE, ['uuid' => array_key_first($areas)]);
    }

    /** @param array<string, string> $parameters */
    private function url(string $route, array $parameters): ?string
    {
        try {
            return $this->urls->generate($route, $parameters);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * THE BOARD AS THE REGISTER'S ROWS: every posting, in the ground's order
     * of stations and the sort's order within each, and a station nobody
     * stands at as one row of its own.
     *
     * @param list<PostingStation> $stations
     *
     * @return list<PostingLine>
     */
    private static function lines(array $stations): array
    {
        $lines = [];
        foreach ($stations as $station) {
            if ($station->isEmpty()) {
                $lines[] = new PostingLine($station);
            }
            foreach ($station->rows as $row) {
                $lines[] = new PostingLine($station, $row);
            }
        }

        return $lines;
    }

    /** @param list<PostingStation> $stations */
    private static function countPostings(array $stations): int
    {
        $postings = 0;
        foreach ($stations as $station) {
            $postings += \count($station->rows);
        }

        return $postings;
    }
}
