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

use Doctrine\ORM\Event\OnFlushEventArgs;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;

/**
 * EVERY HISTORY LINE ASKS FOR A PERMISSIONS RUN.
 *
 * THE REQUEST IS WRITTEN WITH THE CHANGE. When a flush inserts a
 * {@see PermissionChange}, a pending run is added to that same flush — one
 * transaction, so a change that is committed always has its run asked for,
 * and one rolled back never does. A request already waiting is the same
 * request, so none is added beside it.
 *
 * THE WORKER IS WOKEN LATER. The message is sent when the request, the
 * command or the worker's message is over (`kernel.terminate`,
 * `console.terminate`, `WorkerMessageHandledEvent`), never from inside the
 * flush: where the message is handled synchronously the run would flush
 * inside this flush, and the unit of work has not let go of its insertions
 * until after postFlush. A wake-up that is lost costs nothing — the pending
 * row stays, and the next message or the nightly run takes it.
 *
 * @see https://symfony.com/doc/current/doctrine/events.html#doctrine-lifecycle-listeners — a Doctrine listener is a service tagged 'doctrine.event_listener' with the event
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/3.5/reference/events.html#onflush — "If you create and persist a new entity in onFlush, then calling EntityManager#persist() is not enough. You have to execute an additional call to $unitOfWork->computeChangeSet($classMetadata, $entity)."
 * @see vendor/symfony/doctrine-bridge/DependencyInjection/CompilerPass/RegisterEventListenersAndSubscribersPass.php — reads the tag's `event` attribute
 * @see vendor/doctrine/orm/src/UnitOfWork.php — commit(): postFlush is dispatched before postCommitCleanup() lets go of the insertions
 * @see https://symfony.com/doc/current/components/http_kernel.html#the-kernel-terminate-event — work done after the response is sent
 */
final class PermissionRunListener implements ResetInterface
{
    private bool $requested = false;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $line = null;
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PermissionRun) {
                return;
            }
            if ($entity instanceof PermissionChange) {
                $line ??= $entity;
            }
        }
        if (null === $line) {
            return;
        }

        $this->requested = true;
        if (null !== $em->getRepository(PermissionRun::class)->findOneBy(['status' => PermissionRunStatusEnum::Pending])) {
            return;
        }

        $run = new PermissionRun(
            PermissionRunTriggerEnum::HistoryLine,
            $this->clock?->now() ?? new \DateTimeImmutable(),
            $line->getById(),
            $line->getByName(),
        );
        $em->persist($run);
        $uow->computeChangeSet($em->getClassMetadata(PermissionRun::class), $run);
    }

    /** Wake the worker once the request, command or message that made the change is over. */
    public function wake(): void
    {
        if (!$this->requested) {
            return;
        }

        $this->requested = false;
        $this->bus->dispatch(new RecomputePermissions());
    }

    public function reset(): void
    {
        $this->requested = false;
    }
}
