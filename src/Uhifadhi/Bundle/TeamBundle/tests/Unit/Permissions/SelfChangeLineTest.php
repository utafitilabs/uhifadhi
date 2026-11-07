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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;

/**
 * A CHANGE A PERSON MADE TO THEMSELVES SAYS SO: "changed their own rank to
 * Chief Park Warden", never "changed the rank of Naomi Kileo" under Naomi
 * Kileo's own name.
 */
#[CoversClass(ChangeLine::class)]
#[CoversClass(PermissionChangeEnum::class)]
final class SelfChangeLineTest extends TestCase
{
    /** @return iterable<string, array{PermissionChangeEnum, ?string, ?string, string, string}> */
    public static function changes(): iterable
    {
        yield 'tier' => [PermissionChangeEnum::TierChanged, 'Super Admin', 'Admin', 'changed their own tier to Admin', 'made'];
        yield 'seated' => [PermissionChangeEnum::Seated, null, 'Chief warden', 'seated themselves as Chief warden', 'seated'];
        yield 'unseated' => [PermissionChangeEnum::Unseated, 'Chief warden', null, 'unseated themselves', 'unseated'];
        yield 'placed' => [PermissionChangeEnum::Placed, 'placed nowhere', 'the whole organization', 'placed themselves', 'placed'];
        yield 'rank' => [PermissionChangeEnum::RankChanged, 'no rank', 'Chief Park Warden', 'changed their own rank to Chief Park Warden', 'changed the rank of'];
        yield 'email' => [PermissionChangeEnum::EmailChanged, 'a@example.test', 'b@example.test', 'changed their own email', 'changed the email of'];
        yield 'deactivated' => [PermissionChangeEnum::Deactivated, null, null, 'deactivated themselves', 'deactivated'];
        yield 'reset link' => [PermissionChangeEnum::ResetLinkSent, null, null, 'sent themselves a reset link', 'sent'];
    }

    #[DataProvider('changes')]
    public function testAChangeToThemselvesSaysSo(PermissionChangeEnum $action, ?string $before, ?string $after, string $self, string $other): void
    {
        $line = ChangeLine::of(self::change($action, $before, $after, by: 7));

        self::assertSame($self, $line->lead);
        self::assertSame('', $line->on);
        self::assertSame('', $line->tail);
    }

    #[DataProvider('changes')]
    public function testAChangeToSomebodyElseNamesThem(PermissionChangeEnum $action, ?string $before, ?string $after, string $self, string $other): void
    {
        $line = ChangeLine::of(self::change($action, $before, $after, by: 1));

        self::assertSame($other, $line->lead);
        self::assertSame('Thabo Ndlovu', $line->on);
    }

    public function testTheSystemIsNeverThemselves(): void
    {
        $line = ChangeLine::of(self::change(PermissionChangeEnum::Seated, null, 'Ranger', by: null));

        self::assertSame('seated', $line->lead);
    }

    public function testEveryChangeAPersonCanMakeToThemselvesHasItsOwnWords(): void
    {
        foreach (PermissionChangeEnum::cases() as $case) {
            $words = $case->selfPhrase();
            if (null !== $words) {
                self::assertStringNotContainsString('{on}', $words, $case->value);
            }
        }
        self::assertNull(PermissionChangeEnum::AccountCreated->selfPhrase(), 'nobody creates their own account');
        self::assertNull(PermissionChangeEnum::GrantsChanged->selfPhrase(), 'a position is not a person');
    }

    private static function change(PermissionChangeEnum $action, ?string $before, ?string $after, ?int $by): PermissionChange
    {
        return new PermissionChange(
            recordedAt: new \DateTimeImmutable('2026-11-08 09:42'),
            byId: $by,
            byName: 7 === $by ? 'Thabo Ndlovu' : 'Naomi Kileo',
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
