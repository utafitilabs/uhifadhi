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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Exception\CheckInStatusException;
use Uhifadhi\Bundle\AreaBundle\Model\CheckInStatusDraft;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;

/**
 * THE RULES AN AREA'S LIST IS SAVED UNDER, judged on the list as a whole:
 * the statuses as stored, the keys check-ins already carry, and the list the
 * editor sent.
 */
#[CoversClass(CheckInStatusService::class)]
#[CoversClass(CheckInStatusException::class)]
#[CoversClass(CheckInStatusDraft::class)]
final class CheckInStatusRulesTest extends TestCase
{
    /** @var list<CheckInStatus> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->stored = [
            self::stored('at_post', 'At post', CheckInStatusKind::AtPost),
            self::stored('unfit', 'Unfit for duty', CheckInStatusKind::NotWorking),
            self::stored('outside', 'Outside the park', CheckInStatusKind::WorkingElsewhere),
            self::stored('escort', 'On escort', CheckInStatusKind::WorkingElsewhere),
            self::stored('standby', 'Standby at HQ', CheckInStatusKind::WorkingElsewhere, false),
        ];
    }

    public function testRewordingReorderingAndDeactivatingAreSaved(): void
    {
        $this->assertSaves([
            self::kept('outside', 'Off park', CheckInStatusKind::WorkingElsewhere),
            self::kept('at_post', 'At post', CheckInStatusKind::AtPost),
            self::kept('unfit', 'Unfit for duty', CheckInStatusKind::NotWorking, false),
        ]);
    }

    public function testANewStatusWithAWellFormedKeyIsSaved(): void
    {
        $this->assertSaves([self::added('in_court', 'In court', CheckInStatusKind::WorkingElsewhere)]);
        $this->assertSaves([self::added('a'.str_repeat('b', 31), 'Long', CheckInStatusKind::Special)]);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedKeys(): iterable
    {
        yield 'a capital' => ['Court'];
        yield 'a leading digit' => ['1court'];
        yield 'a hyphen' => ['in-court'];
        yield 'a space' => ['in court'];
        yield 'empty' => [''];
        yield 'thirty-three characters' => ['a'.str_repeat('b', 32)];
        yield 'a leading underscore' => ['_court'];
    }

    #[DataProvider('malformedKeys')]
    public function testAMalformedKeyIsRefused(string $key): void
    {
        $refusal = $this->refusalOf([self::added($key, 'In court', CheckInStatusKind::WorkingElsewhere)]);

        self::assertStringContainsString('lower-case letters, digits and _', $refusal->getMessage());
    }

    public function testAKeyTheAreaAlreadyHoldsIsRefusedEvenWhenItIsNotOffered(): void
    {
        $offered = $this->refusalOf([self::added('escort', 'Escort duty', CheckInStatusKind::WorkingElsewhere)]);
        self::assertSame('Not saved — another save took the key escort first. Choose another key; nothing else was lost.', $offered->getMessage());
        self::assertSame('escort', $offered->key);

        $notOffered = $this->refusalOf([self::added('standby', 'Standby', CheckInStatusKind::NotWorking)]);
        self::assertSame('standby', $notOffered->key);
    }

    public function testTwoNewStatusesWithOneKeyAreRefused(): void
    {
        $refusal = $this->refusalOf([
            self::added('court', 'Court', CheckInStatusKind::WorkingElsewhere),
            self::added('court', 'In court', CheckInStatusKind::WorkingElsewhere),
        ]);

        self::assertSame('court', $refusal->key);
        self::assertStringContainsString('two new statuses send court', $refusal->getMessage());
    }

    public function testAKeyTheAreaDoesNotHoldCannotBeEditedAsThoughItDid(): void
    {
        $refusal = $this->refusalOf([self::kept('seconded', 'Seconded', CheckInStatusKind::WorkingElsewhere)]);

        self::assertStringContainsString('no status of this area sends seconded', $refusal->getMessage());
    }

    public function testAStatusWithoutALabelIsRefused(): void
    {
        self::assertStringContainsString('needs a label', $this->refusalOf([self::kept('outside', '   ', CheckInStatusKind::WorkingElsewhere)])->getMessage());
        self::assertStringContainsString('needs a label', $this->refusalOf([self::added('court', '', CheckInStatusKind::WorkingElsewhere)])->getMessage());
    }

    public function testALabelLongerThanTheColumnIsRefused(): void
    {
        self::assertStringContainsString('64 characters', $this->refusalOf([self::kept('outside', str_repeat('x', 65), CheckInStatusKind::WorkingElsewhere)])->getMessage());
    }

    public function testANewStatusWithoutAKindIsRefused(): void
    {
        self::assertStringContainsString('“Court” needs a kind', $this->refusalOf([self::added('court', 'Court', null)])->getMessage());
    }

    public function testAUsedStatusKeepsItsKind(): void
    {
        $refusal = $this->refusalOf([self::kept('outside', 'Outside the park', CheckInStatusKind::Special)], ['outside']);

        self::assertSame('Not saved — “Outside the park” has check-ins, so it stays Working elsewhere. Add a new status to mean something else; nothing else was lost.', $refusal->getMessage());
    }

    public function testAnUnusedStatusMayChangeKind(): void
    {
        $this->assertSaves([self::kept('escort', 'On escort', CheckInStatusKind::Special)], ['outside']);
    }

    public function testAStatusNotOfferedIsFrozenUntilItIsReactivated(): void
    {
        self::assertStringContainsString('“Standby at HQ” is not offered', $this->refusalOf([self::kept('standby', 'Standby', CheckInStatusKind::WorkingElsewhere, false)])->getMessage());
        self::assertStringContainsString('is not offered', $this->refusalOf([self::kept('standby', 'Standby at HQ', CheckInStatusKind::NotWorking, false)])->getMessage());

        $this->assertSaves([self::kept('standby', 'Standby', CheckInStatusKind::NotWorking)]);
    }

    public function testTheLastAtAStationStatusStaysOffered(): void
    {
        $sentence = 'Not saved — at least one At a station status stays offered. Nothing else was lost.';

        self::assertSame($sentence, $this->refusalOf([self::kept('at_post', 'At post', CheckInStatusKind::AtPost, false)])->getMessage());
        self::assertSame($sentence, $this->refusalOf([self::kept('at_post', 'At post', CheckInStatusKind::Special)])->getMessage());

        // A second one offered first lets the first go.
        $this->assertSaves([
            self::added('gate', 'At the gate', CheckInStatusKind::AtPost),
            self::kept('at_post', 'At post', CheckInStatusKind::AtPost, false),
        ]);
    }

    public function testAStatusTheListLeavesOutIsKeptAsStored(): void
    {
        // Another save added a status this page never saw: leaving it out is
        // not deleting it, and it still holds the area's only At a station.
        $this->assertSaves([self::kept('unfit', 'Unfit', CheckInStatusKind::NotWorking)]);
    }

    public function testAStatusSentTwiceIsRefused(): void
    {
        $refusal = $this->refusalOf([
            self::kept('outside', 'Outside the park', CheckInStatusKind::WorkingElsewhere),
            self::kept('outside', 'Off park', CheckInStatusKind::WorkingElsewhere),
        ]);

        self::assertStringContainsString('outside is sent twice', $refusal->getMessage());
    }

    public function testAPostedFormBecomesDrafts(): void
    {
        $drafts = $this->service()->draftsFrom([
            ['key' => 'at_post', 'label' => ' At post ', 'kind' => 'at_post', 'active' => '1'],
            ['key' => 'court', 'label' => 'Court', 'kind' => 'nonsense', 'active' => '1', 'new' => '1'],
            ['key' => 'standby', 'label' => 'Standby at HQ', 'kind' => 'working_elsewhere', 'active' => '0'],
            'not a row',
        ]);

        self::assertEquals([
            new CheckInStatusDraft('at_post', 'At post', CheckInStatusKind::AtPost, true, false),
            new CheckInStatusDraft('court', 'Court', null, true, true),
            new CheckInStatusDraft('standby', 'Standby at HQ', CheckInStatusKind::WorkingElsewhere, false, false),
        ], $drafts);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<CheckInStatusDraft> $drafts
     * @param list<string>             $used
     */
    private function assertSaves(array $drafts, array $used = []): void
    {
        $this->service()->check($this->stored, $used, $drafts);
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<CheckInStatusDraft> $drafts
     * @param list<string>             $used
     */
    private function refusalOf(array $drafts, array $used = []): CheckInStatusException
    {
        try {
            $this->service()->check($this->stored, $used, $drafts);
        } catch (CheckInStatusException $refused) {
            return $refused;
        }

        self::fail('The list was saved; a refusal was expected.');
    }

    private function service(): CheckInStatusService
    {
        return new CheckInStatusService($this->createStub(EntityManagerInterface::class), $this->createStub(CheckInStatusRepository::class));
    }

    private static function stored(string $key, string $label, CheckInStatusKind $kind, bool $active = true): CheckInStatus
    {
        return new CheckInStatus()->setKey($key)->setLabel($label)->setKind($kind)->setActive($active);
    }

    private static function kept(string $key, string $label, CheckInStatusKind $kind, bool $active = true): CheckInStatusDraft
    {
        return new CheckInStatusDraft($key, $label, $kind, $active, false);
    }

    private static function added(string $key, string $label, ?CheckInStatusKind $kind): CheckInStatusDraft
    {
        return new CheckInStatusDraft($key, $label, $kind, true, true);
    }
}
