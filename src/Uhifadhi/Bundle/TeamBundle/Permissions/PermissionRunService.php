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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;

/**
 * THE PERMISSIONS RUN: asked for, then run by the worker.
 *
 * A REQUEST IS A PENDING RUN. {@see requestRun()} writes one and wakes the
 * worker; a second request while one is still waiting is the same request,
 * so a burst of changes, a nightly tick and a button press become one run.
 *
 * A RUN, once started, takes every request still waiting with it (they are
 * superseded: it answers them all), files its answers, its six standing
 * lists and its drift against the previous finished run, and then prunes:
 * the answers and findings of every finished run before the previous one are
 * deleted, so only the latest two are kept and the pages read one run. The
 * run rows themselves, and drift, are kept.
 */
final readonly class PermissionRunService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PermissionRunRepository $runs,
        private PermissionAnswerRepository $answers,
        private PermissionFindingRepository $findings,
        private PermissionAnswerService $answering,
        private PermissionFindingService $finding,
        private PermissionDriftService $drifting,
        private MessageBusInterface $bus,
        private ?ClockInterface $clock = null,
    ) {
    }

    /** Ask for a run; the request already waiting is returned when there is one. */
    public function requestRun(PermissionRunTriggerEnum $trigger, ?User $by): PermissionRun
    {
        $pending = $this->runs->findOnePending();
        if (null !== $pending) {
            return $pending;
        }

        $run = new PermissionRun($trigger, $this->now(), $by?->getId(), null === $by ? null : self::nameOf($by));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->bus->dispatch(new RecomputePermissions($run->getId()));

        return $run;
    }

    /**
     * THE WORKER'S SIDE. A run that is no longer pending — run already, or
     * taken along by another — is left as it is; with no id, the oldest
     * request waiting is the one run.
     */
    public function handle(?int $runId): ?PermissionRun
    {
        $run = null === $runId ? $this->runs->findOnePending() : $this->runs->find($runId);
        if (null === $run || PermissionRunStatusEnum::Pending !== $run->getStatus()) {
            return null;
        }

        return $this->execute($run);
    }

    private function execute(PermissionRun $run): PermissionRun
    {
        $started = $this->now();
        $clock = hrtime(true);
        foreach ($this->runs->findPending() as $waiting) {
            if ($waiting !== $run) {
                $waiting->supersede($started);
            }
        }
        $run->start($started, $this->answering->places(), $this->answering->pairs());
        $this->entityManager->flush();

        try {
            $computed = $this->answering->compute($run);
            $findings = $this->finding->compute($run, $computed['answers']);
            $previous = $this->runs->findOnePreviousFinished($run);
            $drift = $this->drifting->compute($run, $previous, $computed['answers']);

            foreach ([...$computed['answers'], ...$findings, ...$drift] as $row) {
                $this->entityManager->persist($row);
            }
            $run->finish($this->now(), [
                'people' => $computed['people'],
                'staff' => $computed['staff'],
                'superAdmins' => $computed['superAdmins'],
                'admins' => $computed['admins'],
                'questions' => $computed['questions'],
                'shapes' => $computed['shapes'],
                'answers' => \count($computed['answers']),
                'findings' => \count($findings),
                'drift' => \count($drift),
                'ms' => intdiv(hrtime(true) - $clock, 1_000_000),
            ]);
            $this->entityManager->flush();
        } catch (\Throwable $failure) {
            if ($this->entityManager->isOpen()) {
                $run->fail($this->now(), $failure->getMessage());
                $this->entityManager->flush();
            }

            throw $failure;
        }

        if (null !== $previous) {
            foreach ($this->runs->findFinishedOlderThan($previous) as $old) {
                $this->answers->deleteByRun($old);
                $this->findings->deleteByRun($old);
            }
        }

        return $run;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
