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
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsListing;
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsReportReader;

/**
 * THE POSITIONS PAGE AS IT IS DRAWN: rows in the default order, the marks and
 * the notes in the design's words, and every count over the rows written.
 *
 * @phpstan-import-type PositionRow from PositionsReportReader
 * @phpstan-import-type Page from PositionsListing
 *
 * @phpstan-type Groups list<array{group: string, concerns: list<array{key: string, label: string, sensitive: bool, lifts: string|null, verbs: list<string>}>, line: string}>
 */
#[CoversClass(PositionsListing::class)]
final class PositionsListingTest extends TestCase
{
    public function testRowsAreMostHoldersFirstThenByName(): void
    {
        $page = self::page([self::position('Warden', 0), self::position('Clerk', 2), self::position('Assessor', 2)]);

        self::assertSame(['Assessor', 'Clerk', 'Warden'], array_column($page['listed'], 'name'));
        self::assertSame(3, $page['shown']);
        self::assertSame('· 3 · read as written', $page['source']);
    }

    public function testTheSummaryCountsPairsAndConcerns(): void
    {
        $page = self::page([self::position('Clerk', 1, [
            ['group' => 'Surveys', 'concerns' => [['key' => 'surveys', 'label' => 'Surveys', 'sensitive' => false, 'lifts' => null, 'verbs' => ['read', 'record']]], 'line' => ''],
            ['group' => 'Ground', 'concerns' => [['key' => 'stations', 'label' => 'Stations', 'sensitive' => true, 'lifts' => 'the area rule', 'verbs' => ['read']]], 'line' => ''],
        ]), self::position('Idle', 0)]);

        $clerk = $page['listed'][0];
        self::assertSame('3 permissions across 2 resources: Surveys, Stations', $clerk['summary']);
        self::assertSame(['surveys', 'stations'], $clerk['concerns']);
        self::assertSame('read, record', $clerk['groups'][0]['concerns'][0]['verbs']);
        self::assertSame('surveys.read', $clerk['groups'][0]['concerns'][0]['pair']);
        self::assertNull($clerk['groups'][0]['concerns'][0]['sensitive']);
        self::assertSame('sensitive — lifts the area rule', $clerk['groups'][1]['concerns'][0]['sensitive']);
        self::assertSame('holds no permission', $page['listed'][1]['summary']);
    }

    public function testMarksAndNotesSayWhatIsWrong(): void
    {
        $row = self::position('Coordinator', 1, [], [
            'locked' => true,
            'vacantSince' => new \DateTimeImmutable('2026-08-14'),
            'exceptions' => [
                ['pair' => 'locations.read', 'lifts' => 'x', 'inForce' => false, 'reason' => null, 'by' => null, 'at' => null],
                ['pair' => 'stations.configure', 'lifts' => 'x', 'inForce' => true, 'reason' => 'Because.', 'by' => 'A Person', 'at' => new \DateTimeImmutable('2026-10-03 10:18')],
            ],
            'notHonoured' => ['positions.configure'],
            'marks' => ['locked' => true, 'vacant' => true, 'exceptionNotInForce' => true, 'notHonoured' => true],
        ]);
        $listed = self::page([$row])['listed'][0];

        self::assertSame([
            ['text' => 'locked', 'warn' => false],
            ['text' => 'vacant since 14 Aug', 'warn' => true],
            ['text' => 'exception not in force', 'warn' => true],
            ['text' => 'a permission not honoured', 'warn' => true],
        ], $listed['marks']);
        self::assertSame(['vacant', 'exception', 'locked', 'honoured', 'area'], $listed['flags']);
        self::assertSame([true, false, true], array_column($listed['notes'], 'alarm'));
        self::assertSame('held, NOT in force: no written reason, so the voter refuses it', $listed['notes'][0]['text']);
        self::assertSame(['locations', 'read'], [$listed['notes'][0]['label'], $listed['notes'][0]['verb']]);
        self::assertSame('A Person', $listed['notes'][1]['by']);
        self::assertSame('stored, not honoured: only the tiers hold it', $listed['notes'][2]['text']);
    }

    public function testThePillsAndTheConcernsCountTheRows(): void
    {
        $page = self::page([
            self::position('A', 1, [['group' => 'Surveys', 'concerns' => [['key' => 'surveys', 'label' => 'Surveys', 'sensitive' => false, 'lifts' => null, 'verbs' => ['read']]], 'line' => '']], ['allowedKinds' => ['organization']]),
            self::position('B', 0, [], ['marks' => ['locked' => false, 'vacant' => true, 'exceptionNotInForce' => false, 'notHonoured' => false]]),
        ]);

        self::assertSame(['all' => 2, 'vacant' => 1, 'exception' => 0, 'locked' => 0, 'honoured' => 0, 'organization' => 1, 'area' => 1], array_column($page['pills'], 'count', 'key'));
        self::assertSame([null, 'Surveys', 'Ground'], array_column($page['concernMenu'], 'label'), 'Any resource stands under no head');
        self::assertSame([['value' => '', 'label' => 'Any resource', 'count' => 2]], $page['concernMenu'][0]['options']);
        self::assertSame([1, 0], [$page['concernMenu'][1]['options'][0]['count'], $page['concernMenu'][2]['options'][0]['count']]);
        self::assertSame([['value' => 'holders', 'label' => 'Most holders first'], ['value' => 'name', 'label' => 'By name']], $page['sortMenu'][0]['options']);
    }

    public function testTheTiersAreCountedAndNamed(): void
    {
        $page = self::page([]);

        self::assertSame('· 2 tiers · 3 people · not rows in the list', $page['tierSource']);
        self::assertSame(['One', 'Three', 'Two'], array_column($page['above'], 'name'), 'Super Admins first, then by name');
        self::assertSame(['u-1', 'u-3', 'u-2'], array_column($page['above'], 'uuid'));
        self::assertSame(['Super Admin', 'Admin', 'Admin'], array_column($page['above'], 'tier'));
        self::assertSame(['t-super', 't-admin', 't-admin'], array_column($page['above'], 'tierClass'));
        self::assertSame(['the whole organization', 'everywhere, by tier', 'Kilimani + Ziwa Kavu'], array_column($page['above'], 'placed'));
        self::assertSame(['{"tier":["super_admin"]}', '{"tier":["admin"]}', '{"tier":["admin"]}'], array_column($page['above'], 'f'));
        self::assertSame(['one', 'three', 'two'], array_column($page['above'], 'q'));

        $reg = $page['aboveRegister'];
        self::assertSame(['id' => 'above', 'noun' => 'people', 'one' => 'person', 'count' => 3, 'search' => 'Find a person', 'pages' => 1], array_diff_key($reg, ['chips' => true]));
        self::assertSame([[
            'axis' => 'tier', 'value' => 'all · 3', 'on' => false, 'aria' => 'Filter by tier', 'sep' => 1,
            'options' => [
                ['value' => '', 'label' => 'All', 'count' => 3, 'on' => true],
                ['value' => 'super_admin', 'label' => 'Super Admin', 'count' => 1, 'on' => false],
                ['value' => 'admin', 'label' => 'Admin', 'count' => 2, 'on' => false],
            ],
        ]], $reg['chips']);
        self::assertSame(['positions · configure'], $page['tierPairs']);
        self::assertSame(0, $page['shown']);
    }

    /**
     * @param list<PositionRow> $positions
     *
     * @return Page
     */
    private static function page(array $positions): array
    {
        return PositionsListing::build([
            'positions' => $positions,
            'tiers' => [
                ['key' => 'super_admin', 'label' => 'Super Admin', 'people' => [['id' => 1, 'uuid' => 'u-1', 'name' => 'One', 'placed' => 'the whole organization']]],
                ['key' => 'admin', 'label' => 'Admin', 'people' => [['id' => 2, 'uuid' => 'u-2', 'name' => 'Two', 'placed' => 'Kilimani + Ziwa Kavu'], ['id' => 3, 'uuid' => 'u-3', 'name' => 'Three', 'placed' => 'everywhere, by tier']]],
            ],
            'tierOnlyPairs' => ['positions.configure'],
            'groups' => ['Surveys', 'Ground'],
        ], [
            'Surveys' => [['key' => 'surveys', 'label' => 'Surveys']],
            'Ground' => [['key' => 'stations', 'label' => 'Stations']],
        ]);
    }

    /**
     * @param Groups               $groups
     * @param array<string, mixed> $with
     *
     * @return PositionRow
     */
    private static function position(string $name, int $holders, array $groups = [], array $with = []): array
    {
        $pairs = [];
        foreach ($groups as $group) {
            foreach ($group['concerns'] as $c) {
                foreach ($c['verbs'] as $verb) {
                    $pairs[] = $c['key'].'.'.$verb;
                }
            }
        }

        /** @var PositionRow $row */
        $row = [...[
            'id' => 1, 'uuid' => '00000000-0000-0000-0000-000000000001', 'name' => $name, 'holders' => $holders, 'seatCount' => null,
            'allowedKinds' => ['area'], 'where' => 'in named areas', 'vacantSince' => null, 'locked' => false, 'retired' => false,
            'pairs' => $pairs, 'groups' => $groups, 'exceptions' => [], 'notHonoured' => [],
            'marks' => ['locked' => false, 'vacant' => false, 'exceptionNotInForce' => false, 'notHonoured' => false],
        ], ...$with];

        return $row;
    }
}
