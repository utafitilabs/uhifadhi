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

use Uhifadhi\Contracts\Access\PowerGroup;

/**
 * THE ANALYSIS PAGE, AS IT IS DRAWN — one question and its answer, the lists
 * for review and the drift, built from what {@see PermissionAnalysisReader}
 * and {@see PermissionFindingsReader} return and nothing else.
 *
 * THE QUESTION CARD IS THE ONLY ONE THE QUESTION REACHES. The lists and the
 * drift are the run's, whatever is asked above them, and each is one card
 * and one row of the index.
 *
 * @phpstan-type Questions array{
 *     asOf: ?\DateTimeImmutable,
 *     concerns: list<array{key: string, label: string, group: string, verbs: list<array{pair: string, verb: string, label: string, answers: int, positions: int}>}>,
 *     powers: list<array{key: string, label: string, group: string, groupLabel: string, answers: int, allowed: int, target: string}>,
 * }
 * @phpstan-type Place array{key: string, kind: string, id: int|null, name: string}
 * @phpstan-type PairAnswer array{
 *     pair: string, label: string, filed: bool, asOf: ?\DateTimeImmutable, places: list<Place>,
 *     rows: list<array{positionId: int, position: string, holders: int, counts: array<string, int>, anywhere: int, people: int}>,
 *     totals: array<string, int>, tiers: list<array{key: string, label: string, count: int}>,
 *     departments: list<array{id: int, name: string}>,
 * }
 * @phpstan-type PowerAnswer array{
 *     power: array{key: string, label: string, group: string, groupLabel: string},
 *     targets: list<array{key: string, label: string}>,
 *     rows: list<array{kind: string, name: string, positionId: int|null, tier: string|null, cells: array<string, string>, refusedBy: array<string, string|null>}>,
 *     alarm: bool, asOf: ?\DateTimeImmutable,
 * }
 * @phpstan-type Cells array<string, array<int, list<array{id: int, uuid: string, name: string, departments: list<int>}>>>
 * @phpstan-type Standing array{asOf: ?\DateTimeImmutable, lists: list<array{id: string, label: string, alarm: bool, count: int, entries: list<array<string, mixed>>}>}
 * @phpstan-type Drift array{
 *     unreviewed: int,
 *     lines: list<array{
 *         subject: string, positionId: int|null, questionKind: string, question: string, label: string,
 *         places: list<array{driftId: int, place: string, was: int, now: int, reviewed: bool}>,
 *         found: ?\DateTimeImmutable, previousRun: ?\DateTimeImmutable,
 *     }>,
 * }
 * @phpstan-type Option array{value: string, label: string, count: int, title: string}
 * @phpstan-type ChipOption array{value: string, label: string, count: int|null, on: bool}
 * @phpstan-type Chip array{axis: string, value: string, on: bool, aria: string, options: list<ChipOption>, sep: int}
 * @phpstan-type Register array{id: string, noun: string, one: string, count: int, chips: list<Chip>, search: string, pages: int}
 * @phpstan-type PairView array{
 *     tiers: list<array{label: string, count: int}>,
 *     heads: list<array{key: string, head: string, organization: bool}>,
 *     rows: list<array{position: string, people: int, changed: bool, cells: list<array{count: int, cell: string, label: string, changed: bool}>}>,
 *     totals: list<string>,
 *     total: string,
 *     none: ?string,
 *     changed: int,
 *     register: Register,
 *     people: array<string, array{where: string, names: list<array{name: string, url: ?string, id: int, departments: string}>}>,
 * }
 * @phpstan-type Doors array{positions?: array<int, string>, people?: array<int, string>, tiers?: array<string, int>}
 * @phpstan-type PowerView array{
 *     lead: array{group: string, question: string},
 *     expect: bool, alarm: bool, targets: list<string>,
 *     rows: list<array{name: string, kind: string, cells: list<bool>, url: ?string, f: string, q: string}>,
 *     register: Register,
 * }
 * @phpstan-type DriftView array{
 *     lines: int, changed: int, done: bool, heads: list<string>,
 *     between: array{\DateTimeImmutable, \DateTimeImmutable}|null,
 *     rows: list<array{subject: string, label: string, cells: list<array{was: int, now: int}|null>, other: list<array{place: string, was: int, now: int}>, ids: list<int>, reviewed: bool, door: ?string, f: string, q: string}>,
 *     register: Register,
 * }
 * @phpstan-type Page array{
 *     question: array{key: string, kind: string, title: string, default: string, label: string},
 *     menu: list<array{label: string, options: list<Option>}>,
 *     nav: list<array{id: string, label: string, count: int, title: ?string}>,
 *     pair: PairView|null,
 *     power: PowerView|null,
 *     lists: list<array{id: string, title: string, count: int, bad: bool, entries: list<array<string, mixed>>, register: Register}>,
 *     drift: DriftView,
 * }
 */
final class AnalysisListing
{
    /** The index's words for each list, in the design's order. */
    public const array ROWS = [
        'reach-nothing' => 'Reach nothing',
        'not-in-force' => 'Not in force',
        'not-honoured' => 'Not honoured',
        'gaining' => 'Gaining power',
        'vacant' => 'Vacant positions',
        'exceptions' => 'Exceptions in force',
        'self-changes' => 'Self-changes',
    ];

    /** The power groups in the dropdown, in the design's order. */
    private const array POWER_ORDER = [PowerGroup::GainingPower, PowerGroup::GivingPower, PowerGroup::TakingOver, PowerGroup::OthersRecords];

    private const string ORGANIZATION = 'The whole organization';

    /** Each register's noun for one row. */
    private const array ONE = ['people' => 'person', 'permissions' => 'permission', 'answers' => 'answer', 'positions' => 'position', 'exceptions' => 'exception', 'changes' => 'change', 'actors' => 'actor'];

    /** The register foot's page, before a reader chooses another. */
    public const int PAGE = 20;

    /**
     * EACH LIST'S BAR: its noun, then its chips — the axis, the word its
     * chip wears while nothing is chosen (null: "all · n"), the All option.
     *
     * @var array<string, array{string, list<array{string, string|null, string}>}>
     */
    private const array BARS = [
        'reach-nothing' => ['people', [['reason', null, 'All'], ['position', 'position', 'All positions']]],
        'not-in-force' => ['permissions', [['position', 'position', 'All positions'], ['permission', 'permission', 'All permissions']]],
        'not-honoured' => ['permissions', [['position', 'position', 'All positions'], ['permission', 'permission', 'All permissions']]],
        'gaining' => ['answers', [['kind', null, 'All']]],
        'vacant' => ['positions', [['since', null, 'All']]],
        'exceptions' => ['exceptions', [['position', 'position', 'All positions'], ['permission', 'permission', 'All permissions'], ['by', 'by', 'Anybody']]],
        'self-changes' => ['changes', [['what', null, 'All']]],
    ];

    /**
     * THE QUESTION THE ADDRESS ASKS: a pair or a power by its key, a concern
     * by its first verb, and the first pair otherwise.
     *
     * @param Questions $questions
     */
    public static function resolve(array $questions, ?string $asked): string
    {
        $first = null;
        foreach ($questions['concerns'] as $concern) {
            foreach ($concern['verbs'] as $verb) {
                $first ??= $verb['pair'];
                if ($asked === $verb['pair']) {
                    return $asked;
                }
            }
            if ($asked === $concern['key'] && [] !== $concern['verbs']) {
                return $concern['verbs'][0]['pair'];
            }
        }
        foreach ($questions['powers'] as $power) {
            if ($asked === $power['key']) {
                return $asked;
            }
        }

        return $first ?? '';
    }

    /**
     * @param Questions        $questions
     * @param PairAnswer|null  $pair      the answer when the question is a pair
     * @param Cells            $cells     the people behind each of its cells
     * @param PowerAnswer|null $power     the answer when the question is a power
     * @param list<Place>      $places    the latest run's places
     * @param Standing         $standing
     * @param Drift            $drift
     * @param string|null      $check     the Check page, null while it is not mounted
     * @param array<int, int>  $holders   position id to one active holder, the person a drift line's or a power row's check asks about
     * @param Doors            $doors     the records a row opens: a position's and a person's by id, and one person of each tier for a power row's check
     *
     * @return Page
     */
    public static function build(array $questions, string $key, ?array $pair, array $cells, ?array $power, array $places, array $standing, array $drift, ?string $check, array $holders = [], array $doors = []): array
    {
        $pairView = null === $pair ? null : self::pair($pair, $cells, $check, $drift);
        $powerView = null === $power ? null : self::power($power, $check, $holders, $doors['tiers'] ?? []);

        if (null !== $power) {
            $count = \count(array_filter($power['rows'], static fn (array $r): bool => \in_array('allowed', $r['cells'], true)));
            $title = $count.(1 === $count ? ' actor holds it' : ' actors hold it').' over at least one target';
        } else {
            $ids = [];
            foreach ($cells as $byPosition) {
                foreach ($byPosition as $people) {
                    foreach ($people as $person) {
                        $ids[$person['id']] = true;
                    }
                }
            }
            $count = \count($ids);
            $title = $count.(1 === $count ? ' person' : ' people').' below the tiers can, somewhere';
        }

        $lists = array_map(static fn (array $l): array => [
            'id' => $l['id'],
            'title' => $l['label'],
            'count' => $l['count'],
            'bad' => $l['alarm'] && $l['count'] > 0,
            'entries' => $entries = self::entries($l['id'], $l['entries'], $questions, $check, $doors),
            'register' => self::listRegister($l['id'], $entries),
        ], $standing['lists']);
        foreach ($lists as $i => $list) {
            $lists[$i]['entries'] = self::faceted($list['id'], $list['entries']);
        }

        $driftView = self::drift($drift, $places, $check, $holders);

        $nav = [['id' => 'question', 'label' => 'The question', 'count' => $count, 'title' => $title]];
        foreach ($lists as $list) {
            $nav[] = ['id' => $list['id'], 'label' => self::ROWS[$list['id']] ?? $list['title'], 'count' => $list['count'], 'title' => null];
        }
        $nav[] = ['id' => 'drift', 'label' => 'Drift', 'count' => $driftView['lines'], 'title' => null];

        return [
            'question' => [
                'key' => $key,
                'kind' => null === $power ? 'pair' : 'power',
                'title' => null === $power ? self::pairTitle($questions, $key) : 'Who holds '.$power['power']['label'].', over whom',
                'default' => self::resolve($questions, null),
                'label' => self::chosen($questions, $key),
            ],
            'menu' => self::menu($questions),
            'nav' => $nav,
            'pair' => $pairView,
            'power' => $powerView,
            'lists' => $lists,
            'drift' => $driftView,
        ];
    }

    /**
     * @param Questions $questions
     *
     * @return list<array{label: string, options: list<Option>}>
     */
    private static function menu(array $questions): array
    {
        $groups = [];
        foreach ($questions['concerns'] as $concern) {
            foreach ($concern['verbs'] as $verb) {
                $n = $verb['positions'];
                $groups[$concern['group']][] = [
                    'value' => $verb['pair'],
                    'label' => $concern['label'].' · '.$verb['verb'],
                    'count' => $n,
                    'title' => $n.(1 === $n ? ' position holds it' : ' positions hold it'),
                ];
            }
        }

        $menu = [];
        foreach ($groups as $group => $options) {
            $menu[] = ['label' => $group, 'options' => $options];
        }
        $powers = [];
        foreach (self::POWER_ORDER as $group) {
            foreach ($questions['powers'] as $power) {
                if ($group->value === $power['group']) {
                    $n = $power['allowed'];
                    $powers[] = [
                        'value' => $power['key'],
                        'label' => $power['label'],
                        'count' => $n,
                        'title' => $n.(1 === $n ? ' actor holds it' : ' actors hold it').' over '.$power['target'],
                    ];
                }
            }
        }
        if ([] !== $powers) {
            $menu[] = ['label' => 'Powers', 'options' => $powers];
        }

        return $menu;
    }

    /** @param Questions $questions */
    private static function chosen(array $questions, string $key): string
    {
        foreach (self::menu($questions) as $group) {
            foreach ($group['options'] as $option) {
                if ($key === $option['value']) {
                    return $option['label'];
                }
            }
        }

        return $key;
    }

    /** @param Questions $questions */
    private static function pairTitle(array $questions, string $pair): string
    {
        foreach ($questions['concerns'] as $concern) {
            foreach ($concern['verbs'] as $verb) {
                if ($pair === $verb['pair']) {
                    return 'Who can '.$verb['verb'].' '.mb_strtolower($concern['label']).', and where';
                }
            }
        }

        return 'Who can '.$pair.', and where';
    }

    /**
     * @param PairAnswer $pair
     * @param Cells      $cells
     * @param Drift      $drift
     *
     * @return PairView
     */
    private static function pair(array $pair, array $cells, ?string $check, array $drift): array
    {
        $heads = array_map(static fn (array $p): array => [
            'key' => $p['key'],
            'head' => self::head($p),
            'organization' => PlaceKey::ORGANIZATION === $p['key'],
        ], $pair['places']);

        $keyOf = array_column($pair['places'], 'key', 'name');
        $changed = [];
        $changedCount = 0;
        $answers = $pair['rows'];
        $known = array_column($answers, 'positionId');
        foreach ($drift['lines'] as $line) {
            if ('pair' !== $line['questionKind'] || $pair['pair'] !== $line['question'] || null === $line['positionId']) {
                continue;
            }
            foreach ($line['places'] as $p) {
                if ($p['was'] === $p['now']) {
                    continue;
                }
                ++$changedCount;
                if (isset($keyOf[$p['place']])) {
                    $changed[$line['positionId'].'|'.$keyOf[$p['place']]] = true;
                }
            }
            if (!\in_array($line['positionId'], $known, true)) {
                $known[] = $line['positionId'];
                $answers[] = ['positionId' => $line['positionId'], 'position' => $line['subject'], 'holders' => 0, 'counts' => [], 'anywhere' => 0, 'people' => 0];
            }
        }

        $rows = [];
        $people = [];
        foreach ($answers as $row) {
            $line = [];
            $marked = false;
            foreach ($pair['places'] as $place) {
                $n = $row['counts'][$place['key']] ?? 0;
                $cell = $row['positionId'].'|'.$place['key'];
                $isChanged = isset($changed[$cell]);
                $marked = $marked || $isChanged;
                $line[] = ['count' => $n, 'cell' => $cell, 'label' => $n.' '.$row['position'].' · '.$place['name'].($isChanged ? ' · changed since the last run' : ''), 'changed' => $isChanged];
                if ($n <= 0) {
                    continue;
                }
                $people[$cell] = [
                    'where' => $row['position'].' · '.$place['name'],
                    'names' => array_map(static fn (array $p): array => [
                        'name' => $p['name'],
                        'url' => self::check($check, ['person' => $p['id'], 'q' => $pair['pair'], 'target' => $place['key']]),
                        'id' => $p['id'],
                        'departments' => implode(' ', $p['departments']),
                    ], $cells[$place['key']][$row['positionId']] ?? []),
                ];
            }
            $rows[] = ['position' => $row['position'], 'people' => $row['people'], 'changed' => $marked, 'cells' => $line];
        }

        $none = null;
        if ([] === $rows) {
            $none = null === $pair['asOf'] ? '' : 'No position’s holders pass anywhere; only the tiers can.';
        }

        return [
            'tiers' => array_map(static fn (array $t): array => ['label' => $t['label'], 'count' => $t['count']], $pair['tiers']),
            'heads' => $heads,
            'rows' => $rows,
            'totals' => array_map(static fn (array $p): string => ($pair['totals'][$p['key']] ?? 0) > 0 ? (string) $pair['totals'][$p['key']] : '', $pair['places']),
            'total' => \count($rows) > self::PAGE ? 'Total · all '.\count($rows) : 'Total',
            'none' => $none,
            'changed' => $changedCount,
            'register' => self::gridRegister($rows, $people, $pair['departments']),
            'people' => $people,
        ];
    }

    /**
     * THE QUESTION'S THREE CHIPS as they open: every row, any department,
     * most people first. The page recounts them as they are chosen.
     *
     * @param list<array{position: string, people: int, changed: bool, cells: list<array{count: int, cell: string, label: string, changed: bool}>}> $rows
     * @param array<string, array{where: string, names: list<array{name: string, url: ?string, id: int, departments: string}>}>                     $people
     * @param list<array{id: int, name: string}>                                                                                                    $departments
     *
     * @return Register
     */
    private static function gridRegister(array $rows, array $people, array $departments): array
    {
        $with = \count(array_filter($rows, static fn (array $r): bool => $r['people'] > 0));
        $changed = \count(array_filter($rows, static fn (array $r): bool => $r['changed']));
        $all = \count($rows);

        $serving = [];
        $anybody = [];
        foreach ($people as $cell) {
            foreach ($cell['names'] as $person) {
                $anybody[$person['id']] = true;
                foreach ('' === $person['departments'] ? [] : explode(' ', $person['departments']) as $department) {
                    $serving[(int) $department][$person['id']] = true;
                }
            }
        }

        $option = static fn (string $value, string $label, ?int $count, bool $on): array => ['value' => $value, 'label' => $label, 'count' => $count, 'on' => $on];

        return [
            'id' => 'question',
            'noun' => 'positions',
            'one' => 'position',
            'count' => $all,
            'chips' => [
                ['axis' => 'rows', 'value' => 'all positions · '.$all, 'on' => false, 'aria' => 'Rows', 'sep' => 1, 'options' => [
                    $option('all', 'All positions', $all, true),
                    $option('with-people', 'With people', $with, false),
                    $option('empty', 'Empty', $all - $with, false),
                    $option('changed', 'Changed since last run', $changed, false),
                ]],
                ['axis' => 'department', 'value' => 'department', 'on' => false, 'aria' => 'Filter by department', 'sep' => 1, 'options' => [
                    $option('', 'Any department', \count($anybody), true),
                    ...array_map(static fn (array $d): array => $option((string) $d['id'], $d['name'], \count($serving[$d['id']] ?? []), false), $departments),
                ]],
                ['axis' => 'sort', 'value' => 'most people first', 'on' => false, 'aria' => 'Sort', 'sep' => 0, 'options' => [
                    $option('people', 'Most people first', null, true),
                    $option('name', 'By name', null, false),
                ]],
            ],
            'search' => '',
            'pages' => self::pages($all),
        ];
    }

    /**
     * A BAR OVER ROWS THAT EACH CARRY THEIR VALUES ON EVERY AXIS: an All
     * option over every row, then each value in the order it first appears,
     * counted.
     *
     * @param list<array<string, list<string>>>          $facets one row's values per axis
     * @param list<array{string, string|null, string}>   $axes   the axis, the word while nothing is chosen (null: "all · n"), the All option
     * @param array<string, list<array{string, string}>> $fixed  an axis whose options are fixed: value and label
     *
     * @return Register
     */
    private static function register(string $id, string $noun, array $facets, array $axes, array $fixed = []): array
    {
        $all = \count($facets);
        $chips = [];
        foreach ($axes as [$axis, $word, $allLabel]) {
            $counts = [];
            foreach ($facets as $row) {
                foreach (array_unique($row[$axis] ?? []) as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            $values = isset($fixed[$axis]) ? $fixed[$axis] : array_map(static fn (int|string $v): array => [(string) $v, (string) $v], array_keys($counts));
            $chips[] = [
                'axis' => $axis,
                'value' => $word ?? 'all · '.$all,
                'on' => false,
                'aria' => 'Filter by '.$axis,
                'sep' => 1,
                'options' => [
                    ['value' => '', 'label' => $allLabel, 'count' => $all, 'on' => true],
                    ...array_map(static fn (array $v): array => ['value' => $v[0], 'label' => $v[1], 'count' => $counts[$v[0]] ?? 0, 'on' => false], $values),
                ],
            ];
        }

        return ['id' => $id, 'noun' => $noun, 'one' => self::ONE[$noun] ?? $noun, 'count' => $all, 'chips' => $chips, 'search' => 'Search the '.$noun, 'pages' => self::pages($all)];
    }

    private static function pages(int $rows): int
    {
        return max(1, (int) ceil($rows / self::PAGE));
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return Register
     */
    private static function listRegister(string $id, array $entries): array
    {
        [$noun, $axes] = self::BARS[$id] ?? ['entries', []];

        return self::register($id, $noun, array_map(static fn (array $e): array => self::facets($id, $e), $entries), $axes);
    }

    /**
     * ONE ENTRY'S VALUES ON ITS LIST'S AXES.
     *
     * @param array<string, mixed> $e
     *
     * @return array<string, list<string>>
     */
    private static function facets(string $id, array $e): array
    {
        $s = static fn (string $k): string => \is_scalar($e[$k] ?? null) ? (string) $e[$k] : '';

        return match ($id) {
            'reach-nothing' => ['reason' => [$s('why')], 'position' => [$s('position')]],
            'not-in-force', 'not-honoured' => ['position' => [$s('position')], 'permission' => [$s('pair')]],
            'gaining' => ['kind' => [$s('power')]],
            'vacant' => ['since' => [($e['since'] ?? null) instanceof \DateTimeInterface ? 'since '.$e['since']->format('M Y') : 'no date']],
            'exceptions' => ['position' => [$s('position')], 'permission' => [$s('pair')], 'by' => [$s('by')]],
            'self-changes' => ['what' => [$s('what')]],
            default => [],
        };
    }

    /**
     * Each entry with its values as the page reads them (`f`, JSON) and the
     * words a search matches (`q`).
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private static function faceted(string $id, array $entries): array
    {
        return array_map(static fn (array $e): array => [
            ...$e,
            'f' => json_encode(self::facets($id, $e), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'q' => mb_strtolower(implode(' ', array_filter($e, static fn (mixed $v, string $k): bool => \is_string($v) && 'url' !== $k && 'door' !== $k && 'kind' !== $k, \ARRAY_FILTER_USE_BOTH))),
        ], $entries);
    }

    /**
     * THE POWER'S TABLE, one row an actor, the tiers first: each name says
     * whether it is a tier or a position, and opens the live check of one
     * person it stands for.
     *
     * @param PowerAnswer        $power
     * @param array<int, int>    $holders position id to one active holder
     * @param array<string, int> $tiers   tier to one active person of it
     *
     * @return PowerView
     */
    private static function power(array $power, ?string $check, array $holders, array $tiers): array
    {
        $group = PowerGroup::tryFrom($power['power']['group']);
        $target = $power['targets'][0]['key'] ?? null;
        $rows = [];
        $facets = [];
        foreach ($power['rows'] as $row) {
            $cells = array_map(static fn (array $t): bool => 'allowed' === ($row['cells'][$t['key']] ?? null), $power['targets']);
            $facets[] = $f = ['verdict' => [\in_array(true, $cells, true) ? 'allowed' : 'refused']];
            $rows[] = [
                'name' => $row['name'],
                'kind' => 'tier' === $row['kind'] ? 'tier' : 'position',
                'cells' => $cells,
                'url' => self::check($check, [
                    'person' => 'tier' === $row['kind'] ? $tiers[(string) $row['tier']] ?? null : (null === $row['positionId'] ? null : $holders[$row['positionId']] ?? null),
                    'q' => $power['power']['key'],
                    'target' => $target,
                ]),
                'f' => json_encode($f, \JSON_THROW_ON_ERROR),
                'q' => mb_strtolower($row['name']),
            ];
        }

        return [
            'lead' => ['group' => $power['power']['groupLabel'], 'question' => $group?->question() ?? ''],
            'expect' => PowerGroup::GainingPower === $group,
            'alarm' => $power['alarm'],
            'targets' => array_column($power['targets'], 'label'),
            'rows' => $rows,
            'register' => self::register('power', 'actors', $facets, [['verdict', null, 'All']], ['verdict' => [['allowed', 'Allowed somewhere'], ['refused', 'Refused everywhere']]]),
        ];
    }

    /**
     * EACH LIST'S ROWS, in the columns its card draws.
     *
     * @param list<array<string, mixed>> $entries
     * @param Questions                  $questions
     * @param Doors                      $doors
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(string $id, array $entries, array $questions, ?string $check, array $doors): array
    {
        $powers = array_column($questions['powers'], 'label', 'key');
        $position = static fn (array $e): ?string => \is_int($e['positionId'] ?? null) ? $doors['positions'][$e['positionId']] ?? null : null;
        $person = static fn (array $e): ?string => \is_int($e['personId'] ?? null) ? $doors['people'][$e['personId']] ?? null : null;

        return array_map(static fn (array $e): array => match ($id) {
            'reach-nothing' => [
                'name' => $e['name'] ?? '',
                'position' => $e['position'] ?? 'no position',
                'why' => $e['why'] ?? '',
                'url' => self::check($check, ['person' => \is_int($e['personId'] ?? null) ? $e['personId'] : null]),
            ],
            'not-in-force', 'not-honoured' => ['position' => $e['position'] ?? '', 'pair' => $e['pairLabel'] ?? '', 'door' => $position($e)],
            'gaining' => [
                'who' => $e['subject'] ?? '',
                'kind' => \is_int($e['positionId'] ?? null) ? 'position' : 'tier',
                'power' => (\is_string($e['power'] ?? null) ? $powers[$e['power']] ?? null : null) ?? ($e['detail'] ?? ''),
                'door' => $position($e),
            ],
            'vacant' => ['position' => $e['position'] ?? '', 'since' => $e['since'] ?? null, 'door' => $position($e)],
            'exceptions' => ['position' => $e['position'] ?? '', 'pair' => $e['pairLabel'] ?? '', 'by' => $e['by'] ?? '', 'at' => $e['at'] ?? null, 'reason' => $e['reason'] ?? '', 'door' => $position($e)],
            'self-changes' => ['who' => $e['name'] ?? '', 'what' => $e['what'] ?? '', 'before' => $e['before'] ?? null, 'after' => $e['after'] ?? null, 'at' => $e['at'] ?? null, 'door' => $person($e)],
            default => $e,
        }, $entries);
    }

    /**
     * @param Drift           $drift
     * @param list<Place>     $places
     * @param array<int, int> $holders
     *
     * @return DriftView
     */
    private static function drift(array $drift, array $places, ?string $check, array $holders): array
    {
        $names = array_column($places, 'name');
        $keyOf = array_column($places, 'key', 'name');
        $facets = [];
        $rows = [];
        $changed = 0;
        $between = null;
        $same = true;
        foreach ($drift['lines'] as $i => $line) {
            $moved = [];
            $other = [];
            $ids = [];
            foreach ($line['places'] as $p) {
                ++$changed;
                if (!$p['reviewed']) {
                    $ids[] = $p['driftId'];
                }
                if (\in_array($p['place'], $names, true)) {
                    $moved[$p['place']] = ['was' => $p['was'], 'now' => $p['now']];
                } else {
                    $other[] = ['place' => $p['place'], 'was' => $p['was'], 'now' => $p['now']];
                }
            }
            $at = array_key_first($moved);
            $facets[] = $f = [
                'place' => array_values(array_unique(array_column($line['places'], 'place'))),
                'position' => [$line['subject']],
                'review' => [[] === $ids ? 'reviewed' : 'unreviewed'],
            ];
            $rows[] = [
                'subject' => $line['subject'],
                'label' => $line['label'],
                'cells' => array_map(static fn (string $name): ?array => $moved[$name] ?? null, $names),
                'other' => $other,
                'ids' => $ids,
                'reviewed' => [] === $ids,
                'door' => self::check($check, [
                    'person' => null === $line['positionId'] ? null : $holders[$line['positionId']] ?? null,
                    'q' => $line['question'],
                    'target' => null === $at ? null : $keyOf[$at] ?? null,
                ]),
                'f' => json_encode($f, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'q' => mb_strtolower($line['subject'].' '.$line['label']),
            ];

            $first = $drift['lines'][0];
            if (0 === $i) {
                $between = null === $line['previousRun'] || null === $line['found'] ? null : [$line['previousRun'], $line['found']];
            } elseif ($line['previousRun'] != $first['previousRun'] || $line['found'] != $first['found']) {
                $same = false;
            }
        }

        return [
            'lines' => \count($rows),
            'changed' => $changed,
            'done' => 0 === $drift['unreviewed'],
            'heads' => array_map(self::head(...), $places),
            'between' => $same ? $between : null,
            'rows' => $rows,
            'register' => self::register('drift', 'answers', $facets, [['place', 'place', 'All places'], ['position', 'position', 'All positions'], ['review', null, 'All']], ['review' => [['unreviewed', 'Unreviewed'], ['reviewed', 'Reviewed']]]),
        ];
    }

    /** @param Place $place */
    private static function head(array $place): string
    {
        return PlaceKey::ORGANIZATION === $place['key'] ? self::ORGANIZATION : $place['name'];
    }

    /** @param array<string, int|string|null> $query */
    private static function check(?string $check, array $query): ?string
    {
        if (null === $check || null === ($query['person'] ?? null)) {
            return null;
        }

        return $check.'?'.http_build_query(array_filter($query, static fn (int|string|null $v): bool => null !== $v));
    }
}
