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

namespace Uhifadhi\Bundle\RegistryBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockInterface;

/**
 * THE CORE'S `default` SCHEDULE REMEMBERS ITS LAST RUN AND RUNS ONLY THE LAST
 * MISSED ONE, so a recompute missed while the worker was down runs once when it
 * starts again — not once per missed hour, and not never.
 *
 * The three calls the Scheduler documents for a provider's schedule, made on
 * the schedule the framework builds from the core's tasks:
 *
 *   "->stateful($this->cache) // ensure missed tasks are executed"
 *   "->processOnlyLastMissedRun(true) // ensure only last missed task is run"
 *   "->lock($this->lockFactory->createLock('my-lock')) // ensure only one worker"
 *   — https://symfony.com/doc/current/scheduler.html#efficient-management-with-symfony-scheduler
 *
 * with the framework's own `cache.app` pool and its default lock factory
 * (`lock.factory`), so an installation configures nothing.
 *
 * @see vendor/symfony/scheduler/DependencyInjection/AddScheduleMessengerPass.php — without a provider of the installation's own, it registers `scheduler.provider.<name>` as a Schedule definition tagged `scheduler.schedule_provider`; with one, it decorates that provider
 * @see vendor/symfony/scheduler/ScheduleProviderInterface.php — getSchedule(), which the worker's message generator reads the state, lock and missed-run rule from
 * @see vendor/symfony/scheduler/Schedule.php — stateful(), lock(), processOnlyLastMissedRun()
 *
 * ONLY THE SCHEDULE THE CORE'S TASKS MADE. Where the installation writes its
 * own `default` provider, the framework decorates it, and that provider's
 * getSchedule() decides its own state and lock; this pass leaves it alone.
 *
 * Registered after the framework's pass (a lower priority of the same type),
 * because the definition it completes is that pass's.
 */
final class StatefulDefaultSchedulePass implements CompilerPassInterface
{
    public const string SCHEDULE = 'default';

    public const string LOCK_KEY = 'uhifadhi.schedule.default';

    public function process(ContainerBuilder $container): void
    {
        $id = 'scheduler.provider.'.self::SCHEDULE;
        if (!$container->hasDefinition($id)) {
            return;
        }

        $schedule = $container->getDefinition($id);
        if (null !== $schedule->getDecoratedService() || !$schedule->hasTag('scheduler.schedule_provider')) {
            return;
        }

        if ($container->has('cache.app')) {
            $schedule->addMethodCall('stateful', [new Reference('cache.app')], true);
        }

        $schedule->addMethodCall('processOnlyLastMissedRun', [true], true);

        if ($container->has('lock.factory')) {
            $schedule->addMethodCall('lock', [
                new Definition(LockInterface::class)
                    ->setFactory([new Reference('lock.factory'), 'createLock'])
                    ->setArguments([self::LOCK_KEY]),
            ], true);
        }
    }
}
