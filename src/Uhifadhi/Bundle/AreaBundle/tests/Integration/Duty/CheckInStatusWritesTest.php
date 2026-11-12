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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Duty;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiException;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Exception\CheckInStatusException;
use Uhifadhi\Bundle\AreaBundle\Model\CheckInStatusDraft;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;

/**
 * AN AREA'S LIST, WRITTEN: what is stored after a save, what a refused save
 * leaves untouched, and what "used" means — a key some check-in or
 * correction already carries.
 */
#[CoversClass(CheckInStatusService::class)]
#[CoversClass(CheckInStatusRepository::class)]
final class CheckInStatusWritesTest extends DutyWritesTestCase
{
    private const string CLAIM = '3b0c1f2e-5a44-4a1e-9f0e-2c7b1d9e4a10';

    public function testAnAreaThatWasNeverAskedIsListedWithTheFourItStartsWith(): void
    {
        $area = $this->anArea();

        self::assertSame(['at_post', 'unfit', 'outside', 'special'], self::keys($this->statuses()->listedFor($area)));
    }

    public function testASaveRewordsReordersDeactivatesAndAdds(): void
    {
        $area = $this->anArea();
        $this->statuses()->seed($area);

        $this->statuses()->save($area, [
            new CheckInStatusDraft('outside', 'Off park', CheckInStatusKind::WorkingElsewhere, true, false),
            new CheckInStatusDraft('at_post', 'At post', CheckInStatusKind::AtPost, true, false),
            new CheckInStatusDraft('in_court', 'In court', CheckInStatusKind::WorkingElsewhere, true, true),
            new CheckInStatusDraft('unfit', 'Unfit for duty', CheckInStatusKind::NotWorking, false, false),
            new CheckInStatusDraft('special', 'Special assignment', CheckInStatusKind::Special, true, false),
        ]);
        $this->em->clear();
        $area = $this->reloaded($area);

        $offered = $this->statuses()->offeredBy($area);
        self::assertSame(['outside', 'at_post', 'in_court', 'special'], self::keys($offered));
        self::assertSame('Off park', $offered[0]->getLabel());
        self::assertSame(CheckInStatusKind::WorkingElsewhere, $offered[2]->getKind());

        $listed = $this->statuses()->listedFor($area);
        self::assertSame(['outside', 'at_post', 'in_court', 'special', 'unfit'], self::keys($listed), 'the one not offered is kept, after the offered ones');
        self::assertFalse($listed[4]->isActive());
    }

    public function testAKeyACheckInCarriesIsUsedAndItsKindStays(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        $this->service()->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));

        self::assertSame(['at_post'], $this->statuses()->usedKeys($area));

        // Two At a station statuses, so it is the used rule that refuses.
        $this->statuses()->save($area, [new CheckInStatusDraft('gate', 'At the gate', CheckInStatusKind::AtPost, true, true)]);

        try {
            $this->statuses()->save($area, [new CheckInStatusDraft('at_post', 'At post', CheckInStatusKind::Special, true, false)]);
            self::fail('A used status changed kind.');
        } catch (CheckInStatusException $refused) {
            self::assertStringContainsString('has check-ins, so it stays At a station', $refused->getMessage());
        }

        $this->em->clear();
        $stored = $this->statusRepository()->findByKey($this->reloaded($area), 'at_post');
        self::assertInstanceOf(CheckInStatus::class, $stored);
        self::assertSame(CheckInStatusKind::AtPost, $stored->getKind());
    }

    public function testAKeyACorrectionCarriesIsUsedToo(): void
    {
        [$area, $eastgate] = $this->ground();
        $person = $this->aPerson();
        [$checkIn] = $this->service()->claim($area, $person, $this->claimBody(self::CLAIM, $eastgate));
        $this->service()->amend($checkIn, ['corrections' => [[
            'clientRef' => 'c-1',
            'effectiveFrom' => '2026-09-19T09:00:00+03:00',
            'status' => 'outside',
            'note' => 'Escort to the hospital',
        ]]]);

        self::assertSame(['at_post', 'outside'], $this->statuses()->usedKeys($area));
    }

    public function testAnUnusedStatusChangesKind(): void
    {
        $area = $this->anArea();
        $this->statuses()->seed($area);

        $this->statuses()->save($area, [new CheckInStatusDraft('outside', 'Outside the park', CheckInStatusKind::Special, true, false)]);
        $this->em->clear();

        self::assertSame(CheckInStatusKind::Special, $this->statusRepository()->findByKey($this->reloaded($area), 'outside')?->getKind());
    }

    public function testARefusedSaveChangesNothing(): void
    {
        $area = $this->anArea();
        $this->statuses()->seed($area);

        try {
            $this->statuses()->save($area, [
                new CheckInStatusDraft('outside', 'Off park', CheckInStatusKind::WorkingElsewhere, true, false),
                new CheckInStatusDraft('unfit', 'Unfit', CheckInStatusKind::NotWorking, true, true),
            ]);
            self::fail('A taken key was saved.');
        } catch (CheckInStatusException $refused) {
            self::assertSame('unfit', $refused->key);
        }
        $this->em->clear();

        self::assertSame('Outside the park', $this->statusRepository()->findByKey($this->reloaded($area), 'outside')?->getLabel());
        self::assertCount(4, $this->statusRepository()->findBy(['area' => $this->reloaded($area)]));
    }

    public function testASaveMovesTheMomentAHandsetComparesAgainst(): void
    {
        $area = $this->anArea();
        $this->statuses()->seed($area);
        $this->em->getConnection()->executeStatement("UPDATE duty_checkin_status SET created_at = '2026-01-01 00:00:00', updated_at = '2026-01-01 00:00:00'");
        $this->em->clear();
        $area = $this->reloaded($area);
        $before = $this->statuses()->lastChangedFor($area);

        $this->statuses()->save($area, [new CheckInStatusDraft('outside', 'Off park', CheckInStatusKind::WorkingElsewhere, true, false)]);

        self::assertNotNull($before);
        self::assertGreaterThan($before, $this->statuses()->lastChangedFor($area));
    }

    /**
     * A PHONE THAT HAS NOT SYNCED since a status stopped being offered still
     * sends its key, and the check-in it made is real: it is stored, with the
     * status the area still holds.
     */
    public function testAClaimWithAKeyNoLongerOfferedIsAccepted(): void
    {
        [$area] = $this->ground();
        $this->statuses()->seed($area);
        $this->statuses()->save($area, [new CheckInStatusDraft('outside', 'Outside the park', CheckInStatusKind::WorkingElsewhere, false, false)]);

        [$checkIn, $duplicate] = $this->service()->claim($area, $this->aPerson(), $this->claimBody(self::CLAIM, null, status: 'outside'));

        self::assertFalse($duplicate);
        $status = $checkIn->getStatus();
        self::assertInstanceOf(CheckInStatus::class, $status);
        self::assertSame('outside', $status->getKey());
        self::assertFalse($status->isActive());
    }

    /** The same holds for a correction sent from a phone that has not synced. */
    public function testACorrectionWithAKeyNoLongerOfferedIsAccepted(): void
    {
        [$area, $eastgate] = $this->ground();
        [$checkIn] = $this->service()->claim($area, $this->aPerson(), $this->claimBody(self::CLAIM, $eastgate));
        $this->statuses()->save($area, [new CheckInStatusDraft('outside', 'Outside the park', CheckInStatusKind::WorkingElsewhere, false, false)]);

        $this->service()->amend($checkIn, ['corrections' => [[
            'clientRef' => 'c-1',
            'effectiveFrom' => '2026-09-19T09:00:00+03:00',
            'status' => 'outside',
            'note' => 'Escort to the hospital',
        ]]]);

        self::assertSame(['at_post', 'outside'], $this->statuses()->usedKeys($area));
    }

    /** A key the area never had is refused by name, on a check-in and on a correction. */
    public function testAKeyTheAreaNeverHadIsRefused(): void
    {
        [$area, $eastgate] = $this->ground();

        try {
            $this->service()->claim($area, $this->aPerson(), $this->claimBody(self::CLAIM, null, status: 'seconded'));
            self::fail('A claim with a key the area never had was stored.');
        } catch (DutyApiException $refused) {
            self::assertSame(422, $refused->statusCode());
            self::assertStringContainsString('does not offer the status "seconded"', $refused->getMessage());
        }

        [$checkIn] = $this->service()->claim($area, $this->aPerson('Neema', 'Laizer'), $this->claimBody('9d3e2a10-7c41-4f0e-8b2a-1e6f4c9d0b21', $eastgate));
        try {
            $this->service()->amend($checkIn, ['corrections' => [[
                'clientRef' => 'c-1',
                'effectiveFrom' => '2026-09-19T09:00:00+03:00',
                'status' => 'seconded',
            ]]]);
            self::fail('A correction with a key the area never had was stored.');
        } catch (DutyApiException $refused) {
            self::assertSame(422, $refused->statusCode());
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function statuses(): CheckInStatusService
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');

        return $statuses;
    }

    private function statusRepository(): CheckInStatusRepository
    {
        $repository = $this->em->getRepository(CheckInStatus::class);
        self::assertInstanceOf(CheckInStatusRepository::class, $repository);

        return $repository;
    }

    private function reloaded(AreaOfInterest $area): AreaOfInterest
    {
        $fresh = $this->em->find(AreaOfInterest::class, $area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);

        return $fresh;
    }

    /**
     * @param list<CheckInStatus> $statuses
     *
     * @return list<string>
     */
    private static function keys(array $statuses): array
    {
        return array_map(static fn (CheckInStatus $status): string => $status->getKey(), $statuses);
    }
}
