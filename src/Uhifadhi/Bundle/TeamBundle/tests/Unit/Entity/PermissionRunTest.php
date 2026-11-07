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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionDrift;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;

/** A request is pending until the worker starts it; then it finishes, fails, or is taken along by another. */
#[CoversClass(PermissionRun::class)]
#[CoversClass(PermissionDrift::class)]
final class PermissionRunTest extends TestCase
{
    public function testARequestStartsPendingAndFinishesWithItsCounts(): void
    {
        $run = new PermissionRun(PermissionRunTriggerEnum::Manual, new \DateTimeImmutable('2026-11-06 14:00'), 3, 'Naomi Kileo');
        self::assertSame(PermissionRunStatusEnum::Pending, $run->getStatus());
        self::assertNull($run->getDurationMs());

        $run->start(new \DateTimeImmutable('2026-11-06 14:02:00'), [], ['surveys.read']);
        self::assertSame(PermissionRunStatusEnum::Running, $run->getStatus());

        $run->finish(new \DateTimeImmutable('2026-11-06 14:02:03'), ['answers' => 12]);
        self::assertSame(PermissionRunStatusEnum::Finished, $run->getStatus());
        self::assertSame(3000, $run->getDurationMs(), 'from the two instants when the run did not measure itself');
        self::assertSame(12, $run->getCount('answers'));
        self::assertSame(0, $run->getCount('drift'));
    }

    public function testTheRunsOwnMeasureIsPreferred(): void
    {
        $run = new PermissionRun(PermissionRunTriggerEnum::Nightly, new \DateTimeImmutable('2026-11-06 02:00'));
        $run->start(new \DateTimeImmutable('2026-11-06 02:00:00'), [], []);
        $run->finish(new \DateTimeImmutable('2026-11-06 02:00:01'), ['ms' => 734]);

        self::assertSame(734, $run->getDurationMs());
    }

    public function testAFailureAndASupersededRequestAreSaidSo(): void
    {
        $failed = new PermissionRun(PermissionRunTriggerEnum::Deploy, new \DateTimeImmutable())->fail(new \DateTimeImmutable(), 'the database went away');
        self::assertSame(PermissionRunStatusEnum::Failed, $failed->getStatus());
        self::assertSame('the database went away', $failed->getFailure());

        $taken = new PermissionRun(PermissionRunTriggerEnum::HistoryLine, new \DateTimeImmutable())->supersede(new \DateTimeImmutable());
        self::assertSame(PermissionRunStatusEnum::Superseded, $taken->getStatus());
    }

    public function testDriftIsReviewedOnceAndKeepsTheFirstLook(): void
    {
        $run = new PermissionRun(PermissionRunTriggerEnum::Nightly, new \DateTimeImmutable());
        $drift = new PermissionDrift($run, null, QuestionKindEnum::Pair, 'surveys.read', PlaceKindEnum::Organization, null, 'the whole organization', 4, 'Rhino coordinator', before: 0, after: 1);
        self::assertFalse($drift->isReviewed());

        $drift->review(new \DateTimeImmutable('2026-11-06 15:00'), 'Naomi Kileo');
        $drift->review(new \DateTimeImmutable('2026-11-07 09:00'), 'Desta Haile');

        self::assertTrue($drift->isReviewed());
        self::assertSame('Naomi Kileo', $drift->getReviewedByName());
        self::assertSame('2026-11-06', $drift->getReviewedAt()?->format('Y-m-d'));
    }
}
