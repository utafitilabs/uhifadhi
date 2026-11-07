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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

/**
 * THE POSITIONS PAGE, AS IT IS DRAWN — the people above the matrix, then one
 * row per position with what the filters read, built from
 * {@see PositionsReportReader::report()} and the concern catalogue and
 * nothing else.
 *
 * THE COUNTS ARE THE ROWS. Every pill and every concern counts the rows this
 * page writes, by the predicates the page's controller filters them with
 * (`assets/controllers/permission_positions_controller.js`), so what the
 * server writes and what a filter later shows agree. A retired position is a
 * row like any other, marked.
 *
 * @phpstan-import-type PositionRow from PositionsReportReader
 *
 * @phpstan-type Note array{alarm: bool, label: string, verb: string, by: ?string, at: ?\DateTimeImmutable, reason: ?string, text: string}
 * @phpstan-type Row array{
 *     uuid: string, name: string, find: string, holders: int, seatCount: ?int, where: string,
 *     flags: list<string>, concerns: list<string>,
 *     marks: list<array{text: string, warn: bool}>,
 *     summary: string,
 *     groups: list<array{group: string, concerns: list<array{label: string, verbs: string, pair: string, sensitive: ?string}>}>,
 *     notes: list<Note>,
 * }
 * @phpstan-type Above array{name: string, uuid: string, placed: string, tier: string, tierClass: string, f: string, q: string}
 * @phpstan-type Register array{
 *     id: string, noun: string, one: string, count: int, search: string, pages: int,
 *     chips: list<array{axis: string, value: string, on: bool, aria: string, sep: int, options: list<array{value: string, label: string, count: int, on: bool}>}>,
 * }
 * @phpstan-type Page array{
 *     above: list<Above>,
 *     aboveRegister: Register,
 *     tierSource: string,
 *     tierPairs: list<string>,
 *     source: string,
 *     listed: list<Row>,
 *     pills: list<array{key: string, label: string, count: int}>,
 *     concernMenu: list<array{label: ?string, options: list<array{value: string, label: string, count: int}>}>,
 *     sortMenu: list<array{label: string, options: list<array{value: string, label: string}>}>,
 *     shown: int,
 *     perPage: int,
 * }
 */
final class PositionsListing
{
    /** The toggle pills, in the design's order: key, label. */
    public const array ONLY = [
        'all' => 'All',
        'vacant' => 'Vacant',
        'exception' => 'With an exception',
        'locked' => 'Locked',
        'honoured' => 'Not honoured',
        'organization' => 'Organization-wide',
        'area' => 'In areas',
    ];

    /** The house tier chip's class for each tier. */
    private const array TIER_CLASS = ['super_admin' => 't-super', 'admin' => 't-admin'];

    /** The register foot's page size: the rows the browser shows at once. */
    public const int PER_PAGE = 20;

    /** The sort, in the design's order: value, label. */
    public const array SORT = ['holders' => 'Most holders first', 'name' => 'By name'];

    /**
     * @param array{
     *     positions: list<PositionRow>,
     *     tiers: list<array{key: string, label: string, people: list<array{id: int, uuid: string, name: string, placed: string}>}>,
     *     tierOnlyPairs: list<string>,
     *     groups: list<string>,
     * } $report
     * @param array<string, list<array{key: string, label: string}>> $catalogue every concern, under its declaring package, in order
     *
     * @return Page
     */
    public static function build(array $report, array $catalogue): array
    {
        $rows = array_map(self::row(...), self::sorted($report['positions']));

        $above = self::above($report['tiers']);
        $people = \count($above);

        $pills = [];
        foreach (self::ONLY as $key => $label) {
            $pills[] = ['key' => $key, 'label' => $label, 'count' => \count(array_filter($rows, static fn (array $r): bool => 'all' === $key || \in_array($key, $r['flags'], true)))];
        }

        $concernMenu = [['label' => null, 'options' => [['value' => '', 'label' => 'Any resource', 'count' => \count($rows)]]]];
        foreach ($catalogue as $group => $concerns) {
            $concernMenu[] = ['label' => $group, 'options' => array_map(static fn (array $c): array => [
                'value' => $c['key'],
                'label' => $c['label'],
                'count' => \count(array_filter($rows, static fn (array $r): bool => \in_array($c['key'], $r['concerns'], true))),
            ], $concerns)];
        }

        $sort = [];
        foreach (self::SORT as $value => $label) {
            $sort[] = ['value' => $value, 'label' => $label];
        }

        return [
            'above' => $above,
            'aboveRegister' => self::aboveRegister($report['tiers'], $people),
            'tierSource' => '· '.self::plural(\count($report['tiers']), 'tier', 'tiers').' · '.self::plural($people, 'person', 'people').' · not rows in the list',
            'tierPairs' => array_map(static fn (string $p): string => str_replace('.', ' · ', $p), $report['tierOnlyPairs']),
            'source' => '· '.\count($rows).' · read as written',
            'listed' => $rows,
            'pills' => $pills,
            'concernMenu' => $concernMenu,
            'sortMenu' => [['label' => 'Sort', 'options' => $sort]],
            'shown' => \count($rows),
            'perPage' => self::PER_PAGE,
        ];
    }

    /**
     * ONE ROW A PERSON ABOVE THE MATRIX, Super Admins first, then by name;
     * each carries its tier for the chip (data-pa-f) and its name for the
     * search (data-pa-q).
     *
     * @param list<array{key: string, label: string, people: list<array{id: int, uuid: string, name: string, placed: string}>}> $tiers
     *
     * @return list<Above>
     */
    private static function above(array $tiers): array
    {
        $rows = [];
        foreach ($tiers as $tier) {
            $people = $tier['people'];
            usort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            foreach ($people as $person) {
                $rows[] = [
                    'name' => $person['name'],
                    'uuid' => $person['uuid'],
                    'placed' => $person['placed'],
                    'tier' => $tier['label'],
                    'tierClass' => self::TIER_CLASS[$tier['key']] ?? 't-staff',
                    'f' => json_encode(['tier' => [$tier['key']]], \JSON_THROW_ON_ERROR),
                    'q' => mb_strtolower($person['name']),
                ];
            }
        }

        return $rows;
    }

    /**
     * THE BAR AND FOOT OF ABOVE THE MATRIX: the tier chip, counting its
     * people, and the search.
     *
     * @param list<array{key: string, label: string, people: list<array{id: int, uuid: string, name: string, placed: string}>}> $tiers
     *
     * @return Register
     */
    private static function aboveRegister(array $tiers, int $people): array
    {
        return [
            'id' => 'above',
            'noun' => 'people',
            'one' => 'person',
            'count' => $people,
            'search' => 'Find a person',
            'pages' => max(1, (int) ceil($people / self::PER_PAGE)),
            'chips' => [[
                'axis' => 'tier', 'value' => 'all · '.$people, 'on' => false, 'aria' => 'Filter by tier', 'sep' => 1,
                'options' => [
                    ['value' => '', 'label' => 'All', 'count' => $people, 'on' => true],
                    ...array_map(static fn (array $t): array => ['value' => $t['key'], 'label' => $t['label'], 'count' => \count($t['people']), 'on' => false], $tiers),
                ],
            ]],
        ];
    }

    /**
     * Most holders first, then by name, as the page's default sort.
     *
     * @param list<PositionRow> $positions
     *
     * @return list<PositionRow>
     */
    private static function sorted(array $positions): array
    {
        usort($positions, static fn (array $a, array $b): int => $b['holders'] <=> $a['holders'] ?: strcasecmp($a['name'], $b['name']));

        return $positions;
    }

    /**
     * @param PositionRow $p
     *
     * @return Row
     */
    private static function row(array $p): array
    {
        $labels = [];
        $groups = [];
        foreach ($p['groups'] as $group) {
            $concerns = [];
            foreach ($group['concerns'] as $c) {
                $labels[$c['key']] = $c['label'];
                $concerns[] = [
                    'label' => $c['label'],
                    'verbs' => implode(', ', $c['verbs']),
                    'pair' => $c['key'].'.'.($c['verbs'][0] ?? ''),
                    'sensitive' => $c['sensitive'] ? (null !== $c['lifts'] ? 'sensitive — lifts '.$c['lifts'] : 'sensitive') : null,
                ];
            }
            $groups[] = ['group' => $group['group'], 'concerns' => $concerns];
        }

        $flags = array_keys(array_filter([
            'vacant' => $p['marks']['vacant'],
            'exception' => [] !== $p['exceptions'],
            'locked' => $p['locked'],
            'honoured' => [] !== $p['notHonoured'],
            'organization' => \in_array('organization', $p['allowedKinds'], true),
            'area' => \in_array('area', $p['allowedKinds'], true),
        ]));

        $marks = [];
        if ($p['locked']) {
            $marks[] = ['text' => 'locked', 'warn' => false];
        }
        if ($p['retired']) {
            $marks[] = ['text' => 'retired', 'warn' => false];
        }
        if (null !== $p['vacantSince']) {
            $marks[] = ['text' => 'vacant since '.$p['vacantSince']->format('j M'), 'warn' => true];
        }
        if ($p['marks']['exceptionNotInForce']) {
            $marks[] = ['text' => 'exception not in force', 'warn' => true];
        }
        if ($p['marks']['notHonoured']) {
            $marks[] = ['text' => 'a permission not honoured', 'warn' => true];
        }

        $notes = [];
        foreach ($p['exceptions'] as $e) {
            [$concern, $verb] = self::split($e['pair']);
            $notes[] = $e['inForce']
                ? ['alarm' => false, 'label' => $labels[$concern] ?? $concern, 'verb' => $verb, 'by' => $e['by'], 'at' => $e['at'], 'reason' => $e['reason'], 'text' => 'exception in force']
                : ['alarm' => true, 'label' => $labels[$concern] ?? $concern, 'verb' => $verb, 'by' => null, 'at' => null, 'reason' => null, 'text' => 'held, NOT in force: no written reason, so the voter refuses it'];
        }
        foreach ($p['notHonoured'] as $pair) {
            [$concern, $verb] = self::split($pair);
            $notes[] = ['alarm' => true, 'label' => $labels[$concern] ?? $concern, 'verb' => $verb, 'by' => null, 'at' => null, 'reason' => null, 'text' => 'stored, not honoured: only the tiers hold it'];
        }

        $concernCount = \count($labels);

        return [
            'uuid' => $p['uuid'],
            'name' => $p['name'],
            'find' => mb_strtolower($p['name']),
            'holders' => $p['holders'],
            'seatCount' => $p['seatCount'],
            'where' => $p['where'],
            'flags' => $flags,
            'concerns' => array_keys($labels),
            'marks' => $marks,
            'summary' => 0 === $concernCount
                ? 'holds no permission'
                : self::plural(\count($p['pairs']), 'permission', 'permissions').' across '.self::plural($concernCount, 'resource', 'resources').': '.implode(', ', $labels),
            'groups' => $groups,
            'notes' => $notes,
        ];
    }

    /** @return array{string, string} */
    private static function split(string $pair): array
    {
        $at = strrpos($pair, '.');

        return false === $at ? [$pair, ''] : [substr($pair, 0, $at), substr($pair, $at + 1)];
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.(1 === $n ? $one : $many);
    }
}
