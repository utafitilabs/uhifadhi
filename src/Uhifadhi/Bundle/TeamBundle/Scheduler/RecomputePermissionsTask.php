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

namespace Uhifadhi\Bundle\TeamBundle\Scheduler;

use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;

/**
 * THE NIGHTLY TICK: it asks for a run and computes nothing itself.
 *
 * A TASK ON THE `default` SCHEDULE at `team.permissions.schedule`. The
 * bundle tags this service `scheduler.task` by hand — the tag `#[AsCronTask]`
 * writes for an autoconfigured service — so the framework adds it to the
 * installation's `default` schedule, the one the worker consumes as
 * `scheduler_default`:
 *
 *   "#[AsCronTask] … schedule: 'default'"
 *   — https://symfony.com/doc/current/scheduler.html#attaching-recurring-messages-to-a-schedule
 *
 * @see vendor/symfony/scheduler/DependencyInjection/AddScheduleMessengerPass.php — a `scheduler.task` tag becomes a RecurringMessage of a ServiceCallMessage on its schedule
 * @see vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php — the tag attributes #[AsCronTask] maps to: trigger 'cron', expression, timezone, schedule
 * @see src/Uhifadhi/Bundle/RegistryBundle/Scheduler/RecomputeOpenFactsTask.php — the core's other task, wired the same way
 *
 * WHY IT REQUESTS RATHER THAN RUNS: the request is a pending row and a
 * queued message, so a run that fails is retried and kept on the failure
 * transport, and a request already waiting is not doubled.
 */
final readonly class RecomputePermissionsTask
{
    public function __construct(
        private PermissionRunService $runs,
    ) {
    }

    public function __invoke(): void
    {
        $this->runs->requestRun(PermissionRunTriggerEnum::Nightly, null);
    }
}
