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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * AN INSTALLATION AS THE STARTER MAKES IT: a queue, a failure transport, the
 * core's marker routed to the queue, and a `default` schedule of its own.
 *
 * THE MESSENGER BLOCK IS THE STARTER'S `config/packages/messenger.yaml`,
 * written in PHP, with its `when@test` transports — `in-memory://`, so a
 * specification can see what was queued:
 *
 *   "Use in-memory:// in functional tests to verify correct message dispatch
 *    to transports"
 *   — https://symfony.com/doc/current/messenger.html#in-memory-transport
 *
 * and the routing line the upgrade guide tells an installation to write:
 *
 *   'Uhifadhi\Contracts\Queue\AsyncMessageInterface': async
 *
 * THE SCHEDULE is the starter's `App\Schedule` stand-in: an installation's
 * own provider for `default`, which the core's task must join rather than
 * collide with.
 */
final class QueuedHostKernel extends FactsHostKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('framework', [
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => 'in-memory://',
                    'failed' => 'in-memory://',
                ],
                'routing' => [
                    AsyncMessageInterface::class => 'async',
                ],
            ],
        ]);

        $services = $container->services();

        $services->set('test.installation_schedule', InstallationSchedule::class)
            ->tag('scheduler.schedule_provider', ['name' => 'default']);

        $services->set('test.plain_handler', PlainMessageHandler::class)
            ->tag('messenger.message_handler', ['handles' => PlainMessage::class]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'-queued';
    }
}
