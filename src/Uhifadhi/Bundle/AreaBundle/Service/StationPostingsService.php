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
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Model\PostingRow;
use Uhifadhi\Bundle\AreaBundle\Model\StationQuery;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;

/**
 * WHO IS POSTED WHERE IN ONE AREA — every standing posting, in the order of
 * its station and, inside a station, the lead first and then by name.
 *
 * The zone, the station and the position narrow the rows, the search finds a
 * person or a station by name; each dropdown's counts are over what the
 * other answers leave, so a count is what choosing it would show.
 */
final readonly class StationPostingsService
{
    public const string ZONE = 'zone';
    public const string STATION = 'station';
    public const string POSITION = 'position';
    public const string SEARCH = 'q';

    public function __construct(
        private StationRepository $stations,
        private PostingRepository $postings,
        private PostingBoardService $board,
    ) {
    }

    /**
     * @param array{zone: ?string, station: ?string, position: ?string, q: string} $ask
     *
     * @return array{
     *     rows: list<array{person: PostingRow, station: array{uuid: string, name: string, code: ?string, zone: ?string, posted: int}}>,
     *     total: int,
     *     scope: int,
     *     stations: int,
     *     zones: list<FilterOption>,
     *     stationOptions: list<FilterOption>,
     *     positions: list<FilterOption>,
     * }
     */
    public function postings(AreaOfInterest $area, array $ask): array
    {
        $stations = [];
        $zones = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $zone = $station->getZone();
            $zoneUuid = null === $zone ? StationQuery::UNZONED : (string) $zone->getUuidString();
            $stations[(string) $station->getUuidString()] = [
                'uuid' => (string) $station->getUuidString(),
                'name' => (string) $station->getName(),
                'code' => $station->getCode(),
                'zone' => $zone?->getName(),
                'zoneUuid' => $zoneUuid,
                'posted' => 0,
            ];
            $zones[$zoneUuid] = $zone?->getName() ?? 'No zone';
        }

        $all = [];
        foreach ($this->board->board($this->postings->findStandingByArea($area), new PostingQuery())['rows'] as $row) {
            if (isset($stations[$row->stationUuid])) {
                ++$stations[$row->stationUuid]['posted'];
                $all[] = $row;
            }
        }

        usort($all, static fn (PostingRow $a, PostingRow $b): int => strnatcasecmp($stations[$a->stationUuid]['name'], $stations[$b->stationUuid]['name'])
            ?: $b->leader <=> $a->leader
            ?: strnatcasecmp($a->name, $b->name));

        $answers = static function (PostingRow $row, ?string $skip) use ($ask, $stations): bool {
            $at = $stations[$row->stationUuid];
            $term = mb_strtolower($ask['q']);

            return (self::ZONE === $skip || null === $ask['zone'] || $at['zoneUuid'] === $ask['zone'])
                && (self::STATION === $skip || null === $ask['station'] || $row->stationUuid === $ask['station'])
                && (self::POSITION === $skip || null === $ask['position'] || $row->role === $ask['position'])
                && ('' === $term || str_contains(mb_strtolower($row->name), $term) || str_contains(mb_strtolower($at['name']), $term));
        };

        $rows = array_values(array_filter($all, static fn (PostingRow $row): bool => $answers($row, null)));

        /**
         * @param callable(PostingRow): ?string $key
         *
         * @return array<string, int>
         */
        $count = static function (string $axis, callable $key) use ($all, $answers): array {
            $n = [];
            foreach ($all as $row) {
                if ($answers($row, $axis)) {
                    $k = $key($row);
                    if (\is_string($k)) {
                        $n[$k] = ($n[$k] ?? 0) + 1;
                    }
                }
            }

            return $n;
        };

        $byZone = $count(self::ZONE, static fn (PostingRow $row): string => $stations[$row->stationUuid]['zoneUuid']);
        $byStation = $count(self::STATION, static fn (PostingRow $row): string => $row->stationUuid);
        $byPosition = $count(self::POSITION, static fn (PostingRow $row): ?string => $row->role);

        asort($zones, \SORT_NATURAL | \SORT_FLAG_CASE);
        $named = array_filter($stations, static fn (array $s): bool => $s['posted'] > 0);
        uasort($named, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $positions = array_values(array_unique(array_filter(array_map(static fn (PostingRow $row): ?string => $row->role, $all))));
        natcasesort($positions);

        return [
            'rows' => array_map(static fn (PostingRow $row): array => ['person' => $row, 'station' => [
                'uuid' => $stations[$row->stationUuid]['uuid'],
                'name' => $stations[$row->stationUuid]['name'],
                'code' => $stations[$row->stationUuid]['code'],
                'zone' => $stations[$row->stationUuid]['zone'],
                'posted' => $stations[$row->stationUuid]['posted'],
            ]], $rows),
            'total' => \count($rows),
            'scope' => \count($all),
            'stations' => \count($named),
            'zones' => array_map(static fn (string $uuid, string $name): FilterOption => new FilterOption($uuid, $name, $byZone[$uuid] ?? 0), array_keys($zones), $zones),
            'stationOptions' => array_values(array_map(static fn (array $s): FilterOption => new FilterOption($s['uuid'], $s['name'], $byStation[$s['uuid']] ?? 0), $named)),
            'positions' => array_values(array_map(static fn (string $p): FilterOption => new FilterOption($p, $p, $byPosition[$p] ?? 0), $positions)),
        ];
    }
}
