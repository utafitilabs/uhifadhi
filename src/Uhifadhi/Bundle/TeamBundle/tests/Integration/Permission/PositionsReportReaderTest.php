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
use Uhifadhi\Bundle\TeamBundle\Permissions\PositionsReportReader;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * POSITIONS IS THE STORED SHAPE OF EACH POSITION, read as written: no voter,
 * no run.
 *
 * @phpstan-import-type PositionRow from PositionsReportReader
 */
#[CoversClass(PositionsReportReader::class)]
final class PositionsReportReaderTest extends IntegrationTestCase
{
    use BuildsAnOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    /** §7, a ranger: the pairs under their concerns' packages, the verbs in words. */
    public function testAPositionsPairsAreGroupedByTheDeclaringPackage(): void
    {
        $ranger = $this->row('Patrol, security and inspection');

        self::assertSame(5, $ranger['holders'], 'the deactivated ranger holds nothing');
        self::assertSame(['area'], $ranger['allowedKinds']);
        self::assertSame('in named areas', $ranger['where']);
        self::assertSame(['Surveys', 'Ground'], array_column($ranger['groups'], 'group'), 'in the order the packages declared them');
        $surveys = $ranger['groups'][0]['concerns'][0];
        self::assertSame(['key' => 'surveys', 'label' => 'Surveys', 'sensitive' => false, 'lifts' => null, 'verbs' => ['read', 'record']], $surveys);
        self::assertSame('Surveys — read, record', $ranger['groups'][0]['line']);
        self::assertSame([], $ranger['exceptions']);
        self::assertFalse($ranger['marks']['vacant']);
    }

    public function testAnExceptionIsInForceOrHeldButNotInForce(): void
    {
        $head = $this->row('Head of Operations');
        self::assertSame('organization-wide', $head['where']);
        self::assertCount(1, $head['exceptions']);
        self::assertTrue($head['exceptions'][0]['inForce']);
        self::assertSame('Naomi Kileo', $head['exceptions'][0]['by']);
        self::assertSame('Runs every operation across the four areas.', $head['exceptions'][0]['reason']);
        self::assertFalse($head['marks']['exceptionNotInForce']);

        $rhino = $this->row('Rhino coordinator');
        self::assertSame([['pair' => 'locations.read', 'lifts' => $rhino['exceptions'][0]['lifts'], 'inForce' => false, 'reason' => null, 'by' => null, 'at' => null]], $rhino['exceptions']);
        self::assertTrue($rhino['marks']['exceptionNotInForce']);
    }

    public function testATierOnlyPairStoredIsNotHonoured(): void
    {
        $officer = $this->row('People officer');

        self::assertSame(['positions.configure'], $officer['notHonoured']);
        self::assertTrue($officer['marks']['notHonoured']);
        self::assertContains('positions.configure', $this->reader()->report()['tierOnlyPairs']);
    }

    public function testAVacantPositionSaysSince(): void
    {
        $warden = $this->row('Tourism warden');

        self::assertSame(0, $warden['holders']);
        self::assertEquals(new \DateTimeImmutable('2026-08-14'), $warden['vacantSince']);
        self::assertTrue($warden['marks']['vacant']);
    }

    public function testTheTiersCardNamesItsHolders(): void
    {
        $tiers = $this->reader()->report()['tiers'];

        self::assertSame(['super_admin', 'admin'], array_column($tiers, 'key'));
        self::assertSame(['Naomi Kileo'], array_column($tiers[0]['people'], 'name'));
        self::assertSame(['Desta Haile'], array_column($tiers[1]['people'], 'name'));
        self::assertSame(['the whole organization'], array_column($tiers[0]['people'], 'placed'), 'where each is placed');
        self::assertSame([$this->people['Naomi Kileo']->getUuidString()], array_column($tiers[0]['people'], 'uuid'));
    }

    public function testTheTotalsAreTheFilterPills(): void
    {
        $this->positions['Driver']->setLocked(true);
        $this->positions['Driver']->retire(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();
        $report = $this->reader()->report();

        self::assertSame(['all' => 6, 'vacant' => 1, 'exception' => 2, 'locked' => 0, 'honoured' => 1, 'organization' => 3, 'area' => 3], $report['totals'], 'a retired position is listed and counted in no pill');
        self::assertTrue($this->row('Driver')['retired']);
        self::assertSame(['Surveys', 'Ground', 'Modules', 'Settings', 'Team'], $report['groups'], 'every declaring package, in the catalogue\'s order');
    }

    /** @return PositionRow */
    private function row(string $name): array
    {
        foreach ($this->reader()->report()['positions'] as $row) {
            if ($name === $row['name']) {
                return $row;
            }
        }

        throw new \LogicException('no row for '.$name);
    }

    private function reader(): PositionsReportReader
    {
        return $this->service(PositionsReportReader::class);
    }
}
