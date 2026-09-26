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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;

/**
 * WHY A SEAT HOLDS AN EXCEPTION TO A RULE, WHO SAID SO, AND WHEN.
 *
 * An exception is a grant that lifts a rule everybody else is held to — the
 * control room's "Live locations" lifts the rank rule. It is given by a Super
 * Admin with a written reason, and this row is that reason, kept beside the
 * grant for as long as the seat holds it.
 *
 * TAKEN AWAY, NOT DELETED: revoking stamps the row with who and when, so the
 * seat's history still says it once saw everybody and why. The row with no
 * revocation is the one in force, and there is at most one per seat and pair.
 * Names are copied at the time, because the account that gave it may be
 * deactivated or renamed later and the record must still read true.
 */
#[ORM\Entity(repositoryClass: GrantJustificationRepository::class)]
#[ORM\Table(name: 'team_grant_justification')]
#[ORM\UniqueConstraint(name: 'uniq_team_grant_justification_current', columns: ['position_id', 'pair'], options: ['where' => '(revoked_at IS NULL)'])]
class GrantJustification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Position $position;

    /** The pair it justifies, written `<concern>.<verb>`. */
    #[ORM\Column(length: 120)]
    private string $pair;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy; // @phpstan-ignore property.unusedType (the database sets it null when that account is removed)

    #[ORM\Column(length: 160)]
    private string $grantedByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $revokedBy = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $revokedByName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Position $position, string $pair, string $reason, User $grantedBy, \DateTimeImmutable $grantedAt)
    {
        $this->position = $position;
        $this->pair = $pair;
        $this->reason = $reason;
        $this->grantedBy = $grantedBy;
        $this->grantedByName = $grantedBy->getFullName();
        $this->grantedAt = $grantedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPosition(): Position
    {
        return $this->position;
    }

    public function getPair(): string
    {
        return $this->pair;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function getGrantedByName(): string
    {
        return $this->grantedByName;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevokedByName(): ?string
    {
        return $this->revokedByName;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(User $by, \DateTimeImmutable $at): static
    {
        $this->revokedBy = $by;
        $this->revokedByName = $by->getFullName();
        $this->revokedAt = $at;

        return $this;
    }
}
