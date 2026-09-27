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
use Uhifadhi\Bundle\TeamBundle\Repository\OneTimePasswordRepository;

/**
 * ONE ONE-TIME PASSWORD, ISSUED — the audit line, never the code.
 *
 * Who issued a one-time password for whom, when, and when it was first used
 * to sign in. The code itself is never stored here: it becomes the person's
 * password, hashed like any other, and is shown once to the administrator
 * who issued it. The issuer's name is copied at issue, so the line still
 * reads after that account is gone.
 */
#[ORM\Entity(repositoryClass: OneTimePasswordRepository::class)]
#[ORM\Table(name: 'team_one_time_password')]
class OneTimePassword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $person;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy; // @phpstan-ignore property.unusedType (the database sets it null when that account is removed)

    #[ORM\Column(length: 160)]
    private string $issuedByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(User $person, User $issuedBy, \DateTimeImmutable $issuedAt)
    {
        $this->person = $person;
        $this->issuedBy = $issuedBy;
        $this->issuedByName = $issuedBy->getFullName();
        $this->issuedAt = $issuedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): User
    {
        return $this->person;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
    }

    public function getIssuedByName(): string
    {
        return $this->issuedByName;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;

        return $this;
    }
}
