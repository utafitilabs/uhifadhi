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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\TeamSettings;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository;

/**
 * THE RULES THE WHOLE TEAM IS RUN UNDER — read by every screen that states one
 * and written by the configure sections, one card per write.
 *
 * READING NEVER WRITES. An installation whose schema was built without the
 * shipped migration has no row; it reads a fresh object carrying the same
 * defaults the migration inserts, and the row is written the first time a
 * card is saved.
 *
 * EACH CARD SAVES ITS OWN RULES AND NO OTHER'S: the invitation rules and the
 * stationing rules are two writes, so a save on one section never carries a
 * stale copy of the other.
 */
final readonly class TeamSettingsService
{
    public function __construct(
        private TeamSettingsRepository $settings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function current(): TeamSettings
    {
        return $this->settings->findOne() ?? new TeamSettings();
    }

    /**
     * THE INVITATION RULES — how long a link lives, how many times it may be
     * used, and whether somebody may be created with a password instead.
     *
     * @throws \InvalidArgumentException when a count is below one
     */
    public function setInvitationRules(int $validAmount, InvitationUnitEnum $unit, int $uses, bool $withPassword): void
    {
        if ($validAmount < 1) {
            throw new \InvalidArgumentException('An invitation is valid for at least one hour or one day.');
        }
        if ($uses < 1) {
            throw new \InvalidArgumentException('An invitation link is used at least once.');
        }

        $row = $this->row();
        $row->setInvitationValidAmount($validAmount)
            ->setInvitationValidUnit($unit)
            ->setInvitationUses($uses)
            ->setInvitationWithPassword($withPassword);

        $this->entityManager->flush();
    }

    /**
     * THE STATIONING RULES — what every posting an area writes is held to.
     *
     * @throws \InvalidArgumentException when the leader count is below one
     */
    public function setStationingRules(bool $twoStationsAllowed, int $leadersPerStation, bool $emptyStationAllowed): void
    {
        if ($leadersPerStation < 1) {
            throw new \InvalidArgumentException('A station has at least one leader.');
        }

        $row = $this->row();
        $row->setTwoStationsAllowed($twoStationsAllowed)
            ->setLeadersPerStation($leadersPerStation)
            ->setEmptyStationAllowed($emptyStationAllowed);

        $this->entityManager->flush();
    }

    /** The one row, made the first time anything is written to it. */
    private function row(): TeamSettings
    {
        $row = $this->settings->findOne();
        if (null === $row) {
            $row = new TeamSettings();
            $this->entityManager->persist($row);
        }

        return $row;
    }
}
