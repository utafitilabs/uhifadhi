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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Permissions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Permissions\AnalysisListing;

/**
 * THE ANALYSIS PAGE AS IT IS DRAWN: the question read from the address, the
 * dropdown in the design's groups and counts, the answer to a pair or a
 * power, the lists for review and the drift, and one index row per card.
 *
 * @phpstan-import-type Questions from AnalysisListing
 * @phpstan-import-type Standing from AnalysisListing
 * @phpstan-import-type Drift from AnalysisListing
 * @phpstan-import-type Page from AnalysisListing
 * @phpstan-import-type PairAnswer from AnalysisListing
 * @phpstan-import-type PowerAnswer from AnalysisListing
 */
#[CoversClass(AnalysisListing::class)]
final class AnalysisListingTest extends TestCase
{
    private const array PLACES = [
        ['key' => 'organization', 'kind' => 'organization', 'id' => null, 'name' => 'the whole organization'],
        ['key' => 'area-1', 'kind' => 'area', 'id' => 1, 'name' => 'Kilimani'],
        ['key' => 'area-2', 'kind' => 'area', 'id' => 2, 'name' => 'Ziwa Kavu'],
    ];

    public function testTheQuestionIsReadFromTheAddress(): void
    {
        $questions = self::questions();

        self::assertSame('surveys.record', AnalysisListing::resolve($questions, 'surveys.record'));
        self::assertSame('make-admin', AnalysisListing::resolve($questions, 'make-admin'));
        self::assertSame('stations.read', AnalysisListing::resolve($questions, 'stations'), 'a concern opens on its first verb');
        self::assertSame('surveys.read', AnalysisListing::resolve($questions, 'nothing.such'), 'the first pair otherwise');
        self::assertSame('surveys.read', AnalysisListing::resolve($questions, null));
    }

    public function testTheDropdownIsThePackagesThenThePowerGroupsInTheDesignsOrder(): void
    {
        $page = self::pairPage();

        self::assertSame(['Surveys', 'Ground', 'Powers'], array_column($page['menu'], 'label'), 'the packages, then the powers in one group');
        self::assertSame(['raise-their-own-tier', 'make-admin', 'edit-someone'], array_column($page['menu'][2]['options'], 'value'), 'the power groups in the design order');
        self::assertSame('Surveys · record', $page['question']['label']);
        self::assertSame([
            ['value' => 'surveys.read', 'label' => 'Surveys · read', 'count' => 4, 'title' => '4 positions hold it'],
            ['value' => 'surveys.record', 'label' => 'Surveys · record', 'count' => 1, 'title' => '1 position holds it'],
        ], $page['menu'][0]['options']);
        self::assertSame(['value' => 'make-admin', 'label' => 'make Admin', 'count' => 1, 'title' => '1 actor holds it over a colleague in their area'], $page['menu'][2]['options'][1]);
        self::assertSame('2 actors hold it over themselves', $page['menu'][2]['options'][0]['title']);
        self::assertSame('surveys.read', $page['question']['default']);
    }

    public function testAPairIsPositionsDownPlacesAcrossWithThePeopleBehindEachCell(): void
    {
        $page = self::pairPage();
        $pair = $page['pair'];
        self::assertNotNull($pair);
        self::assertNull($page['power']);

        self::assertSame('Who can record surveys, and where', $page['question']['title']);
        self::assertSame([['label' => 'Super Admin', 'count' => 1], ['label' => 'Admin', 'count' => 2]], $pair['tiers']);
        self::assertSame(['The whole organization', 'Kilimani', 'Ziwa Kavu'], array_column($pair['heads'], 'head'));
        self::assertTrue($pair['heads'][0]['organization']);
        self::assertSame(['Rangers', 'Head'], array_column($pair['rows'], 'position'));

        $cells = $pair['rows'][0]['cells'];
        self::assertSame(0, $cells[0]['count']);
        self::assertSame(['count' => 2, 'cell' => '7|area-1', 'label' => '2 Rangers · Kilimani · changed since the last run', 'changed' => true], $cells[1], 'the drift names Kilimani on this row');
        self::assertFalse($cells[0]['changed']);
        self::assertSame('1 Head · the whole organization', $pair['rows'][1]['cells'][0]['label'] ?? null);
        self::assertSame(['1', '2', ''], $pair['totals']);
        self::assertNull($pair['none']);

        self::assertSame('Rangers · Kilimani', $pair['people']['7|area-1']['where']);
        self::assertSame([
            ['name' => 'Asha Moshi', 'url' => '/check?person=11&q=surveys.record&target=area-1', 'id' => 11, 'departments' => '5'],
            ['name' => 'Baraka Kimaro', 'url' => '/check?person=12&q=surveys.record&target=area-1', 'id' => 12, 'departments' => '5 6'],
        ], $pair['people']['7|area-1']['names']);
    }

    public function testTheGridsChipsOpenOnEveryRowAnyDepartmentMostPeopleFirst(): void
    {
        $pair = self::pairPage()['pair'];
        self::assertNotNull($pair);
        $chips = array_column($pair['register']['chips'], null, 'axis');

        self::assertSame(['rows', 'department', 'sort'], array_keys($chips));
        self::assertSame('all positions · 2', $chips['rows']['value']);
        self::assertSame([['all', 2], ['with-people', 2], ['empty', 0], ['changed', 1]], array_map(static fn (array $o): array => [$o['value'], $o['count']], $chips['rows']['options']));
        self::assertSame('department', $chips['department']['value']);
        self::assertSame([['', 'Any department', 3], ['5', 'Ecology', 2], ['6', 'Protection', 1], ['7', 'Tourism', 0]], array_map(static fn (array $o): array => [$o['value'], $o['label'], $o['count']], $chips['department']['options']), 'a person counts under every department they serve');
        self::assertSame([null, null], array_column($chips['sort']['options'], 'count'));
        self::assertSame(2, $pair['changed'], 'every place the drift names for the pair, a column or not');
        self::assertSame([true, false], array_column($pair['rows'], 'changed'));
        self::assertSame('Total', $pair['total']);
        self::assertSame(1, $pair['register']['pages']);
    }

    public function testMoreThanAPageOfRowsSaysSoInTheTotalAndTheFoot(): void
    {
        $rows = [];
        for ($i = 1; $i <= 21; ++$i) {
            $rows[] = ['positionId' => $i, 'position' => 'P'.$i, 'holders' => 0, 'counts' => ['organization' => 0, 'area-1' => 0, 'area-2' => 0], 'anywhere' => 0, 'people' => 0];
        }
        $pair = AnalysisListing::build(self::questions(), 'surveys.record', self::pair($rows), [], null, self::PLACES, self::standing(), ['unreviewed' => 0, 'lines' => []], null)['pair'];
        self::assertNotNull($pair);

        self::assertSame('Total · all 21', $pair['total']);
        self::assertSame(2, $pair['register']['pages']);
    }

    public function testEveryListIsARegisterWithItsAxesAndItsNoun(): void
    {
        $lists = array_column(self::pairPage()['lists'], null, 'id');

        $reach = $lists['reach-nothing']['register'];
        self::assertSame('people', $reach['noun']);
        self::assertSame(1, $reach['count']);
        self::assertSame(['reason', 'position'], array_column($reach['chips'], 'axis'));
        self::assertSame('all · 1', $reach['chips'][0]['value']);
        self::assertSame([['', 'All', 1], ['no position · no placement', 'no position · no placement', 1]], array_map(static fn (array $o): array => [$o['value'], $o['label'], $o['count']], $reach['chips'][0]['options']));
        self::assertSame('position', $reach['chips'][1]['value']);
        self::assertSame('Search the people', $reach['search']);
        self::assertSame('{"reason":["no position · no placement"],"position":["no position"]}', $lists['reach-nothing']['entries'][0]['f']);
        self::assertSame(['what'], array_column($lists['self-changes']['register']['chips'], 'axis'));
        self::assertSame(['position', 'permission', 'by'], array_column($lists['exceptions']['register']['chips'], 'axis'));
    }

    public function testDriftIsARegisterAndEachLineOpensItsLiveCheck(): void
    {
        $drift = AnalysisListing::build(self::questions(), 'surveys.record', self::pair(), self::cells(), null, self::PLACES, self::standing(), self::drift(), '/check', [7 => 11])['drift'];

        self::assertSame(['place', 'position', 'review'], array_column($drift['register']['chips'], 'axis'));
        self::assertSame([['', 1], ['unreviewed', 1], ['reviewed', 0]], array_map(static fn (array $o): array => [$o['value'], $o['count']], $drift['register']['chips'][2]['options']));
        self::assertSame('/check?person=11&q=surveys.record&target=area-1', $drift['rows'][0]['door']);
        self::assertNull(self::pairPage()['drift']['rows'][0]['door'], 'no holder, no door');
    }

    public function testTheQuestionsIndexRowCountsThePeopleBelowTheTiers(): void
    {
        $nav = self::pairPage()['nav'];

        self::assertSame(['question', 'reach-nothing', 'not-in-force', 'not-honoured', 'gaining', 'vacant', 'exceptions', 'self-changes', 'drift'], array_column($nav, 'id'));
        self::assertSame(['The question', 'Reach nothing', 'Not in force', 'Not honoured', 'Gaining power', 'Vacant positions', 'Exceptions in force', 'Self-changes', 'Drift'], array_column($nav, 'label'));
        self::assertSame(3, $nav[0]['count']);
        self::assertSame('3 people below the tiers can, somewhere', $nav[0]['title']);
        self::assertSame([1, 0, 0, 0, 0, 0, 1, 1], array_column(\array_slice($nav, 1), 'count'));
    }

    public function testAPairNobodyPassesAndAPageBeforeAnyRunSaySo(): void
    {
        $passed = AnalysisListing::build(self::questions(), 'surveys.read', self::pair([], true), [], null, self::PLACES, self::standing(), ['unreviewed' => 0, 'lines' => []], null);
        self::assertSame('No position’s holders pass anywhere; only the tiers can.', $passed['pair']['none'] ?? null);

        $never = AnalysisListing::build(self::questions(), 'surveys.read', ['pair' => 'surveys.read', 'label' => 'Surveys · read', 'filed' => false, 'asOf' => null, 'places' => [], 'rows' => [], 'totals' => [], 'tiers' => [], 'departments' => []], [], null, [], self::standing(), self::drift(), null);
        self::assertSame('', $never['pair']['none'] ?? null, 'the template says not computed yet');
    }

    public function testAPowerIsActorsDownTargetKindsAcross(): void
    {
        $page = AnalysisListing::build(self::questions(), 'raise-their-own-tier', null, [], self::power(true), self::PLACES, self::standing(), self::drift(), '/check', [7 => 11], ['tiers' => ['super_admin' => 1]]);
        $power = $page['power'];
        self::assertNotNull($power);
        self::assertNull($page['pair']);

        self::assertSame('Who holds raise their own tier, over whom', $page['question']['title']);
        self::assertSame(['group' => 'Gaining power', 'question' => 'can anybody raise themselves?'], $power['lead']);
        self::assertTrue($power['expect']);
        self::assertTrue($power['alarm']);
        self::assertSame(['themselves'], $power['targets']);
        self::assertArrayNotHasKey('groups', $power, 'no band rows: tier or position is each name\'s sub-line');
        self::assertSame([
            ['name' => 'Super Admin', 'kind' => 'tier', 'cells' => [true], 'url' => '/check?person=1&q=raise-their-own-tier&target=themselves', 'f' => '{"verdict":["allowed"]}', 'q' => 'super admin'],
            ['name' => 'Rangers', 'kind' => 'position', 'cells' => [true], 'url' => '/check?person=11&q=raise-their-own-tier&target=themselves', 'f' => '{"verdict":["allowed"]}', 'q' => 'rangers'],
        ], $power['rows'], 'the tiers first, each with its check');
        self::assertSame(['verdict'], array_column($power['register']['chips'], 'axis'));
        self::assertSame('actors', $power['register']['noun']);
        self::assertSame([['', 2], ['allowed', 2], ['refused', 0]], array_map(static fn (array $o): array => [$o['value'], $o['count']], $power['register']['chips'][0]['options']));
        self::assertSame(2, $page['nav'][0]['count']);
        self::assertSame('2 actors hold it over at least one target', $page['nav'][0]['title']);
    }

    public function testTheListsAreTheRunsInTheDesignsWords(): void
    {
        $lists = array_column(self::pairPage()['lists'], null, 'id');

        self::assertSame('People who reach nothing', $lists['reach-nothing']['title']);
        self::assertTrue($lists['reach-nothing']['bad']);
        $entry = $lists['reach-nothing']['entries'][0];
        self::assertSame(['name' => 'Frank Massawe', 'position' => 'no position', 'why' => 'no position · no placement', 'url' => '/check?person=3'], array_intersect_key($entry, array_flip(['name', 'position', 'why', 'url'])));
        self::assertSame('frank massawe no position no position · no placement', $entry['q'], 'a search reads the words, not the door');
        self::assertFalse($lists['vacant']['bad'], 'zero is never warn');
        self::assertFalse($lists['self-changes']['bad'], 'self-changes are never an alarm');
        self::assertSame(['who' => 'Desta Haile', 'what' => 'tier', 'before' => 'Admin', 'after' => 'Super Admin', 'at' => null], array_intersect_key($lists['self-changes']['entries'][0], array_flip(['who', 'what', 'before', 'after', 'at'])));

        $without = AnalysisListing::build(self::questions(), 'surveys.record', self::pair(), self::cells(), null, self::PLACES, self::standing(), self::drift(), null);
        self::assertNull(array_column($without['lists'], null, 'id')['reach-nothing']['entries'][0]['url'], 'no Check route, no door');
    }

    public function testEachListRowOpensItsRecord(): void
    {
        $standing = self::standing();
        $standing['lists'][1] = self::list('not-in-force', 'Sensitive permissions held but not in force', true, [['positionId' => 7, 'position' => 'Rangers', 'pair' => 'locations.read', 'pairLabel' => 'Live locations · read']]);
        $standing['lists'][3] = self::list('gaining', 'Gaining power — answers below Super Admin', true, [
            ['subject' => 'Rangers', 'positionId' => 7, 'power' => 'raise-their-own-tier', 'detail' => 'raise their own tier — themselves'],
            ['subject' => 'Admin', 'positionId' => null, 'power' => 'raise-their-own-tier', 'detail' => 'raise their own tier — themselves'],
        ]);
        $standing['lists'][4] = self::list('vacant', 'Positions nobody holds', false, [['positionId' => 9, 'position' => 'Tourism warden', 'since' => null]]);
        $doors = ['positions' => [7 => '/team/positions/u-7', 9 => '/team/positions/u-9'], 'people' => [4 => '/team/u-4']];
        $lists = array_column(AnalysisListing::build(self::questions(), 'surveys.record', self::pair(), self::cells(), null, self::PLACES, $standing, self::drift(), '/check', [], $doors)['lists'], null, 'id');

        self::assertSame('/team/positions/u-7', $lists['not-in-force']['entries'][0]['door']);
        self::assertSame('/team/positions/u-9', $lists['vacant']['entries'][0]['door']);
        self::assertSame('/team/u-4', $lists['self-changes']['entries'][0]['door']);
        self::assertSame(['Rangers', 'position', '/team/positions/u-7'], [$lists['gaining']['entries'][0]['who'], $lists['gaining']['entries'][0]['kind'], $lists['gaining']['entries'][0]['door']]);
        self::assertSame(['Admin', 'tier', null], [$lists['gaining']['entries'][1]['who'], $lists['gaining']['entries'][1]['kind'], $lists['gaining']['entries'][1]['door']], 'a tier has no record to open');
        self::assertSame('rangers live locations · read', $lists['not-in-force']['entries'][0]['q'], 'a search reads the words, not the door');
    }

    public function testDriftIsOneRowPerLineWithTheMovedPlacesInTheirColumns(): void
    {
        $drift = self::pairPage()['drift'];

        self::assertSame(1, $drift['lines']);
        self::assertSame(2, $drift['changed']);
        self::assertFalse($drift['done']);
        self::assertSame(['The whole organization', 'Kilimani', 'Ziwa Kavu'], $drift['heads']);
        self::assertNotNull($drift['between']);

        $row = $drift['rows'][0];
        self::assertSame('Rangers', $row['subject']);
        self::assertSame('Surveys · record', $row['label']);
        self::assertSame([null, ['was' => 3, 'now' => 4], null], $row['cells']);
        self::assertSame([['place' => 'a colleague', 'was' => 1, 'now' => 0]], $row['other'], 'a place no column names is kept');
        self::assertSame([22], $row['ids'], 'the unreviewed ids only');
        self::assertFalse($row['reviewed']);
    }

    /** @return Page */
    private static function pairPage(): array
    {
        return AnalysisListing::build(self::questions(), 'surveys.record', self::pair(), self::cells(), null, self::PLACES, self::standing(), self::drift(), '/check');
    }

    /** @return Questions */
    private static function questions(): array
    {
        return [
            'asOf' => null,
            'concerns' => [
                ['key' => 'surveys', 'label' => 'Surveys', 'group' => 'Surveys', 'verbs' => [
                    ['pair' => 'surveys.read', 'verb' => 'read', 'label' => 'Read', 'answers' => 3, 'positions' => 4],
                    ['pair' => 'surveys.record', 'verb' => 'record', 'label' => 'Record', 'answers' => 3, 'positions' => 1],
                ]],
                ['key' => 'stations', 'label' => 'Stations', 'group' => 'Ground', 'verbs' => [
                    ['pair' => 'stations.read', 'verb' => 'read', 'label' => 'Read', 'answers' => 1, 'positions' => 2],
                ]],
                ['key' => 'empty', 'label' => 'Empty', 'group' => 'Nothing', 'verbs' => []],
            ],
            'powers' => [
                ['key' => 'make-admin', 'label' => 'make Admin', 'group' => 'giving-power', 'groupLabel' => 'Giving power', 'answers' => 9, 'allowed' => 1, 'target' => 'a colleague in their area'],
                ['key' => 'edit-someone', 'label' => 'edit somebody', 'group' => 'somebody-elses-records', 'groupLabel' => 'Somebody else’s records', 'answers' => 9, 'allowed' => 0, 'target' => 'a colleague in their area'],
                ['key' => 'raise-their-own-tier', 'label' => 'raise their own tier', 'group' => 'gaining-power', 'groupLabel' => 'Gaining power', 'answers' => 9, 'allowed' => 2, 'target' => 'themselves'],
            ],
        ];
    }

    /**
     * @param list<array{positionId: int, position: string, holders: int, counts: array<string, int>, anywhere: int, people: int}>|null $rows
     *
     * @return PairAnswer
     */
    private static function pair(?array $rows = null, bool $filed = true): array
    {
        $rows ??= [
            ['positionId' => 7, 'position' => 'Rangers', 'holders' => 3, 'counts' => ['organization' => 0, 'area-1' => 2, 'area-2' => 0], 'anywhere' => 2, 'people' => 2],
            ['positionId' => 8, 'position' => 'Head', 'holders' => 1, 'counts' => ['organization' => 1, 'area-1' => 0, 'area-2' => 0], 'anywhere' => 1, 'people' => 1],
        ];

        return [
            'pair' => 'surveys.record', 'label' => 'Surveys · record', 'filed' => $filed, 'asOf' => new \DateTimeImmutable('2026-11-06 14:02'),
            'places' => self::PLACES, 'rows' => $rows, 'totals' => ['organization' => 1, 'area-1' => 2, 'area-2' => 0],
            'tiers' => [['key' => 'super_admin', 'label' => 'Super Admin', 'count' => 1], ['key' => 'admin', 'label' => 'Admin', 'count' => 2]],
            'departments' => [['id' => 5, 'name' => 'Ecology'], ['id' => 6, 'name' => 'Protection'], ['id' => 7, 'name' => 'Tourism']],
        ];
    }

    /** @return array<string, array<int, list<array{id: int, uuid: string, name: string, departments: list<int>}>>> */
    private static function cells(): array
    {
        return [
            'area-1' => [7 => [['id' => 11, 'uuid' => 'u-11', 'name' => 'Asha Moshi', 'departments' => [5]], ['id' => 12, 'uuid' => 'u-12', 'name' => 'Baraka Kimaro', 'departments' => [5, 6]]]],
            'organization' => [8 => [['id' => 13, 'uuid' => 'u-13', 'name' => 'Chausiku Ngowi', 'departments' => []]]],
        ];
    }

    /** @return PowerAnswer */
    private static function power(bool $alarm): array
    {
        return [
            'power' => ['key' => 'raise-their-own-tier', 'label' => 'raise their own tier', 'group' => 'gaining-power', 'groupLabel' => 'Gaining power'],
            'targets' => [['key' => 'themselves', 'label' => 'themselves']],
            'rows' => [
                ['kind' => 'tier', 'name' => 'Super Admin', 'positionId' => null, 'tier' => 'super_admin', 'cells' => ['themselves' => 'allowed'], 'refusedBy' => ['themselves' => null]],
                ['kind' => 'position', 'name' => 'Rangers', 'positionId' => 7, 'tier' => null, 'cells' => ['themselves' => 'allowed'], 'refusedBy' => ['themselves' => null]],
            ],
            'alarm' => $alarm,
            'asOf' => null,
        ];
    }

    /** @return Standing */
    private static function standing(): array
    {
        return [
            'asOf' => new \DateTimeImmutable('2026-11-06 14:02'),
            'lists' => [
                self::list('reach-nothing', 'People who reach nothing', true, [['personId' => 3, 'name' => 'Frank Massawe', 'position' => null, 'why' => 'no position · no placement']]),
                self::list('not-in-force', 'Sensitive permissions held but not in force', true, []),
                self::list('not-honoured', 'Tier-only permissions stored on a position and not honoured', true, []),
                self::list('gaining', 'Gaining power — answers below Super Admin', true, []),
                self::list('vacant', 'Positions nobody holds', false, []),
                self::list('exceptions', 'Exceptions in force', false, []),
                self::list('self-changes', 'Self-changes', false, [['personId' => 4, 'name' => 'Desta Haile', 'kind' => 'tier-changed', 'what' => 'tier', 'before' => 'Admin', 'after' => 'Super Admin', 'at' => null]]),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array{id: string, label: string, alarm: bool, count: int, entries: list<array<string, mixed>>}
     */
    private static function list(string $id, string $label, bool $alarm, array $entries): array
    {
        return ['id' => $id, 'label' => $label, 'alarm' => $alarm, 'count' => \count($entries), 'entries' => $entries];
    }

    /** @return Drift */
    private static function drift(): array
    {
        return [
            'unreviewed' => 1,
            'lines' => [[
                'subject' => 'Rangers', 'positionId' => 7, 'questionKind' => 'pair', 'question' => 'surveys.record', 'label' => 'Surveys · record',
                'places' => [
                    ['driftId' => 21, 'place' => 'a colleague', 'was' => 1, 'now' => 0, 'reviewed' => true],
                    ['driftId' => 22, 'place' => 'Kilimani', 'was' => 3, 'now' => 4, 'reviewed' => false],
                ],
                'found' => new \DateTimeImmutable('2026-11-06 14:02'), 'previousRun' => new \DateTimeImmutable('2026-11-05 02:00'),
            ]],
        ];
    }
}
