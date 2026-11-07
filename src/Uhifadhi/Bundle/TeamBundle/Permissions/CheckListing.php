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
 * TEAM › PERMISSIONS › CHECK, as the page draws it
 * (team-module/team/permissions/check.html): the bar's three chips, the
 * question in words, the walk with its doors, the coverage line, the lens's
 * table with its two axes, Worth running with its axis and sort, the index.
 * What the browser filters, sorts and pages it is handed whole, with the
 * counts the axes open on.
 *
 * @phpstan-import-type Questions from AnalysisListing
 * @phpstan-import-type Lens from PermissionCheckService
 * @phpstan-import-type Line from PermissionCheckService
 * @phpstan-import-type WorthRow from PermissionFindingsReader
 *
 * @phpstan-type Option array{value: int|string, label: string, count: int|string|null}
 * @phpstan-type Group array{label: string|null, options: list<Option>}
 * @phpstan-type Field array{default: string, options: list<Option>}
 * @phpstan-type Form array{
 *     people: list<array{tier: string, label: string, people: list<array{id: int, name: string, position: string|null}>}>,
 *     questions: Questions,
 *     places: list<array{key: string, name: string}>,
 *     targets: array<string, list<array{key: string, label: string}>>,
 *     person: int|null,
 *     q: string|null,
 *     target: string,
 * }
 * @phpstan-type WalkLine array{q: string, status: string, text: string, door: array<string, mixed>|null, url: string|null}
 * @phpstan-type Answer array{
 *     person: array{id: int, name: string},
 *     q: string,
 *     target: string,
 *     place: array{key: string, name: string},
 *     check: array{allowed: bool, lines: list<WalkLine>, coverage: array{asOf: ?\DateTimeImmutable, total: int, byPosition: list<array{position: string, count: int}>}|null},
 *     lens: Lens,
 *     aboutPerson: array<string, mixed>,
 *     aboutQuestion: array<string, mixed>,
 *     tiers: list<string>,
 *     actors: int,
 *     placementUrl?: string,
 * }
 * @phpstan-type Worth array{rows: list<WorthRow>, asOf: ?\DateTimeImmutable}
 * @phpstan-type Pill array{key: string, label: string, count: int}
 * @phpstan-type FormView array{
 *     people: list<array{label: string, options: list<array{value: int, label: string, count: string, find: string}>}>,
 *     questions: list<Group>,
 *     target: Field,
 *     targets: array<string, Field>,
 *     values: array{person: int|null, q: string|null, target: string},
 *     chosen: array{person: string|null, q: string|null, target: string|null},
 *     ready: bool,
 * }
 * @phpstan-type AnswerView array{
 *     allowed: bool,
 *     person: string,
 *     words: string,
 *     walk: list<array{label: string, status: string, text: string, door: array{url: string, text: string}|null}>,
 *     further: bool,
 *     coverage: array{kind: 'power', held: int, actors: int, target: string, asOf: ?\DateTimeImmutable}|array{kind: 'pair', place: string, count: int, top: list<array{position: string, count: int}>, tiers: string, asOf: ?\DateTimeImmutable}|null,
 *     asked: array{person: string, words: string, question: string, place: string, allowed: bool, href: string},
 *     aboutPerson: array<string, mixed>,
 *     aboutQuestion: array<string, mixed>,
 *     questionTitle: string,
 *     lens: Lens,
 *     placementUrl: string|null,
 *     pairWords: array<string, string>,
 * }
 * @phpstan-type EveryView array{
 *     rows: list<array{pair: string, words: string, concern: string, asked: bool, allowed: bool, step: string, line: string, group: string, href: string, find: string}>,
 *     pills: list<Pill>,
 *     concerns: list<array{head: string, options: list<array{key: string, label: string, count: int}>}>,
 *     allowed: int,
 *     total: int,
 * }
 * @phpstan-type WorthView array{
 *     rows: list<array{person: string, position: string, question: string, place: string, why: string, kind: string, chip: string, flagged: bool, href: string, find: string}>,
 *     count: int,
 *     flagged: int,
 *     pills: list<Pill>,
 *     sorts: list<array{key: string, label: string}>,
 *     asOf: ?\DateTimeImmutable,
 * }
 * @phpstan-type Page array{
 *     form: FormView,
 *     answer: AnswerView|null,
 *     everything: EveryView|null,
 *     worth: WorthView|null,
 *     nav: list<array{id: string, label: string, count: int|null, title: null}>,
 *     page: int,
 *     sorts: list<array{key: string, label: string}>,
 * }
 */
final class CheckListing
{
    /** The page size the two registers open on. */
    public const int PAGE = 20;

    private const array STEPS = [
        'tier' => 'Tier', 'tier-only' => 'Tier-only', 'position' => 'Position', 'exception' => 'Exception',
        'placement' => 'Placement', 'place' => 'Place', 'department' => 'Department', 'rule' => 'Rule',
    ];

    private const array DOORS = [
        'person' => 'open the person', 'position' => 'open the position', 'placement' => 'open the placement',
        'exception' => 'open the exception', 'department' => 'open the department',
    ];

    /** Everything here's verdict axis: the three always, then a group only where a row lands in it. */
    private const array EVERY_PILLS = [
        'all' => 'All', 'allowed' => 'Allowed', 'refused' => 'Refused',
        'exception' => 'By Exception', 'sensitive' => 'Sensitive', 'placement' => 'By Placement', 'place' => 'By Place',
        'department' => 'By Department', 'tier-only' => 'For the tiers', 'not-held' => 'Not held',
    ];

    /** Worth running's Why axis: All, then a kind only where a row has it. */
    private const array WORTH_PILLS = [
        'all' => 'All', 'reaches-nothing' => 'Reaches nothing', 'not-in-force' => 'Exception not in force',
        'stored-not-honoured' => 'Stored, not honoured', 'gains-power' => 'Gains power', 'in-force' => 'Exception in force',
        'nothing-flagged' => 'Nothing flagged',
    ];

    private const array WORTH_CHIPS = ['attention' => 'warn', 'confirmation' => 'ok', 'none' => 'pp-quiet'];

    private const array SORTS = ['flagged' => 'Flagged first', 'name' => 'By name', 'place' => 'By place'];

    private const array POWER_ORDER = [PowerGroup::GainingPower, PowerGroup::GivingPower, PowerGroup::TakingOver, PowerGroup::OthersRecords];

    public const string PLACED = 'placed';

    /**
     * @param Form        $form
     * @param Answer|null $answer the check asked, null before one
     * @param Worth       $worth
     * @param string      $check  the page's own address
     *
     * @return Page
     */
    public static function build(array $form, ?array $answer, array $worth, string $check): array
    {
        $labels = self::pairLabels($form['questions']);
        $isPower = null !== $form['q'] && isset($form['targets'][$form['q']]);

        $targets = ['' => self::placeField($form['places'])];
        foreach ($form['targets'] as $key => $kinds) {
            $targets[$key] = self::targetField($kinds);
        }

        $everything = null;
        if (null !== $answer && !$answer['lens']['tierHoldsAll']) {
            $everything = self::everything($answer, $labels, $form['questions'], $check);
        }
        $positions = [];
        foreach ($form['people'] as $tier) {
            foreach ($tier['people'] as $p) {
                $positions[$p['id']] = $p['position'] ?? 'no position';
            }
        }
        $worthView = self::worth($worth, $labels, $positions, $check);

        $nav = [];
        foreach (['check' => 'Check', 'everything' => 'Everything here', 'worth' => 'Worth running', 'recent' => 'Recent checks'] as $id => $label) {
            $count = match ($id) {
                'everything' => $everything['allowed'] ?? null,
                'worth' => null === $worthView || 0 === $worthView['flagged'] ? null : $worthView['flagged'],
                default => null,
            };
            $nav[] = ['id' => $id, 'label' => $label, 'count' => $count, 'title' => null];
        }

        $people = self::people($form['people']);
        $questions = self::questions($form['questions']);
        $target = $isPower ? $targets[(string) $form['q']] : $targets[''];

        return [
            'form' => [
                'people' => $people,
                'questions' => $questions,
                'target' => $target,
                'targets' => $targets,
                'values' => ['person' => $form['person'], 'q' => $form['q'], 'target' => $form['target']],
                'chosen' => [
                    'person' => self::chosen(array_merge(...array_column($people, 'options')), $form['person']),
                    'q' => self::chosen(array_merge(...array_column($questions, 'options')), $form['q']),
                    'target' => self::chosen($target['options'], $form['target']),
                ],
                'ready' => null !== $form['person'] && null !== $form['q'],
            ],
            'answer' => null === $answer ? null : self::answer($answer, $labels, $check),
            'everything' => $everything,
            'worth' => $worthView,
            'nav' => $nav,
            'page' => self::PAGE,
            'sorts' => array_map(static fn (string $k, string $l): array => ['key' => $k, 'label' => $l], array_keys(self::SORTS), self::SORTS),
        ];
    }

    /** `record surveys`: the verb, then the resource in lower case. */
    public static function pairWords(string $pair, ?string $label = null): string
    {
        [$concern, $verb] = array_pad(explode('.', $pair, 2), 2, '');
        $name = null === $label ? $concern : explode(' · ', $label)[0];

        return $verb.' '.mb_strtolower($name);
    }

    /**
     * @param list<array{tier: string, label: string, people: list<array{id: int, name: string, position: string|null}>}> $people
     *
     * @return list<array{label: string, options: list<array{value: int, label: string, count: string, find: string}>}>
     */
    private static function people(array $people): array
    {
        return array_map(static fn (array $g): array => ['label' => $g['label'], 'options' => array_map(static function (array $p): array {
            $position = $p['position'] ?? 'no position';

            return ['value' => $p['id'], 'label' => $p['name'], 'count' => $position, 'find' => mb_strtolower($p['name'].' '.$position)];
        }, $g['people'])], $people);
    }

    /**
     * @param Questions $questions
     *
     * @return list<Group>
     */
    private static function questions(array $questions): array
    {
        $menu = [];
        $byPackage = [];
        foreach ($questions['concerns'] as $concern) {
            foreach ($concern['verbs'] as $verb) {
                $byPackage[$concern['group']][] = ['value' => $verb['pair'], 'label' => $concern['label'].' · '.$verb['verb'], 'count' => null];
            }
        }
        foreach ($byPackage as $package => $options) {
            $menu[] = ['label' => (string) $package, 'options' => $options];
        }
        foreach (self::POWER_ORDER as $group) {
            $options = [];
            foreach ($questions['powers'] as $power) {
                if ($group->value === $power['group']) {
                    $options[] = ['value' => $power['key'], 'label' => $power['label'], 'count' => null];
                }
            }
            if ([] !== $options) {
                $menu[] = ['label' => $group->label(), 'options' => $options];
            }
        }

        return $menu;
    }

    /**
     * @param list<array{key: string, name: string}> $places
     *
     * @return Field
     */
    private static function placeField(array $places): array
    {
        $options = [['value' => self::PLACED, 'label' => 'where they are placed', 'count' => null]];
        foreach ($places as $place) {
            $options[] = ['value' => $place['key'], 'label' => $place['name'], 'count' => null];
        }

        return ['default' => self::PLACED, 'options' => $options];
    }

    /**
     * @param list<array{key: string, label: string}> $kinds
     *
     * @return Field
     */
    private static function targetField(array $kinds): array
    {
        return [
            'default' => $kinds[0]['key'] ?? '',
            'options' => array_map(static fn (array $k): array => ['value' => $k['key'], 'label' => $k['label'], 'count' => null], $kinds),
        ];
    }

    /**
     * THE QUESTION IN WORDS, as the verdict line asks it.
     *
     * @param Answer                $answer
     * @param array<string, string> $labels
     */
    private static function words(array $answer, array $labels): string
    {
        $about = $answer['aboutQuestion'];
        if ('power' === ($about['kind'] ?? null)) {
            return \sprintf('%s — about %s', self::text($about['label'] ?? ''), self::text($about['target'] ?? ''));
        }
        $words = self::pairWords($answer['q'], $labels[$answer['q']] ?? null);

        return match ($answer['target']) {
            self::PLACED => $words.' where they are placed — '.$answer['place']['name'],
            PlaceKey::ORGANIZATION => $words.' across the whole organization',
            default => $words.' in '.$answer['place']['name'],
        };
    }

    /**
     * @param Answer                $answer
     * @param array<string, string> $labels
     *
     * @return AnswerView
     */
    private static function answer(array $answer, array $labels, string $check): array
    {
        $isPower = 'power' === ($answer['aboutQuestion']['kind'] ?? null);
        $lines = $answer['check']['lines'];
        $walk = array_map(static fn (array $l): array => [
            'label' => self::STEPS[$l['q']] ?? $l['q'],
            'status' => $l['status'],
            'text' => $l['text'],
            'door' => null === $l['url'] || null === $l['door'] ? null : ['url' => $l['url'], 'text' => self::DOORS[self::text($l['door']['kind'] ?? '')] ?? 'open'],
        ], $lines);
        $last = [] === $lines ? null : $lines[\count($lines) - 1]['q'];
        $coverage = $answer['check']['coverage'];
        $words = self::words($answer, $labels);

        return [
            'allowed' => $answer['check']['allowed'],
            'person' => $answer['person']['name'],
            'words' => $words,
            'walk' => $walk,
            'further' => $last !== ($isPower ? 'rule' : 'department'),
            'coverage' => null === $coverage ? null : ($isPower
                ? ['kind' => 'power', 'held' => \count($coverage['byPosition']), 'actors' => $answer['actors'], 'target' => self::text($answer['aboutQuestion']['target'] ?? ''), 'asOf' => $coverage['asOf']]
                : ['kind' => 'pair', 'place' => $answer['place']['name'], 'count' => $coverage['total'], 'top' => \array_slice($coverage['byPosition'], 0, 3), 'tiers' => implode(' and ', $answer['tiers']), 'asOf' => $coverage['asOf']]),
            'asked' => [
                'person' => $answer['person']['name'],
                'words' => $words,
                'question' => $isPower ? self::text($answer['aboutQuestion']['label'] ?? '') : self::pairWords($answer['q'], $labels[$answer['q']] ?? null),
                'place' => $isPower ? self::text($answer['aboutQuestion']['target'] ?? '') : (self::PLACED === $answer['target'] ? 'where they are placed — '.$answer['place']['name'] : $answer['place']['name']),
                'allowed' => $answer['check']['allowed'],
                'href' => self::href($check, $answer['person']['id'], $answer['q'], $answer['target']),
            ],
            'aboutPerson' => $answer['aboutPerson'],
            'aboutQuestion' => $answer['aboutQuestion'],
            'questionTitle' => $isPower ? self::text($answer['aboutQuestion']['label'] ?? '') : self::pairWords($answer['q'], $labels[$answer['q']] ?? null),
            'lens' => $answer['lens'],
            'placementUrl' => $answer['placementUrl'] ?? null,
            'pairWords' => array_combine(array_keys($labels), array_map(static fn (string $p, string $l): string => self::pairWords($p, $l), array_keys($labels), $labels)),
        ];
    }

    /**
     * @param Answer                $answer
     * @param array<string, string> $labels
     * @param Questions             $questions
     *
     * @return EveryView
     */
    private static function everything(array $answer, array $labels, array $questions, string $check): array
    {
        $lens = $answer['lens'];
        $rows = $lens['everything'];
        usort($rows, static fn (array $a, array $b): int => array_search($a['group'], PermissionCheckService::EVERYTHING_GROUPS, true) <=> array_search($b['group'], PermissionCheckService::EVERYTHING_GROUPS, true));
        $target = $answer['lens']['placeIsOwn'] ? $lens['place']['key'] : $answer['target'];

        $view = [];
        foreach ($rows as $r) {
            $words = self::pairWords($r['pair'], $labels[$r['pair']] ?? null);
            $step = self::STEPS[$r['step']] ?? $r['step'];
            $view[] = [
                'pair' => $r['pair'],
                'words' => $words,
                'concern' => $r['concern'],
                'asked' => $r['asked'],
                'allowed' => $r['allowed'],
                'step' => mb_strtolower($step),
                'line' => $r['line'],
                'group' => $r['group'],
                'href' => self::href($check, $answer['person']['id'], $r['pair'], $target),
                'find' => mb_strtolower($words.' '.($labels[$r['pair']] ?? $r['pair']).' '.$step.' '.$r['line']),
            ];
        }

        $pills = [];
        foreach (self::EVERY_PILLS as $key => $label) {
            $count = \count(array_filter($view, static fn (array $r): bool => match ($key) {
                'all' => true,
                'allowed' => $r['allowed'],
                'refused' => !$r['allowed'],
                default => $r['group'] === $key,
            }));
            if (\in_array($key, ['all', 'allowed', 'refused'], true) || $count > 0) {
                $pills[] = ['key' => $key, 'label' => $label, 'count' => $count];
            }
        }

        $concerns = [];
        foreach ($questions['concerns'] as $concern) {
            $concerns[$concern['group']][] = ['key' => $concern['key'], 'label' => $concern['label'], 'count' => \count(array_filter($view, static fn (array $r): bool => $r['concern'] === $concern['key']))];
        }

        $allowed = \count(array_filter($view, static fn (array $r): bool => $r['allowed']));

        return [
            'rows' => $view,
            'pills' => $pills,
            'concerns' => array_map(static fn (string $head, array $options): array => ['head' => $head, 'options' => $options], array_keys($concerns), array_values($concerns)),
            'allowed' => $allowed,
            'total' => \count($view),
        ];
    }

    /**
     * @param Worth                 $worth
     * @param array<string, string> $labels
     * @param array<int, string>    $positions person id → the position they hold
     *
     * @return WorthView|null
     */
    private static function worth(array $worth, array $labels, array $positions, string $check): ?array
    {
        if ([] === $worth['rows']) {
            return null;
        }
        $rows = array_map(static function (array $w) use ($labels, $positions, $check): array {
            $question = 'power' === $w['question']['kind'] ? $w['question']['label'] : self::pairWords($w['question']['key'], $labels[$w['question']['key']] ?? null);

            return [
                'person' => $w['person']['name'],
                'position' => $positions[$w['person']['id']] ?? 'no position',
                'question' => $question,
                'place' => $w['place']['name'],
                'why' => $w['why'],
                'kind' => $w['kind'],
                'chip' => self::WORTH_CHIPS[$w['group']],
                'flagged' => $w['flagged'],
                'href' => self::href($check, $w['person']['id'], $w['question']['key'], $w['place']['key']),
                'find' => mb_strtolower($w['person']['name'].' '.$question.' '.$w['place']['name']),
            ];
        }, $worth['rows']);

        $pills = [];
        foreach (self::WORTH_PILLS as $key => $label) {
            $count = 'all' === $key ? \count($rows) : \count(array_filter($rows, static fn (array $r): bool => $r['kind'] === $key));
            if ('all' === $key || $count > 0) {
                $pills[] = ['key' => $key, 'label' => $label, 'count' => $count];
            }
        }

        return [
            'rows' => $rows,
            'count' => \count($rows),
            'flagged' => \count(array_filter($rows, static fn (array $r): bool => $r['flagged'])),
            'pills' => $pills,
            'sorts' => array_map(static fn (string $k, string $l): array => ['key' => $k, 'label' => $l], array_keys(self::SORTS), self::SORTS),
            'asOf' => $worth['asOf'],
        ];
    }

    /**
     * @param Questions $questions
     *
     * @return array<string, string> pair → `Surveys · record`
     */
    private static function pairLabels(array $questions): array
    {
        $labels = [];
        foreach ($questions['concerns'] as $concern) {
            foreach ($concern['verbs'] as $verb) {
                $labels[$verb['pair']] = $concern['label'].' · '.$verb['verb'];
            }
        }

        return $labels;
    }

    /**
     * The label of the option a chip names, or null when nothing is chosen.
     *
     * @param list<array{value: int|string, label: string, count: int|string|null}|array{value: int, label: string, count: string, find: string}> $options
     */
    private static function chosen(array $options, int|string|null $value): ?string
    {
        foreach ($options as $option) {
            if (null !== $value && (string) $option['value'] === (string) $value) {
                return $option['label'];
            }
        }

        return null;
    }

    private static function href(string $check, int $person, string $q, string $target): string
    {
        return $check.'?'.http_build_query(['person' => $person, 'q' => $q, 'target' => $target]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
