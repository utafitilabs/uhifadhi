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
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionCheckService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionEvaluator;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * CHECK IS LIVE: one person, one question, one place, and the walk is the
 * voter's own record of the questions it asked.
 */
#[CoversClass(PermissionCheckService::class)]
#[CoversClass(PermissionEvaluator::class)]
final class PermissionCheckServiceTest extends IntegrationTestCase
{
    use BuildsAnOrganization;
    use RunsPermissions;

    private const string RANGERS = 'Patrol, security and inspection';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    /** §7, a ranger at Mlima Mkuu: refused at the place, placed at Kilimani only. */
    public function testTheRangerIsRefusedWherePlacementDoesNotReach(): void
    {
        $check = $this->check()->checkPair($this->people['Thabo Ndlovu'], 'surveys.record', $this->areaKey('Mlima Mkuu'));

        self::assertFalse($check['allowed']);
        self::assertSame(['kind' => 'pair', 'key' => 'surveys.record', 'label' => 'Surveys · record'], $check['question']);
        self::assertSame(['key' => $this->areaKey('Mlima Mkuu'), 'name' => 'Mlima Mkuu'], $check['place']);
        self::assertSame(['tier', 'position', 'exception', 'placement', 'place'], array_column($check['lines'], 'q'), 'the tier-only step is printed only when it refuses');
        self::assertSame(['skip', 'ok', 'skip', 'ok', 'no'], array_column($check['lines'], 'status'));
        self::assertSame('Staff — the tiers alone are not asked', $check['lines'][0]['text']);
        self::assertSame('Patrol, security and inspection — holds surveys.record', $check['lines'][1]['text']);
        self::assertSame(['kind' => 'position', 'id' => $this->positionId(self::RANGERS), 'uuid' => $this->positions[self::RANGERS]->getUuidString(), 'name' => self::RANGERS], $check['lines'][1]['door']);
        self::assertSame('not a permission that lifts a rule', $check['lines'][2]['text']);
        self::assertSame('Kilimani Game Reserve · Protection Service', $check['lines'][3]['text']);
        self::assertSame('Mlima Mkuu — their placement does not cover it (placed at Kilimani Game Reserve only)', $check['lines'][4]['text']);
        self::assertSame('placement', $check['lines'][4]['door']['kind'] ?? null);
    }

    public function testWhereTheyArePlacedIsTheirFirstAreaAndTheDepartmentLineNamesWhoRunsIt(): void
    {
        $this->runNow();
        $check = $this->check()->checkPair($this->people['Thabo Ndlovu'], 'surveys.record', null);

        self::assertTrue($check['allowed']);
        self::assertSame('Kilimani Game Reserve', $check['place']['name'] ?? null);
        $last = $check['lines'][\count($check['lines']) - 1];
        self::assertSame(['department', 'ok', 'Protection Service runs Surveys'], [$last['q'], $last['status'], $last['text']]);
        self::assertSame('department', $last['door']['kind'] ?? null);
        self::assertSame(['total' => 3, 'byPosition' => [['position' => self::RANGERS, 'count' => 3]]], array_diff_key($check['coverage'] ?? [], ['asOf' => 0]));
    }

    public function testADepartmentThatRunsNothingIsTheRefusal(): void
    {
        $check = $this->check()->checkPair($this->people['Neema Swai'], 'surveys.record', null);

        $last = $check['lines'][\count($check['lines']) - 1];
        self::assertSame(['department', 'no'], [$last['q'], $last['status']]);
        self::assertSame('Surveys is run by Protection Service, and they serve Ecology and Monitoring', $last['text']);
        self::assertSame('Ecology and Monitoring', $last['door']['name'] ?? null);
    }

    public function testATierIsAboveTheMatrix(): void
    {
        $check = $this->check()->checkPair($this->people['Desta Haile'], 'positions.configure', 'organization');

        self::assertTrue($check['allowed']);
        self::assertSame([['q' => 'tier', 'status' => 'ok', 'text' => 'Admin — above the matrix; nothing else is asked']], array_map(static fn (array $l): array => array_diff_key($l, ['door' => 0]), $check['lines']));
    }

    public function testTheRefusalsInWords(): void
    {
        $tierOnly = $this->check()->checkPair($this->people['Mariam Temba'], 'positions.configure', 'organization');
        self::assertSame('only Admins and Super Admins hold positions.configure; no position does', $this->last($tierOnly)['text']);

        $none = $this->check()->checkPair($this->people['Frank Massawe'], 'stations.read', 'organization');
        self::assertSame(['position', 'holds no position', 'person'], [$this->last($none)['q'], $this->last($none)['text'], $this->last($none)['door']['kind'] ?? null]);
        self::assertSame('organization', $none['place']['key'] ?? null);

        $notHeld = $this->check()->checkPair($this->people['Thabo Ndlovu'], 'stations.configure', null);
        self::assertSame(self::RANGERS.' — does not hold stations.configure', $this->last($notHeld)['text']);

        $rhino = $this->check()->checkPair($this->people['Elias Mdee'], 'locations.read', 'organization');
        self::assertSame('exception', $this->last($rhino)['q']);
        self::assertStringStartsWith('locations.read lifts ', $this->last($rhino)['text']);
        self::assertStringEndsWith(', and no written reason is in force', $this->last($rhino)['text']);

        $grace = $this->check()->checkPair($this->people['Grace Ndosi'], 'locations.read', 'organization');
        self::assertSame('in force — Naomi Kileo, 3 Oct 2026: “Runs every operation across the four areas.”', $grace['lines'][2]['text']);
        self::assertSame('the whole organization — placed across it', $this->line($grace, 'place')['text']);

        $chiku = $this->check()->checkPair($this->people['Chiku Nnko'], 'stations.read', null);
        self::assertSame('their placement reaches nothing — no ground', $this->last($chiku)['text']);
        $bakari = $this->check()->checkPair($this->people['Bakari Chuwa'], 'stations.read', null);
        self::assertSame('their placement reaches nothing — no department', $this->last($bakari)['text']);

        $juma = $this->check()->checkPair($this->people['Juma Mrema'], 'stations.read', 'organization');
        self::assertSame('the whole organization — their placement covers Sinde Flats only', $this->last($juma)['text']);
    }

    /** Check agrees with the vote, for every fixture person, pair and place. */
    public function testCheckAgreesWithTheVoteEverywhere(): void
    {
        $pairs = $this->service(ConcernCatalogue::class)->pairs();
        $places = ['organization', ...array_map(fn (string $a): string => $this->areaKey($a), array_keys($this->areas))];
        foreach ($this->people as $name => $person) {
            foreach ($pairs as $pair) {
                foreach ($places as $place) {
                    $check = $this->check()->checkPair($person, $pair, $place);
                    self::assertNotContains('rule', array_column($check['lines'], 'q'), $name.' · '.$pair.' · '.$place);
                    self::assertSame($check['allowed'], 'ok' === $this->last($check)['status'] || 'skip' === $this->last($check)['status'], $name.' · '.$pair);
                }
            }
        }
    }

    public function testAPowerIsTheTierLineThenThePairsThenTheOwnersRule(): void
    {
        $ranger = $this->check()->checkPower($this->people['Thabo Ndlovu'], 'make-admin', 'a-colleague');

        self::assertFalse($ranger['allowed']);
        self::assertSame(['kind' => 'power', 'key' => 'make-admin', 'label' => 'make Admin'], $ranger['question']);
        self::assertSame('a-colleague', $ranger['target']['kind']);
        self::assertNotNull($ranger['target']['name'], 'a real colleague was found, as the ledger finds one');
        self::assertSame(['tier', 'rule'], array_column($ranger['lines'], 'q'));
        self::assertSame('no', $this->last($ranger)['status']);

        $super = $this->check()->checkPower($this->people['Naomi Kileo'], 'make-admin', 'a-colleague');
        self::assertTrue($super['allowed']);
        self::assertSame(['tier', 'ok'], [$super['lines'][0]['q'], $super['lines'][0]['status']]);
        self::assertSame(['rule', 'ok'], [$this->last($super)['q'], $this->last($super)['status']]);
    }

    public function testAPowerWithNoTargetOfItsKindSaysSo(): void
    {
        $check = $this->check()->checkPower($this->people['Grace Ndosi'], 'make-admin', 'somebody-beyond-their-area');

        self::assertFalse($check['allowed']);
        self::assertSame('no-target', $check['verdict']);
        self::assertNull($check['target']['name']);
    }

    public function testCheckTakesAPairOrAPowerByItsKey(): void
    {
        self::assertSame('pair', $this->check()->check($this->people['Thabo Ndlovu'], 'surveys.record', null)['question']['kind']);
        self::assertSame('power', $this->check()->check($this->people['Thabo Ndlovu'], 'make-admin', 'a-colleague')['question']['kind']);
    }

    /** The lens: only what the voter reads of the ranger at Mlima Mkuu, then the whole catalogue asked there. */
    public function testTheRangerAtAPlaceTheirPlacementDoesNotReach(): void
    {
        $lens = $this->check()->atPlace($this->people['Thabo Ndlovu'], 'surveys.record', $this->areaKey('Mlima Mkuu'));

        self::assertSame(['Thabo Ndlovu', ['key' => $this->areaKey('Mlima Mkuu'), 'name' => 'Mlima Mkuu'], false, false, 'Staff'], [$lens['person'], $lens['place'], $lens['placeIsOwn'], $lens['tierHoldsAll'], $lens['tierLabel']]);
        self::assertSame(['covers' => false, 'how' => 'not', 'areas' => ['Kilimani Game Reserve']], $lens['cover']);
        self::assertSame(['module' => 'Surveys', 'served' => ['Protection Service'], 'running' => ['Protection Service'], 'hit' => 'Protection Service'], $lens['department']);
        self::assertSame('none', $lens['exception']['state']);

        $pairs = $this->service(ConcernCatalogue::class)->pairs();
        self::assertSame(\count($pairs), $lens['catalogueCount']);
        self::assertCount(\count($pairs), $lens['everything']);
        self::assertSame(0, $lens['allowedHere']);
        $asked = array_values(array_filter($lens['everything'], static fn (array $r): bool => $r['asked']));
        self::assertSame([['pair' => 'surveys.record', 'concern' => 'surveys', 'asked' => true, 'allowed' => false, 'step' => 'place', 'line' => 'Mlima Mkuu — their placement does not cover it (placed at Kilimani Game Reserve only)', 'held' => true, 'group' => 'place']], $asked);
        $order = ['allowed', 'exception', 'sensitive', 'placement', 'place', 'department', 'tier-only', 'not-held'];
        $groups = array_map(static fn (array $r): int => (int) array_search($r['group'], $order, true), $lens['everything']);
        $sorted = $groups;
        sort($sorted);
        self::assertSame($sorted, $groups, 'allowed first, then the refusals in the voter\'s step order');
        self::assertContains('tier-only', array_column($lens['everything'], 'group'));
        self::assertContains('not-held', array_column($lens['everything'], 'group'));
    }

    public function testEverythingHereAgreesWithTheVote(): void
    {
        foreach (['Thabo Ndlovu', 'Grace Ndosi', 'Elias Mdee', 'Neema Swai', 'Frank Massawe'] as $name) {
            foreach (['organization', $this->areaKey('Kilimani Game Reserve'), $this->areaKey('Ziwa Kavu')] as $place) {
                $lens = $this->check()->atPlace($this->people[$name], 'surveys.read', $place);
                foreach ($lens['everything'] as $row) {
                    self::assertSame($this->check()->checkPair($this->people[$name], $row['pair'], $place)['allowed'], $row['allowed'], $name.' · '.$row['pair'].' · '.$place);
                }
                self::assertSame(\count(array_filter($lens['everything'], static fn (array $r): bool => $r['allowed'])), $lens['allowedHere']);
            }
        }
    }

    public function testTheExceptionAsTheVoterReadsIt(): void
    {
        $grace = $this->check()->atPlace($this->people['Grace Ndosi'], 'locations.read', $this->areaKey('Mlima Mkuu'));
        self::assertSame(['in-force', 'locations.read', true], [$grace['exception']['state'], $grace['exception']['pair'], $grace['exception']['appliesHere']]);
        self::assertSame('Naomi Kileo', $grace['exception']['by']);
        self::assertSame(['covers' => true, 'how' => 'organization', 'areas' => []], $grace['cover']);
        self::assertSame(['module' => null, 'served' => ['Protection Service', 'Ecology and Monitoring'], 'running' => [], 'hit' => null], $grace['department']);

        $elias = $this->check()->atPlace($this->people['Elias Mdee'], 'surveys.read', 'organization');
        self::assertSame(['not-in-force', 'locations.read', false], [$elias['exception']['state'], $elias['exception']['pair'], $elias['exception']['appliesHere']]);
        $row = array_column($elias['everything'], null, 'pair')['locations.read'];
        self::assertSame(['exception', 'exception', false], [$row['group'], $row['step'], $row['allowed']]);

        $frank = $this->check()->atPlace($this->people['Frank Massawe'], 'surveys.read', 'organization');
        self::assertSame(['covers' => false, 'how' => 'nothing', 'areas' => []], $frank['cover']);
    }

    public function testATierHoldsEverythingAndAPowerIsAskedWhereTheyArePlaced(): void
    {
        $desta = $this->check()->atPlace($this->people['Desta Haile'], 'surveys.record', 'organization');
        self::assertSame([true, 'Admin', []], [$desta['tierHoldsAll'], $desta['tierLabel'], $desta['everything']]);

        $power = $this->check()->atPlace($this->people['Thabo Ndlovu'], 'make-admin', 'a-colleague');
        self::assertSame([true, ['key' => $this->areaKey('Kilimani Game Reserve'), 'name' => 'Kilimani Game Reserve'], null], [$power['placeIsOwn'], $power['place'], $power['department']]);
        self::assertSame(['key' => 'organization', 'name' => 'the whole organization'], $this->check()->ownPlace($this->people['Frank Massawe']), 'placed nowhere: the whole organization');
    }

    public function testThePeopleThePlacesThePersonAndTheQuestion(): void
    {
        $people = $this->check()->people();
        self::assertSame(['Super Admin', 'Admin', 'Staff'], array_column($people, 'label'));
        $staff = array_column($people[2]['people'], null, 'name');
        self::assertArrayNotHasKey('Kito Lema', $staff, 'active people only');
        self::assertSame(['id' => (int) $this->people['Thabo Ndlovu']->getId(), 'name' => 'Thabo Ndlovu', 'position' => self::RANGERS], $staff['Thabo Ndlovu']);

        self::assertSame(['organization', 'Kilimani Game Reserve', 'Mlima Mkuu', 'Sinde Flats', 'Ziwa Kavu'], array_map(static fn (array $p): string => 'organization' === $p['key'] ? 'organization' : $p['name'], $this->check()->places()));

        $grace = $this->check()->aboutPerson($this->people['Grace Ndosi']);
        self::assertSame(['Staff', false, 'Head of Operations', 'Protection Service', ['Ecology and Monitoring'], 'in-force'], [$grace['tier'], $grace['above'], $grace['position']['name'] ?? null, $grace['department'], $grace['supports'], $grace['exception']['state']]);

        $pair = $this->check()->aboutQuestion('surveys.record', null);
        if ('pair' !== $pair['kind']) {
            self::fail('a pair is asked about as a pair');
        }
        self::assertSame(['pair', 'Surveys', false, 2, 6], [$pair['kind'], $pair['module'], $pair['tierOnly'], $pair['positions'], $pair['people']], 'the ranger and the station head; five active rangers and the station head');
        self::assertSame(['read', 'record'], array_column(array_filter($pair['verbs'], static fn (array $v): bool => \in_array($v['verb'], ['read', 'record'], true)), 'verb'));
        self::assertTrue(array_column($pair['verbs'], 'asked', 'verb')['record']);

        $power = $this->check()->aboutQuestion('make-admin', 'a-colleague');
        if ('power' !== $power['kind']) {
            self::fail('a power is asked about as a power');
        }
        self::assertSame(['power', 'make Admin', 'a colleague in their area'], [$power['kind'], $power['label'], $power['target']]);
    }

    /**
     * @param array{lines: list<array{q: string, status: string, text: string, door: array<string, mixed>|null}>} $check
     *
     * @return array{q: string, status: string, text: string, door: array<string, mixed>|null}
     */
    private function last(array $check): array
    {
        return $check['lines'][\count($check['lines']) - 1];
    }

    /**
     * @param array{lines: list<array{q: string, status: string, text: string, door: array<string, mixed>|null}>} $check
     *
     * @return array{q: string, status: string, text: string, door: array<string, mixed>|null}
     */
    private function line(array $check, string $q): array
    {
        foreach ($check['lines'] as $line) {
            if ($q === $line['q']) {
                return $line;
            }
        }

        throw new \LogicException('no '.$q.' line');
    }

    private function check(): PermissionCheckService
    {
        return $this->service(PermissionCheckService::class);
    }
}
