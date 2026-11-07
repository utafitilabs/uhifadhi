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
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionAnalysisReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PlaceKey;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * ANALYSIS READS ONE QUESTION'S STORED ANSWERS: places across, positions
 * down, a number of people in each cell, and the tiers in one line above.
 */
#[CoversClass(PermissionAnalysisReader::class)]
#[CoversClass(PlaceKey::class)]
final class PermissionAnalysisReaderTest extends IntegrationTestCase
{
    use BuildsAnOrganization;
    use RunsPermissions;

    private const string RANGERS = 'Patrol, security and inspection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    public function testWithNoRunEveryListIsEmptyAndNothingIsFiled(): void
    {
        $analysis = $this->reader()->forPair('surveys.record');

        self::assertNull($analysis['asOf']);
        self::assertFalse($analysis['filed']);
        self::assertSame([], $analysis['places']);
        self::assertSame([], $analysis['rows']);
        self::assertSame('Surveys · record', $analysis['label']);
    }

    /** §7, a ranger: three in Kilimani and one in Sinde Flats on the ranger row, nothing elsewhere. */
    public function testThePlacesRunAcrossAndThePositionsDownByMostPeople(): void
    {
        $run = $this->runNow();
        $analysis = $this->reader()->forPair('surveys.record');

        self::assertTrue($analysis['filed']);
        self::assertEquals($run->getStartedAt(), $analysis['asOf']);
        self::assertSame(['organization', $this->areaKey('Kilimani Game Reserve'), $this->areaKey('Mlima Mkuu'), $this->areaKey('Sinde Flats'), $this->areaKey('Ziwa Kavu')],
            array_column($analysis['places'], 'key'));
        self::assertSame('the whole organization', $analysis['places'][0]['name']);

        self::assertSame([self::RANGERS, 'Station head'], array_column($analysis['rows'], 'position'), 'most people first; the ranger in Ecology passes nowhere');
        $ranger = $analysis['rows'][0];
        self::assertSame($this->positionId(self::RANGERS), $ranger['positionId']);
        self::assertSame(5, $ranger['holders'], 'the active Staff holders; the deactivated ranger is not one');
        self::assertSame(3, $ranger['counts'][$this->areaKey('Kilimani Game Reserve')]);
        self::assertSame(1, $ranger['counts'][$this->areaKey('Sinde Flats')]);
        self::assertSame(0, $ranger['counts'][$this->areaKey('Mlima Mkuu')]);
        self::assertSame(0, $ranger['counts']['organization']);
        self::assertSame(3, $ranger['anywhere']);
        self::assertSame(1, $analysis['totals'][$this->areaKey('Ziwa Kavu')]);
        self::assertSame([['key' => 'super_admin', 'label' => 'Super Admin', 'count' => 1], ['key' => 'admin', 'label' => 'Admin', 'count' => 1]], $analysis['tiers']);
    }

    /** §7, the head of operations: one row, one person in every column. */
    public function testAnOrganizationWideHolderIsInEveryColumnOfTheRow(): void
    {
        $this->runNow();
        $analysis = $this->reader()->forPair('locations.read');

        self::assertSame(['Head of Operations', 'Rhino coordinator'], array_column($analysis['rows'], 'position'), 'the Rhino coordinator holds it with no reason in force: a row, and empty');
        self::assertSame([1, 1, 1, 1, 1], array_values($analysis['rows'][0]['counts']));
        self::assertSame([0, 0, 0, 0, 0], array_values($analysis['rows'][1]['counts']));
        self::assertSame([1, 0], array_column($analysis['rows'], 'people'), 'the distinct people behind the row');
    }

    public function testRevokingTheReasonEmptiesTheRowWithNoDrift(): void
    {
        $this->runNow();
        $positions = $this->service(PositionService::class);
        $positions->revokeException($this->positions['Head of Operations'], 'locations.read', $this->people['Naomi Kileo']);
        $second = $this->runNow();

        $rows = $this->reader()->forPair('locations.read')['rows'];
        self::assertNotSame([], $rows);
        self::assertSame([0], array_unique(array_column($rows, 'people')), 'a row still holds it; nobody passes');
        self::assertSame([], $this->service(PermissionDriftRepository::class)->findByRun($second), 'a history line explains it');
    }

    public function testATierOnlyPairIsFiledForNobody(): void
    {
        $this->runNow();
        $analysis = $this->reader()->forPair('positions.configure');

        self::assertTrue($analysis['filed']);
        self::assertSame(['People officer'], array_column($analysis['rows'], 'position'), 'the People officer stores it');
        self::assertSame(0, $analysis['rows'][0]['people'], 'it is honoured nowhere');
    }

    public function testThePeopleInACellAreReadFromTheStoredIds(): void
    {
        $this->runNow();

        $people = $this->reader()->peopleIn('surveys.record', $this->areaKey('Kilimani Game Reserve'), $this->positionId(self::RANGERS));

        self::assertSame(['Imani Laizer', 'Omari Mollel', 'Thabo Ndlovu'], array_column($people, 'name'));
        self::assertSame($this->people['Imani Laizer']->getUuidString(), $people[0]['uuid']);
        self::assertSame([], $this->reader()->peopleIn('surveys.record', $this->areaKey('Mlima Mkuu'), $this->positionId(self::RANGERS)));
    }

    public function testAPowerIsReadByActorAndTargetKindWithTheQuestionThatRefused(): void
    {
        $this->runNow();
        $power = $this->reader()->forPower('make-admin');

        self::assertSame('make Admin', $power['power']['label']);
        self::assertSame('giving-power', $power['power']['group']);
        self::assertSame(['a-colleague', 'somebody-beyond-their-area'], array_column($power['targets'], 'key'));
        self::assertSame(['tier', 'tier'], \array_slice(array_column($power['rows'], 'kind'), 0, 2), 'the tiers first');
        self::assertSame('Super Admin', $power['rows'][0]['name']);
        self::assertSame('allowed', $power['rows'][0]['cells']['a-colleague']);
        $ranger = array_values(array_filter($power['rows'], static fn (array $r): bool => self::RANGERS === $r['name']))[0];
        self::assertSame('refused', $ranger['cells']['a-colleague']);
        self::assertSame('team.member.tier', $ranger['refusedBy']['a-colleague']);
        self::assertFalse($power['alarm'], 'giving power is no alarm');
    }

    /** Ruled 7 Nov: nobody but a Super Admin gains power; any other yes is the alarm. */
    public function testAGainingPowerIsAnAlarmOnlyWhenSomebodyButASuperAdminHoldsIt(): void
    {
        $this->runNow();

        foreach (['raise-their-own-tier', 'seat-themselves-in-a-stronger-position', 'raise-their-own-rank', 'create-an-account-in-a-stronger-position'] as $key) {
            $power = $this->reader()->forPower($key);
            $others = array_filter($power['rows'], static fn (array $r): bool => 'super_admin' !== $r['tier'] && \in_array('allowed', $r['cells'], true));
            self::assertSame([] !== $others, $power['alarm'], $key);
        }
    }

    public function testTheQuestionListOffersEveryPairAndPowerWithTheAnswersFiled(): void
    {
        $this->runNow();
        $questions = $this->reader()->questions();

        $surveys = array_values(array_filter($questions['concerns'], static fn (array $c): bool => 'surveys' === $c['key']))[0];
        self::assertSame('Surveys', $surveys['label']);
        self::assertSame(['surveys.read', 'surveys.record'], array_column($surveys['verbs'], 'pair'));
        self::assertSame('record', $surveys['verbs'][1]['verb']);
        self::assertSame(3, $surveys['verbs'][1]['answers'], 'three cells: Kilimani, Sinde Flats, Ziwa Kavu');

        $powers = array_column($questions['powers'], null, 'key');
        self::assertArrayHasKey('switch-user', $powers);
        self::assertGreaterThan(0, $powers['switch-user']['answers']);
    }

    public function testThePeopleOfEveryCellAreReadInOneGo(): void
    {
        $this->runNow();

        $cells = $this->reader()->peopleByCell('surveys.record');

        $ranger = $this->positionId(self::RANGERS);
        self::assertSame(['Imani Laizer', 'Omari Mollel', 'Thabo Ndlovu'], array_column($cells[$this->areaKey('Kilimani Game Reserve')][$ranger], 'name'));
        self::assertSame(['Juma Mrema'], array_column($cells[$this->areaKey('Sinde Flats')][$ranger], 'name'));
        self::assertSame(['Rehema Mushi'], array_column($cells[$this->areaKey('Ziwa Kavu')][$this->positionId('Station head')], 'name'));
        self::assertArrayNotHasKey($this->areaKey('Mlima Mkuu'), $cells, 'an empty cell is not stored');
        self::assertSame($this->people['Juma Mrema']->getUuidString(), $cells[$this->areaKey('Sinde Flats')][$ranger][0]['uuid']);
        self::assertSame([], $this->reader()->peopleByCell('positions.configure'));
    }

    public function testTheQuestionListSaysHowManyPositionsHoldEachPairAndHowManyActorsEachPower(): void
    {
        $this->runNow();
        $questions = $this->reader()->questions();

        $surveys = array_values(array_filter($questions['concerns'], static fn (array $c): bool => 'surveys' === $c['key']))[0];
        self::assertSame([4, 2], array_column($surveys['verbs'], 'positions'), 'read: rangers, station head, the two organization-wide; record: rangers and station head');

        $power = $this->reader()->forPower('make-admin');
        $first = $power['targets'][0]['key'];
        $allowed = \count(array_filter($power['rows'], static fn (array $r): bool => 'allowed' === ($r['cells'][$first] ?? null)));
        $powers = array_column($questions['powers'], null, 'key');
        self::assertSame($allowed, $powers['make-admin']['allowed']);
        self::assertSame($power['targets'][0]['label'], $powers['make-admin']['target']);
    }

    public function testEveryPositionHoldingThePairIsARowOrderedByItsPeopleThenName(): void
    {
        $this->positions['Driver']->setGrantValues(['stations.read', 'surveys.record'], ['stations.read', 'surveys.record']);
        $this->positions['Tourism warden']->setGrantValues(['stations.read', 'surveys.record'], ['stations.read', 'surveys.record'])->retire(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();
        $this->runNow();
        $analysis = $this->reader()->forPair('surveys.record');

        self::assertSame([self::RANGERS, 'Station head', 'Driver'], array_column($analysis['rows'], 'position'), 'a retired position is no row');
        self::assertSame([4, 1, 0], array_column($analysis['rows'], 'people'), 'the distinct people, most first');
        self::assertSame(['Ecology and Monitoring', 'ICT', 'Protection Service'], array_column($analysis['departments'], 'name'));
        self::assertSame($this->departments['ICT']->getId(), $analysis['departments'][1]['id']);
    }

    public function testEachPersonBehindACellCarriesTheDepartmentsTheyServe(): void
    {
        $this->runNow();

        $grace = $this->reader()->peopleByCell('locations.read')['organization'][$this->positionId('Head of Operations')][0];
        self::assertSame('Grace Ndosi', $grace['name']);
        self::assertSame([$this->departments['Protection Service']->getId(), $this->departments['Ecology and Monitoring']->getId()], $grace['departments'], 'their own, then those they support');

        $juma = $this->reader()->peopleByCell('surveys.record')[$this->areaKey('Sinde Flats')][$this->positionId(self::RANGERS)][0];
        self::assertSame([$this->departments['Protection Service']->getId()], $juma['departments']);
    }

    public function testTheRecordsARowOpensAreReadByIdWithOnePersonOfEachTier(): void
    {
        $records = $this->reader()->records();

        $head = $this->positions['Head of Operations'];
        self::assertSame($head->getUuidString(), $records['positions'][(int) $head->getId()]);
        $naomi = $this->people['Naomi Kileo'];
        self::assertSame($naomi->getUuidString(), $records['people'][(int) $naomi->getId()]);
        self::assertSame((int) $naomi->getId(), $records['tiers']['super_admin'], 'the first active person of each tier, by name');
        self::assertSame((int) $this->people['Desta Haile']->getId(), $records['tiers']['admin']);
    }

    private function reader(): PermissionAnalysisReader
    {
        return $this->service(PermissionAnalysisReader::class);
    }
}
