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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Model\RankQuery;
use Uhifadhi\Bundle\TeamBundle\Model\RankRow;

/**
 * THE RANKS REGISTER'S SORT — the house in-column caret: by rank
 * (seniority, the default) or by holders, either way, with the address as
 * the state exactly as the Positions register does it.
 */
#[CoversClass(RankQuery::class)]
final class RankQueryTest extends TestCase
{
    public function testTheDefaultIsSeniorityAscendingAndAnUnknownSortFallsBackToIt(): void
    {
        $query = RankQuery::fromRequest(new Request(['sort' => 'colour', 'dir' => 'sideways']));

        self::assertSame('rank', $query->sort);
        self::assertSame(RankQuery::ASC, $query->direction);
        self::assertSame([], $query->with('q', null), 'the default sort writes nothing into the address');
    }

    public function testHoldersDescendingBreaksTiesBySeniority(): void
    {
        $query = RankQuery::fromRequest(new Request(['sort' => 'holders', 'dir' => 'desc']));
        [$one, $two, $three] = $this->ladder();

        $ordered = $query->order([new RankRow($one, 1, 2), new RankRow($two, 2, 5), new RankRow($three, 3, 2)]);

        self::assertSame([2, 1, 3], array_map(static fn (RankRow $row): int => $row->order, $ordered));
    }

    public function testRankDescendingReadsTheLadderFromTheTop(): void
    {
        $query = RankQuery::fromRequest(new Request(['sort' => 'rank', 'dir' => 'desc']));
        [$one, $two, $three] = $this->ladder();

        $ordered = $query->order([new RankRow($one, 1, 0), new RankRow($two, 2, 0), new RankRow($three, 3, 0)]);

        self::assertSame([3, 2, 1], array_map(static fn (RankRow $row): int => $row->order, $ordered));
    }

    public function testSortedByTogglesTheDirectionOfTheSortedColumnAndStartsAnotherAscending(): void
    {
        $bySeniority = RankQuery::fromRequest(new Request(['q' => 'cr']));
        self::assertSame(['q' => 'cr', 'dir' => 'desc'], $bySeniority->sortedBy('rank'), 'the default column, clicked, turns over');
        self::assertSame(['q' => 'cr', 'sort' => 'holders'], $bySeniority->sortedBy('holders'));

        $byHolders = RankQuery::fromRequest(new Request(['sort' => 'holders', 'dir' => 'desc', 'scale' => 'abc']));
        self::assertSame(['scale' => 'abc', 'sort' => 'holders'], $byHolders->sortedBy('holders'));
        self::assertSame(['scale' => 'abc'], $byHolders->sortedBy('rank'));
        self::assertSame(RankQuery::DESC, $bySeniority->directionFor('rank'));
        self::assertSame(RankQuery::ASC, $byHolders->directionFor('rank'));
    }

    /** @return list<Rank> */
    private function ladder(): array
    {
        $scale = new RankScale();

        return [
            new Rank($scale)->setName('Conservation Ranger I')->setShortCode('CR I'),
            new Rank($scale)->setName('Conservation Ranger II')->setShortCode('CR II'),
            new Rank($scale)->setName('Senior Conservation Ranger')->setShortCode('SCR'),
        ];
    }
}
