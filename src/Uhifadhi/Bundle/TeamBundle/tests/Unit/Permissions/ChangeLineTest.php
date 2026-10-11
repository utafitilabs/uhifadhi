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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;

/**
 * ONE CHANGE AS A LINE READS IT: who did what to whom, and what it was before
 * and after — a grant list as what was added and what was taken away, since a
 * position holding twenty pairs is read by the two that moved.
 */
final class ChangeLineTest extends TestCase
{
    public function testTheSentenceIsSplitAroundWhatItIsAbout(): void
    {
        $line = ChangeLine::of(self::change(PermissionChangeEnum::TierChanged, 'Staff', 'Admin'));

        self::assertSame('Naomi Kileo', $line->by);
        self::assertSame('made', $line->lead);
        self::assertSame('Thabo Ndlovu', $line->on);
        self::assertSame('Admin', $line->tail);
        self::assertSame('tier', $line->kind);
    }

    public function testAValueReplacedReadsAsBeforeAndAfter(): void
    {
        $line = ChangeLine::of(self::change(PermissionChangeEnum::TierChanged, 'Staff', 'Admin'));

        self::assertSame([['was', 'Staff'], ['now', 'Admin']], $line->diff);
    }

    public function testAGrantListReadsAsWhatWasAddedAndWhatWasTakenAway(): void
    {
        $line = ChangeLine::of(self::change(PermissionChangeEnum::GrantsChanged, 'patrols.read, roster.read', 'patrols.read, roster.read, roster.record, incidents.read'));

        self::assertSame([['added', 'incidents.read'], ['added', 'roster.record']], $line->diff);

        $taken = ChangeLine::of(self::change(PermissionChangeEnum::GrantsChanged, 'patrols.read, incidents.record', 'patrols.read'));
        self::assertSame([['removed', 'incidents.record']], $taken->diff);
    }

    public function testSomethingGivenOrTakenReadsAsOneValue(): void
    {
        self::assertSame([['now', 'Live locations']], ChangeLine::of(self::change(PermissionChangeEnum::ExceptionGiven, null, 'Live locations'))->diff);
        self::assertSame([['was', 'Live locations']], ChangeLine::of(self::change(PermissionChangeEnum::ExceptionTaken, 'Live locations', null))->diff);
    }

    public function testEveryChangeNamesWhatItIsAboutAndItsKind(): void
    {
        foreach (PermissionChangeEnum::cases() as $case) {
            self::assertStringContainsString('{on}', $case->phrase(), $case->value.' names what it is about');
            self::assertContains($case->kind(), ['tier', 'position', 'placement', 'account', 'sign-in', 'grants', 'exception', 'department'], $case->value);
        }
    }

    private static function change(PermissionChangeEnum $action, ?string $before, ?string $after): PermissionChange
    {
        return new PermissionChange(
            recordedAt: new \DateTimeImmutable('2026-10-11 09:42'),
            byId: 1,
            byName: 'Naomi Kileo',
            action: $action,
            subjectKind: PermissionChange::PERSON,
            subjectId: 7,
            subjectUuid: '0199c000-0000-7000-8000-000000000007',
            subjectName: 'Thabo Ndlovu',
            before: $before,
            after: $after,
        );
    }
}
