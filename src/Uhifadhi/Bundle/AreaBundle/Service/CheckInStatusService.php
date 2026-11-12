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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Exception\CheckInStatusException;
use Uhifadhi\Bundle\AreaBundle\Model\CheckInStatusDraft;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;

/**
 * THE WORDS AN AREA LETS A RANGER CHECK IN WITH.
 *
 * AN AREA THAT HAS CONFIGURED NOTHING STILL WORKS. The four the handset
 * already speaks are written on first use, so an installation that has
 * never opened the roster's settings has a working check-in — and an
 * organization that wants "On escort" adds it beside them rather than
 * waiting for a release.
 *
 * WRITTEN WHOLE, by the area's Configure › Area settings card: the list as
 * it stands after a save is judged by the rules in {@see check()}, and a
 * refused list stores nothing.
 *
 * SEEDED ONCE AND NEVER RE-SEEDED. An area that deactivated "Unfit for
 * duty" on purpose must not find it back tomorrow; the presence of ANY
 * status is what says this area has been here before.
 */
final readonly class CheckInStatusService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CheckInStatusRepository $statuses,
    ) {
    }

    /**
     * WHAT THIS AREA OFFERS, seeding the defaults the first time it is
     * asked.
     *
     * @return list<CheckInStatus>
     */
    public function offeredBy(AreaOfInterest $area): array
    {
        $offered = $this->statuses->activeFor($area);
        if ([] !== $offered) {
            return $offered;
        }

        // NOTHING AT ALL means this area has never been asked; an area
        // that switched every status off has rows and gets none of this.
        if ([] !== $this->statuses->findBy(['area' => $area])) {
            return [];
        }

        return $this->seed($area);
    }

    /**
     * WHEN THIS AREA'S WORDS LAST CHANGED, so a handset can tell whether
     * the list it holds is the list the area publishes.
     *
     * ASKED THROUGH THE SERVICE and not the repository, because a caller
     * that reached past it for this one fact would read a moment from
     * before the seeding the same call is about to do — and an area
     * being asked for the first time would answer "never changed" for a
     * list it had just been given.
     */
    public function lastChangedFor(AreaOfInterest $area): ?\DateTimeImmutable
    {
        return $this->statuses->lastChangedFor($area);
    }

    /**
     * EVERY STATUS THIS AREA HOLDS, offered or not, for the editor: seeded
     * the first time, like the list the handset is given.
     *
     * @return list<CheckInStatus>
     */
    public function listedFor(AreaOfInterest $area): array
    {
        $this->offeredBy($area);

        return $this->statuses->findListedFor($area);
    }

    /**
     * THE KEYS A CHECK-IN OR A CORRECTION ALREADY CARRIES in this area.
     *
     * @return list<string>
     */
    public function usedKeys(AreaOfInterest $area): array
    {
        return $this->statuses->getUsedKeys($area);
    }

    /**
     * THE EDITOR'S FORM, row by row, in the order it was sent. A row that is
     * not a row is dropped; a kind the platform does not have is no kind.
     *
     * @param array<mixed> $posted
     *
     * @return list<CheckInStatusDraft>
     */
    public function draftsFrom(array $posted): array
    {
        $drafts = [];
        foreach ($posted as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $drafts[] = new CheckInStatusDraft(
                trim(self::text($row['key'] ?? '')),
                trim(self::text($row['label'] ?? '')),
                CheckInStatusKind::tryFrom(self::text($row['kind'] ?? '')),
                '1' === self::text($row['active'] ?? '1'),
                '1' === self::text($row['new'] ?? '0'),
            );
        }

        return $drafts;
    }

    /**
     * THE LIST, SAVED WHOLE OR NOT AT ALL. Each row sent rewords, reorders,
     * re-kinds or (de)activates the status it names, or adds a new one; a
     * stored status the list leaves out is kept as it is, after the ones sent.
     * The offered ones come first, in the order sent.
     *
     * @param list<CheckInStatusDraft> $drafts
     *
     * @throws CheckInStatusException
     */
    public function save(AreaOfInterest $area, array $drafts): void
    {
        $stored = $this->listedFor($area);
        $this->check($stored, $this->statuses->getUsedKeys($area), $drafts);

        $byKey = [];
        foreach ($stored as $status) {
            $byKey[$status->getKey()] = $status;
        }

        $sent = [];
        $listed = [];
        foreach ($drafts as $draft) {
            $status = $draft->new ? new CheckInStatus()->setArea($area)->setKey($draft->key) : $byKey[$draft->key];
            if (null !== $draft->kind) {
                $status->setKind($draft->kind);
            }
            $status->setLabel($draft->label)->setActive($draft->active);
            if ($draft->new) {
                $this->entityManager->persist($status);
            }
            $sent[$draft->key] = true;
            $listed[] = $status;
        }
        foreach ($stored as $status) {
            if (!isset($sent[$status->getKey()])) {
                $listed[] = $status;
            }
        }

        $ordered = [
            ...array_filter($listed, static fn (CheckInStatus $status): bool => $status->isActive()),
            ...array_filter($listed, static fn (CheckInStatus $status): bool => !$status->isActive()),
        ];
        foreach ($ordered as $position => $status) {
            $status->setPosition($position);
        }

        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->flush();
        });
    }

    /**
     * THE RULES, judged on the list as it would stand after the save.
     *
     *  - a key is the platform's shape, used once in the area offered or not,
     *    and only a new status brings one;
     *  - every status has a label and a kind;
     *  - a used status keeps its kind;
     *  - a status not offered is frozen: it changes only by being reactivated;
     *  - at least one At a station status stays offered.
     *
     * @param list<CheckInStatus>      $stored
     * @param list<string>             $used
     * @param list<CheckInStatusDraft> $drafts
     *
     * @throws CheckInStatusException
     */
    public function check(array $stored, array $used, array $drafts): void
    {
        $byKey = [];
        foreach ($stored as $status) {
            $byKey[$status->getKey()] = $status;
        }

        $sent = [];
        $added = [];
        foreach ($drafts as $draft) {
            $was = $byKey[$draft->key] ?? null;

            if ($draft->new) {
                if (1 !== preg_match(CheckInStatus::KEY_PATTERN, $draft->key)) {
                    throw CheckInStatusException::keyMalformed($draft->key);
                }
                if (null !== $was) {
                    throw CheckInStatusException::keyTaken($draft->key);
                }
                if (isset($added[$draft->key])) {
                    throw CheckInStatusException::keyTwice($draft->key);
                }
                $added[$draft->key] = true;
            } else {
                if (null === $was) {
                    throw CheckInStatusException::unknown($draft->key);
                }
                if (isset($sent[$draft->key])) {
                    throw CheckInStatusException::sentTwice($draft->key);
                }
                $sent[$draft->key] = true;
            }

            if ('' === trim($draft->label)) {
                throw CheckInStatusException::nameless();
            }
            if (mb_strlen($draft->label) > CheckInStatus::LABEL_LENGTH) {
                throw CheckInStatusException::labelTooLong();
            }
            if (null === $draft->kind) {
                throw CheckInStatusException::kindless($draft->label);
            }

            if (null === $was) {
                continue;
            }
            if (!$was->isActive() && !$draft->active && ($draft->label !== $was->getLabel() || $draft->kind !== $was->getKind())) {
                throw CheckInStatusException::frozen($was->getLabel());
            }
            if ($draft->kind !== $was->getKind() && \in_array($was->getKey(), $used, true)) {
                throw CheckInStatusException::kindFixed($was->getLabel(), $was->getKind());
            }
        }

        $offersAStation = false;
        foreach ($drafts as $draft) {
            $offersAStation = $offersAStation || ($draft->active && CheckInStatusKind::AtPost === $draft->kind);
        }
        foreach ($stored as $status) {
            if (!isset($sent[$status->getKey()])) {
                $offersAStation = $offersAStation || ($status->isActive() && $status->getKind()->takesStation());
            }
        }
        if (!$offersAStation) {
            throw CheckInStatusException::noStation();
        }
    }

    /**
     * THE FOUR AN AREA STARTS WITH, in the order the design offers them.
     *
     * @return list<CheckInStatus>
     */
    public function seed(AreaOfInterest $area): array
    {
        $seeded = [];
        foreach (CheckInStatus::DEFAULTS as $position => $default) {
            $status = new CheckInStatus()
                ->setArea($area)
                ->setKey($default['key'])
                ->setLabel($default['label'])
                ->setKind($default['kind'])
                ->setPosition($position);

            $this->entityManager->persist($status);
            $seeded[] = $status;
        }

        $this->entityManager->flush();

        return $seeded;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
