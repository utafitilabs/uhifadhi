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
use Uhifadhi\Bundle\TeamBundle\Permissions\CheckListing;

/**
 * THE CHECK PAGE FROM WHAT THE SERVICE AND THE READERS RETURN: the three
 * menus, the question in words, the walk with its doors, the coverage line,
 * the lens's table with its axes, Worth running with its axes, the index.
 */
/**
 * @phpstan-import-type Form from CheckListing
 * @phpstan-import-type Answer from CheckListing
 * @phpstan-import-type Worth from CheckListing
 */
#[CoversClass(CheckListing::class)]
final class CheckListingTest extends TestCase
{
    private const string CHECK = '/team/permissions/check';

    public function testTheMenusArePeopleByTierThePermissionsThenThePowersAndThePlaceFollowsTheQuestion(): void
    {
        $page = CheckListing::build(self::form(null, null, 'placed'), null, self::worth(), self::CHECK);
        $form = $page['form'];

        self::assertSame(['Super Admin', 'Admin', 'Staff'], array_column($form['people'], 'label'));
        self::assertSame(['value' => 11, 'label' => 'Asha Moshi', 'count' => 'Ranger', 'find' => 'asha moshi ranger'], $form['people'][2]['options'][0]);
        self::assertSame('no position', $form['people'][2]['options'][1]['count']);
        self::assertSame(['Surveys', 'Team', 'Gaining power'], array_column($form['questions'], 'label'), 'the permissions under their package heads, the powers last');
        self::assertSame(['value' => 'surveys.record', 'label' => 'Surveys · record', 'count' => null], $form['questions'][0]['options'][1]);
        self::assertFalse($form['ready']);
        self::assertSame(['person' => null, 'q' => null, 'target' => 'where they are placed'], $form['chosen'], 'what each chip names before a choice');

        self::assertSame(['default' => 'placed', 'options' => [['value' => 'placed', 'label' => 'where they are placed', 'count' => null], ['value' => 'organization', 'label' => 'the whole organization', 'count' => null], ['value' => 'area-1', 'label' => 'Kilimani', 'count' => null]]], $form['target']);
        self::assertSame(['default' => 'a-colleague', 'options' => [['value' => 'a-colleague', 'label' => 'a colleague', 'count' => null]]], $form['targets']['raise-own-rank']);
        self::assertSame(['', 'raise-own-rank'], array_keys($form['targets']), 'one menu for every permission, one per power');

        $chosen = CheckListing::build(self::form(11, 'surveys.record', 'area-1'), null, self::worth(), self::CHECK)['form']['chosen'];
        self::assertSame(['person' => 'Asha Moshi', 'q' => 'Surveys · record', 'target' => 'Kilimani'], $chosen);
        $power = CheckListing::build(self::form(11, 'raise-own-rank', 'a-colleague'), null, self::worth(), self::CHECK)['form'];
        self::assertSame(['person' => 'Asha Moshi', 'q' => 'raise their own rank', 'target' => 'a colleague'], $power['chosen']);
        self::assertSame($power['targets']['raise-own-rank'], $power['target']);
    }

    public function testTheAnswerIsTheQuestionInWordsTheWalkAndTheCoverage(): void
    {
        $page = CheckListing::build(self::form(11, 'surveys.record', 'area-1'), self::answer(), self::worth(), self::CHECK);
        $answer = $page['answer'];
        self::assertNotNull($answer);

        self::assertTrue($page['form']['ready']);
        self::assertFalse($answer['allowed']);
        self::assertSame('record surveys in Kilimani', $answer['words']);
        self::assertSame(['Tier', 'Position', 'Place'], array_column($answer['walk'], 'label'));
        self::assertSame(['url' => '/team/u-11', 'text' => 'open the person'], $answer['walk'][0]['door']);
        self::assertNull($answer['walk'][2]['door']);
        self::assertTrue($answer['further'], 'the walk stopped before the department');
        self::assertSame(['kind' => 'pair', 'place' => 'Kilimani', 'count' => 3, 'top' => [['position' => 'Ranger', 'count' => 3]], 'tiers' => 'Super Admin and Admin'], array_diff_key($answer['coverage'] ?? [], ['asOf' => 0]));
        self::assertSame(['person' => 'Asha Moshi', 'words' => 'record surveys in Kilimani', 'question' => 'record surveys', 'place' => 'Kilimani', 'allowed' => false, 'href' => self::CHECK.'?person=11&q=surveys.record&target=area-1'], $answer['asked']);

        $placed = CheckListing::build(self::form(11, 'surveys.record', 'placed'), self::answer('placed'), self::worth(), self::CHECK);
        self::assertSame('record surveys where they are placed — Kilimani', $placed['answer']['words'] ?? null);
        $org = CheckListing::build(self::form(11, 'surveys.record', 'organization'), self::answer('organization'), self::worth(), self::CHECK);
        self::assertSame('record surveys across the whole organization', $org['answer']['words'] ?? null);
    }

    public function testEverythingHereIsOrderedAndItsAxesCountOnlyTheGroupsThatOccur(): void
    {
        $page = CheckListing::build(self::form(11, 'surveys.record', 'area-1'), self::answer(), self::worth(), self::CHECK);
        $every = $page['everything'];
        self::assertNotNull($every);

        self::assertSame(['surveys.read', 'surveys.record', 'positions.configure'], array_column($every['rows'], 'pair'), 'allowed, then place, then the tiers');
        self::assertSame('read surveys', $every['rows'][0]['words']);
        self::assertSame(self::CHECK.'?person=11&q=surveys.read&target=area-1', $every['rows'][0]['href']);
        self::assertSame('read surveys surveys · read department protection runs surveys', $every['rows'][0]['find']);
        self::assertSame(['all' => 3, 'allowed' => 1, 'refused' => 2, 'place' => 1, 'tier-only' => 1], array_column($every['pills'], 'count', 'key'));
        self::assertSame(['All', 'Allowed', 'Refused', 'By Place', 'For the tiers'], array_column($every['pills'], 'label'));
        self::assertSame([['head' => 'Surveys', 'options' => [['key' => 'surveys', 'label' => 'Surveys', 'count' => 2]]], ['head' => 'Team', 'options' => [['key' => 'positions', 'label' => 'Positions', 'count' => 1]]]], $every['concerns']);
        self::assertSame([1, 3], [$every['allowed'], $every['total']]);
    }

    public function testWorthRunningCarriesItsChipKindAndDoor(): void
    {
        $worth = CheckListing::build(self::form(null, null, 'placed'), null, self::worth(), self::CHECK)['worth'];
        self::assertNotNull($worth);

        self::assertSame([2, 1], [$worth['count'], $worth['flagged']]);
        self::assertSame(['warn', 'pp-quiet'], array_column($worth['rows'], 'chip'));
        self::assertSame('record surveys', $worth['rows'][0]['question']);
        self::assertSame(['Ranger', 'no position'], array_column($worth['rows'], 'position'), 'the position from the people the form lists');
        self::assertSame(self::CHECK.'?person=11&q=surveys.record&target=area-1', $worth['rows'][0]['href']);
        self::assertSame(['all' => 2, 'reaches-nothing' => 1, 'nothing-flagged' => 1], array_column($worth['pills'], 'count', 'key'));
        self::assertSame(['Flagged first', 'By name', 'By place'], array_column($worth['sorts'], 'label'));
    }

    public function testTheIndexIsOneRowPerCard(): void
    {
        $nav = CheckListing::build(self::form(11, 'surveys.record', 'area-1'), self::answer(), self::worth(), self::CHECK)['nav'];
        self::assertSame(['check', 'everything', 'worth', 'recent'], array_column($nav, 'id'));
        self::assertSame([null, 1, 1, null], array_column($nav, 'count'));

        $none = CheckListing::build(self::form(null, null, 'placed'), null, ['rows' => [], 'asOf' => null], self::CHECK);
        self::assertNull($none['worth']);
        self::assertSame([null, null, null, null], array_column($none['nav'], 'count'));
    }

    /** @return Form */
    private static function form(?int $person, ?string $q, string $target): array
    {
        return [
            'people' => [
                ['tier' => 'super_admin', 'label' => 'Super Admin', 'people' => [['id' => 1, 'name' => 'Ada Kimaro', 'position' => null]]],
                ['tier' => 'admin', 'label' => 'Admin', 'people' => []],
                ['tier' => 'staff', 'label' => 'Staff', 'people' => [['id' => 11, 'name' => 'Asha Moshi', 'position' => 'Ranger'], ['id' => 12, 'name' => 'Baraka Kimaro', 'position' => null]]],
            ],
            'questions' => [
                'asOf' => null,
                'concerns' => [
                    ['key' => 'surveys', 'label' => 'Surveys', 'group' => 'Surveys', 'verbs' => [['pair' => 'surveys.read', 'verb' => 'read', 'label' => 'read', 'answers' => 0, 'positions' => 1], ['pair' => 'surveys.record', 'verb' => 'record', 'label' => 'record', 'answers' => 0, 'positions' => 1]]],
                    ['key' => 'positions', 'label' => 'Positions', 'group' => 'Team', 'verbs' => [['pair' => 'positions.configure', 'verb' => 'configure', 'label' => 'configure', 'answers' => 0, 'positions' => 0]]],
                ],
                'powers' => [['key' => 'raise-own-rank', 'label' => 'raise their own rank', 'group' => 'gaining-power', 'groupLabel' => 'Gaining power', 'answers' => 0, 'allowed' => 0, 'target' => 'a colleague']],
            ],
            'places' => [['key' => 'organization', 'name' => 'the whole organization'], ['key' => 'area-1', 'name' => 'Kilimani']],
            'targets' => ['raise-own-rank' => [['key' => 'a-colleague', 'label' => 'a colleague']]],
            'person' => $person,
            'q' => $q,
            'target' => $target,
        ];
    }

    /** @return Answer */
    private static function answer(string $target = 'area-1'): array
    {
        return [
            'person' => ['id' => 11, 'name' => 'Asha Moshi'],
            'q' => 'surveys.record',
            'target' => $target,
            'place' => ['key' => 'area-1', 'name' => 'Kilimani'],
            'check' => [
                'allowed' => false,
                'question' => ['kind' => 'pair', 'key' => 'surveys.record', 'label' => 'Surveys · record'],
                'place' => ['key' => 'area-1', 'name' => 'Kilimani'],
                'lines' => [
                    self::line('tier', 'skip', 'Staff — the tiers alone are not asked', ['kind' => 'person', 'id' => 11, 'uuid' => 'u-11', 'name' => 'Asha Moshi']),
                    self::line('position', 'ok', 'Ranger — holds surveys.record', ['kind' => 'position', 'id' => 7, 'uuid' => 'p-7', 'name' => 'Ranger']),
                    self::line('place', 'no', 'Kilimani — their placement does not cover it'),
                ],
                'coverage' => ['asOf' => new \DateTimeImmutable('2026-11-06 14:02'), 'total' => 3, 'byPosition' => [['position' => 'Ranger', 'count' => 3]]],
            ],
            'lens' => [
                'person' => 'Asha Moshi', 'place' => ['key' => 'area-1', 'name' => 'Kilimani'], 'placeIsOwn' => false, 'tierHoldsAll' => false, 'tierLabel' => 'Staff',
                'cover' => ['covers' => false, 'how' => 'not', 'areas' => ['Sinde']],
                'department' => ['module' => 'Surveys', 'served' => ['Protection'], 'running' => ['Protection'], 'hit' => 'Protection'],
                'exception' => ['state' => 'none', 'pair' => null, 'by' => null, 'at' => null, 'reason' => null, 'appliesHere' => false],
                'everything' => [
                    ['pair' => 'positions.configure', 'concern' => 'positions', 'asked' => false, 'allowed' => false, 'step' => 'tier-only', 'line' => 'only the tiers', 'held' => false, 'group' => 'tier-only'],
                    ['pair' => 'surveys.read', 'concern' => 'surveys', 'asked' => false, 'allowed' => true, 'step' => 'department', 'line' => 'Protection runs Surveys', 'held' => true, 'group' => 'allowed'],
                    ['pair' => 'surveys.record', 'concern' => 'surveys', 'asked' => true, 'allowed' => false, 'step' => 'place', 'line' => 'Kilimani — no', 'held' => true, 'group' => 'place'],
                ],
                'catalogueCount' => 3, 'allowedHere' => 1,
            ],
            'aboutPerson' => ['tier' => 'Staff', 'above' => false, 'position' => ['name' => 'Ranger', 'uuid' => 'p-7'], 'placedAt' => 'Sinde', 'department' => 'Protection', 'supports' => [], 'exception' => ['state' => 'none', 'pair' => null, 'by' => null, 'at' => null, 'reason' => null]],
            'aboutQuestion' => ['kind' => 'pair', 'description' => 'Surveys.', 'verbs' => [['verb' => 'read', 'asked' => false], ['verb' => 'record', 'asked' => true]], 'scopes' => ['area'], 'module' => 'Surveys', 'lifts' => null, 'tierOnly' => false, 'positions' => 1, 'people' => 3],
            'tiers' => ['Super Admin', 'Admin'],
            'actors' => 0,
        ];
    }

    /**
     * @param array<string, mixed>|null $door
     *
     * @return array{q: string, status: string, text: string, door: array<string, mixed>|null, url: string|null}
     */
    private static function line(string $q, string $status, string $text, ?array $door = null): array
    {
        return ['q' => $q, 'status' => $status, 'text' => $text, 'door' => $door, 'url' => null === $door ? null : '/team/u-11'];
    }

    /** @return Worth */
    private static function worth(): array
    {
        return ['asOf' => new \DateTimeImmutable('2026-11-06 14:02'), 'rows' => [
            ['person' => ['id' => 11, 'uuid' => 'u-11', 'name' => 'Asha Moshi'], 'question' => ['kind' => 'pair', 'key' => 'surveys.record', 'label' => 'Surveys · record'], 'place' => ['key' => 'area-1', 'name' => 'Kilimani'], 'why' => 'reaches nothing', 'kind' => 'reaches-nothing', 'group' => 'attention', 'flagged' => true],
            ['person' => ['id' => 12, 'uuid' => 'u-12', 'name' => 'Baraka Kimaro'], 'question' => ['kind' => 'pair', 'key' => 'surveys.read', 'label' => 'Surveys · read'], 'place' => ['key' => 'organization', 'name' => 'the whole organization'], 'why' => 'nothing flagged', 'kind' => 'nothing-flagged', 'group' => 'none', 'flagged' => false],
        ]];
    }
}
