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

namespace Uhifadhi\Bundle\RegistryBundle\Scheduler;

use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\RegistryBundle\Message\RecomputeOpenFacts;

/**
 * THE SCHEDULE'S TICK: it queues the recompute and computes nothing itself.
 *
 * A TASK ON THE `default` SCHEDULE, one per cron expression in
 * `registry.facts.schedule`. The bundle tags this service `scheduler.task`
 * by hand — the tag `#[AsCronTask]` writes for an autoconfigured service —
 * so the framework adds the task to the installation's own `default`
 * schedule when it has one (the starter's `App\Schedule`), and creates the
 * schedule when it has not:
 *
 *   "#[AsCronTask] … schedule: 'default'"
 *   — https://symfony.com/doc/current/scheduler.html#attaching-recurring-messages-to-a-schedule
 *
 * @see vendor/symfony/scheduler/DependencyInjection/AddScheduleMessengerPass.php — a `scheduler.task` tag becomes a RecurringMessage of a ServiceCallMessage; with a provider already registered for the schedule it decorates that provider, otherwise it registers one
 * @see vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php — the tag attributes #[AsCronTask] maps to: trigger 'cron', expression, timezone, schedule
 *
 * WHY IT QUEUES RATHER THAN COMPUTES. The worker that consumes
 * `scheduler_default` would run the recompute inline; sent to the queue
 * instead ({@see RecomputeOpenFacts} carries the core's marker), a run that
 * fails is retried and then kept on the failure transport, where
 * `messenger:failed:retry` finds it — the schedule's own transport keeps
 * neither.
 */
final readonly class RecomputeOpenFactsTask
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): void
    {
        $this->bus->dispatch(new RecomputeOpenFacts());
    }
}
