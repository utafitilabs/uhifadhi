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
use Uhifadhi\Bundle\TeamBundle\Repository\RankHoldingRepository;

/**
 * A PERSON HELD A RANK FROM ONE DAY TO ANOTHER — a promotion is a dated fact.
 *
 * ONE RANK AT A TIME: the holding with no end is the rank held now, and
 * giving somebody another closes it on the day the new one starts. The rows
 * are the history, read on the person's record as from · to · rank · by.
 */
#[ORM\Entity(repositoryClass: RankHoldingRepository::class)]
#[ORM\Table(name: 'team_rank_holding')]
#[ORM\HasLifecycleCallbacks]
class RankHolding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $person;

    #[ORM\ManyToOne(targetEntity: Rank::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Rank $rank;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $since;

    /** Null while the rank is held. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $until = null;

    /** Who wrote it down; kept when that account is not. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(User $person, Rank $rank, \DateTimeImmutable $since)
    {
        $this->person = $person;
        $this->rank = $rank;
        $this->since = $since;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): User
    {
        return $this->person;
    }

    public function getRank(): Rank
    {
        return $this->rank;
    }

    public function getSince(): \DateTimeImmutable
    {
        return $this->since;
    }

    public function getUntil(): ?\DateTimeImmutable
    {
        return $this->until;
    }

    public function setUntil(?\DateTimeImmutable $until): static
    {
        $this->until = $until;

        return $this;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(?User $recordedBy): static
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function stampCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
