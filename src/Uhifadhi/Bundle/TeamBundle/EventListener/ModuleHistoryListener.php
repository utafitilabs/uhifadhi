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

namespace Uhifadhi\Bundle\TeamBundle\EventListener;

use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\RegistryBundle\Event\ModuleInstalledEvent;
use Uhifadhi\Bundle\TeamBundle\Message\BackfillModuleHistory;

/**
 * A MODULE THAT ARRIVES IN SEPTEMBER CAN ANSWER FOR JULY — SO INSTALLING ONE
 * QUEUES THE QUESTION.
 *
 * The snapshot writes, once a period, what the installed modules published;
 * a module switched on afterwards would have no past for a year. So the
 * install asks it about the periods that have closed
 * ({@see \Uhifadhi\Bundle\TeamBundle\MessageHandler\BackfillModuleHistoryHandler}).
 *
 * THE CLICK WAITS FOR NOTHING. Asking six closed months of every department
 * is work that grows with the installation, so the listener dispatches a
 * message that carries the core's queue marker and returns; the worker
 * answers it. An installation that has not routed the marker handles it in
 * the request, as before — correct, and slow.
 *
 * @see https://symfony.com/doc/current/event_dispatcher.html — a listener is a service tagged 'kernel.event_listener' with the event and the method
 * @see vendor/symfony/event-dispatcher/DependencyInjection/RegisterListenersPass.php — the tag attributes this listener is registered with in config/services.php
 */
final readonly class ModuleHistoryListener
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function onModuleInstalled(ModuleInstalledEvent $event): void
    {
        $this->bus->dispatch(new BackfillModuleHistory($event->slug));
    }
}
