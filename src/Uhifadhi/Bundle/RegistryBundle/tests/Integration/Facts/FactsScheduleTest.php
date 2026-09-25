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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Scheduler\RecomputeOpenFactsTask;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\FactsHostKernel;

/**
 * THE SCHEDULE: the recompute is a task on the `default` schedule, at the
 * configured cadence, in an installation that has no schedule of its own.
 *
 * The schedule is read the way the Scheduler documents reading one —
 * `debug:scheduler` with `--date`, which prints each recurring message's
 * trigger and its next run from that date:
 *
 *   "php bin/console debug:scheduler --date=2025-10-18"
 *   — https://symfony.com/doc/current/scheduler.html#debugging-the-schedule
 */
#[CoversClass(RecomputeOpenFactsTask::class)]
final class FactsScheduleTest extends FactsTestCase
{
    protected function tearDown(): void
    {
        FactsHostKernel::$registry = [];

        parent::tearDown();
    }

    public function testTheRecomputeRunsHourlyByDayAndOnceAtNight(): void
    {
        [$status, $output] = $this->console(['command' => 'debug:scheduler', 'schedule' => ['default'], '--date' => '2026-09-25 13:30:00']);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('0 6-20 * * *', $output);
        self::assertStringContainsString('0 2 * * *', $output);
        self::assertStringContainsString('Fri, 25 Sep 2026 14:00:00', $output, 'the next working hour');
        self::assertStringContainsString('Sat, 26 Sep 2026 02:00:00', $output, 'the night run');
    }

    public function testAnInstallationSetsItsOwnCadence(): void
    {
        self::ensureKernelShutdown();
        FactsHostKernel::$registry = ['facts' => ['schedule' => ['*/15 * * * *']]];
        self::bootKernel();

        [$status, $output] = $this->console(['command' => 'debug:scheduler', 'schedule' => ['default'], '--date' => '2026-09-25 13:30:00']);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('*/15 * * * *', $output);
        self::assertStringNotContainsString('0 6-20 * * *', $output);
        self::assertStringContainsString('Fri, 25 Sep 2026 13:45:00', $output);
    }
}
