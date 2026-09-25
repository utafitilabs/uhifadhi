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

namespace Uhifadhi\Bundle\TeamBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\TeamSettingsRepository;

/**
 * THE RULES THE WHOLE TEAM IS RUN UNDER — one row for the installation.
 *
 * THE TEST A RULE HAS TO PASS TO BE HERE: it applies to every invitation or
 * every posting, whoever writes it. A fact about one person, one position or
 * one posting is that record's own, and a rule the model itself fixes is not
 * a row anybody edits.
 *
 * ONE ROW, BY CONSTRUCTION. The identifier is assigned, never generated, so
 * there is exactly one place to read and one to write; the shipped migration
 * inserts it with these same defaults, and a schema built without the
 * migration reads them off a fresh object instead.
 */
#[ORM\Entity(repositoryClass: TeamSettingsRepository::class)]
#[ORM\Table(name: 'team_settings')]
class TeamSettings
{
    /** The one row's identifier. */
    public const int ONE = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ONE;

    /** How long an invitation link may be opened for, in $invitationValidUnit. */
    #[ORM\Column]
    private int $invitationValidAmount = 7;

    #[ORM\Column(enumType: InvitationUnitEnum::class)]
    private InvitationUnitEnum $invitationValidUnit = InvitationUnitEnum::Days;

    /** How many times one invitation link may be used. */
    #[ORM\Column]
    private int $invitationUses = 1;

    /** Whether somebody may be created with a password, rather than by invitation only. */
    #[ORM\Column]
    private bool $invitationWithPassword = true;

    /** Whether one person may be stationed at two stations at once. */
    #[ORM\Column]
    private bool $twoStationsAllowed = true;

    /** How many leaders a station carries. */
    #[ORM\Column]
    private int $leadersPerStation = 1;

    /** Whether a station may stand with nobody stationed at it. */
    #[ORM\Column]
    private bool $emptyStationAllowed = true;

    /**
     * THIS ORGANIZATION USES RANKS — on by default, set once for the whole
     * organization and never per area or department. Off hides the Rank
     * column, the Rank filter, the rank on a person and the Ranks page.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $usesRanks = true;

    public function getId(): int
    {
        return $this->id;
    }

    public function getInvitationValidAmount(): int
    {
        return $this->invitationValidAmount;
    }

    public function setInvitationValidAmount(int $amount): static
    {
        $this->invitationValidAmount = $amount;

        return $this;
    }

    public function getInvitationValidUnit(): InvitationUnitEnum
    {
        return $this->invitationValidUnit;
    }

    public function setInvitationValidUnit(InvitationUnitEnum $unit): static
    {
        $this->invitationValidUnit = $unit;

        return $this;
    }

    public function getInvitationUses(): int
    {
        return $this->invitationUses;
    }

    public function setInvitationUses(int $uses): static
    {
        $this->invitationUses = $uses;

        return $this;
    }

    public function isInvitationWithPassword(): bool
    {
        return $this->invitationWithPassword;
    }

    public function setInvitationWithPassword(bool $allowed): static
    {
        $this->invitationWithPassword = $allowed;

        return $this;
    }

    public function isTwoStationsAllowed(): bool
    {
        return $this->twoStationsAllowed;
    }

    public function setTwoStationsAllowed(bool $allowed): static
    {
        $this->twoStationsAllowed = $allowed;

        return $this;
    }

    public function getLeadersPerStation(): int
    {
        return $this->leadersPerStation;
    }

    public function setLeadersPerStation(int $leaders): static
    {
        $this->leadersPerStation = $leaders;

        return $this;
    }

    public function isEmptyStationAllowed(): bool
    {
        return $this->emptyStationAllowed;
    }

    public function setEmptyStationAllowed(bool $allowed): static
    {
        $this->emptyStationAllowed = $allowed;

        return $this;
    }

    /** When a link sent at $since stops opening. */
    public function usesRanks(): bool
    {
        return $this->usesRanks;
    }

    public function setUsesRanks(bool $usesRanks): static
    {
        $this->usesRanks = $usesRanks;

        return $this;
    }

    public function invitationExpiry(\DateTimeImmutable $since): \DateTimeImmutable
    {
        return $since->add($this->invitationValidUnit->interval($this->invitationValidAmount));
    }
}
