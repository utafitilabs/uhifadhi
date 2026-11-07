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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionOverviewReader;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE HUB READS A HANDFUL OF NUMBERS: the four figures, the four plots, the
 * six lists in one line each and the whole history, newest first.
 */
#[CoversClass(PermissionOverviewReader::class)]
final class PermissionOverviewReaderTest extends IntegrationTestCase
{
    use BuildsAnOrganization;
    use RunsPermissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    public function testTheFiguresAreLiveAndTheFindingsAreTheRunsOwn(): void
    {
        $this->positions['Driver']->setLocked(true);
        $this->em->flush();
        $run = $this->runNow();
        $kpis = $this->reader()->overview()['kpis'];

        self::assertSame(7, $kpis['positions']);
        self::assertSame(1, $kpis['vacant']);
        self::assertSame(1, $kpis['locked']);
        self::assertSame(15, $kpis['people']);
        self::assertSame(13, $kpis['staff']);
        self::assertSame(2, $kpis['above']);
        self::assertSame(1, $kpis['exceptionsInForce']);
        self::assertSame(1, $kpis['notInForce']);
        self::assertSame(4, $kpis['reachNothing']);
        self::assertSame(1, $kpis['notHonoured']);
        self::assertSame(5, $kpis['needsAttention'], 'reach nothing and not honoured');
        self::assertSame(\count($run->getPairs()), $kpis['questionsFiled']);
    }

    public function testThePlots(): void
    {
        $this->runNow();
        $overview = $this->reader()->overview();

        $concerns = array_column($overview['concernsByPositions'], null, 'key');
        self::assertSame(5, $concerns['stations']['positions'], 'every position but the People officer and the Rhino coordinator');
        self::assertSame(71, $concerns['stations']['share']);
        self::assertSame('stations', $overview['concernsByPositions'][0]['key'], 'most first');

        $positions = array_column($overview['positionsByPairs'], null, 'position');
        self::assertSame('Station head', $overview['positionsByPairs'][0]['position'], 'most pairs first');
        self::assertSame(['held' => 2, 'problem' => 1, 'kinds' => ['not in force']], array_diff_key($positions['Rhino coordinator'], ['position' => 0]));
        self::assertSame(['not honoured'], $positions['People officer']['kinds']);

        $places = array_column($overview['whoCanActByPlace'], 'people', 'name');
        self::assertSame(['the whole organization' => 5, 'Kilimani Game Reserve' => 9, 'Mlima Mkuu' => 6, 'Sinde Flats' => 6, 'Ziwa Kavu' => 7], $places,
            'five placed across it; each area adds the people placed there');
    }

    public function testChangesByMonthAreTheLastSixMonthsAndTheHistoryInWords(): void
    {
        $this->service(PermissionChangeLog::class)->record(PermissionChangeEnum::GrantsChanged, $this->positions['Driver'], 'stations.read', 'stations.read, surveys.read', null, $this->people['Naomi Kileo']);
        $this->em->flush();
        $overview = $this->reader()->overview();

        self::assertCount(6, $overview['changesByMonth']);
        $last = $overview['changesByMonth'][5];
        self::assertSame(new \DateTimeImmutable()->format('Y-m'), $last['key']);
        self::assertSame(new \DateTimeImmutable()->format('M'), $last['month']);
        self::assertSame(1, $last['changes']);
        self::assertSame(0, $overview['changesByMonth'][0]['changes']);

        self::assertCount(1, $overview['history']);
        self::assertInstanceOf(ChangeLine::class, $overview['history'][0]);
        self::assertSame('Naomi Kileo', $overview['history'][0]->by);
    }

    public function testTheReviewTableIsTheSixListsInOneLineEach(): void
    {
        $this->runNow();
        $review = array_column($this->reader()->overview()['review'], null, 'id');

        self::assertSame(['reach-nothing', 'not-in-force', 'not-honoured', 'gaining', 'vacant', 'exceptions', 'self-changes'], array_keys($review));
        self::assertFalse($review['self-changes']['alarm']);
        self::assertSame('nobody changed their own tier, rank or position', $review['self-changes']['summary']);
        self::assertSame('Bakari Chuwa, Chiku Nnko, Frank Massawe, Latifa Macha', $review['reach-nothing']['summary']);
        self::assertSame(4, $review['reach-nothing']['count']);
        self::assertStringStartsWith('Rhino coordinator · ', $review['not-in-force']['summary']);
        self::assertSame('Tourism warden, since 14 Aug 2026', $review['vacant']['summary']);
        self::assertStringEndsWith(' — Head of Operations', $review['exceptions']['summary']);
    }

    public function testWithNoRunTheFindingsAreZeroAndTheLiveFiguresStand(): void
    {
        $overview = $this->reader()->overview();

        self::assertSame(0, $overview['kpis']['reachNothing']);
        self::assertSame(15, $overview['kpis']['people']);
        self::assertNull($overview['freshness']['asOf']);
        self::assertSame('nobody below Super Admin can raise themselves', array_column($overview['review'], 'summary', 'id')['gaining']);
    }

    private function reader(): PermissionOverviewReader
    {
        return $this->service(PermissionOverviewReader::class);
    }
}
