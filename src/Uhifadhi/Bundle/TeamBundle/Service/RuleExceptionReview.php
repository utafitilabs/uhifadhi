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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;

/**
 * ONE PLACE TO REVIEW WHO IS OUTSIDE A RULE (ruled 2026-09-26).
 *
 * Every seat holding an exception in force — its reason, who gave it, when,
 * and how many sit in it — and, named openly rather than left silent, the two
 * tiers that stand above the matrix and so see everybody by tier.
 */
final readonly class RuleExceptionReview
{
    public function __construct(
        private GrantJustificationRepository $justifications,
        private UserRepository $users,
        private ConcernCatalogue $catalogue,
    ) {
    }

    /**
     * @return array{
     *     seats: list<array{position: string, uuid: string, label: string, lifts: string, holders: int, by: string, at: \DateTimeImmutable, reason: string}>,
     *     tiers: list<array{label: string, people: list<string>}>,
     *     lifts: ?string,
     *     people: int,
     * }
     */
    public function read(): array
    {
        $seats = [];
        $people = 0;
        $lifts = null;
        foreach ($this->justifications->findAllCurrent() as $justification) {
            $position = $justification->getPosition();
            if (!\in_array($justification->getPair(), $position->getGrantValues(), true)) {
                continue;
            }
            $concern = $this->catalogue->concern(Grant::parse($justification->getPair())->concern);
            if (null === $concern || null === $concern->lifts()) {
                continue;
            }
            $holders = \count($this->users->findActiveHolders($position));
            $people += $holders;
            $lifts ??= $concern->lifts();
            $seats[] = self::seat($justification, $concern->label(), $concern->lifts(), $holders);
        }

        $tiers = [];
        foreach ([TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin] as $tier) {
            $names = array_map(static fn (User $u): string => $u->getFullName(), $this->users->findActiveInTier($tier));
            $people += \count($names);
            $tiers[] = ['label' => $tier->label(), 'people' => $names];
        }

        return ['seats' => $seats, 'tiers' => $tiers, 'lifts' => $lifts ?? $this->firstLifted(), 'people' => $people];
    }

    /** @return array{position: string, uuid: string, label: string, lifts: string, holders: int, by: string, at: \DateTimeImmutable, reason: string} */
    private static function seat(GrantJustification $j, string $label, string $lifts, int $holders): array
    {
        return [
            'position' => (string) $j->getPosition()->getName(),
            'uuid' => (string) $j->getPosition()->getUuidString(),
            'label' => $label,
            'lifts' => $lifts,
            'holders' => $holders,
            'by' => $j->getGrantedByName(),
            'at' => $j->getGrantedAt(),
            'reason' => $j->getReason(),
        ];
    }

    /** The rule the installation's first exception lifts, or null when none is declared. */
    private function firstLifted(): ?string
    {
        foreach ($this->catalogue->all() as $concern) {
            if (null !== $concern->lifts()) {
                return $concern->lifts();
            }
        }

        return null;
    }
}
