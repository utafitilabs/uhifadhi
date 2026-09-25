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
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\TimestampableTrait;
use Uhifadhi\Bundle\TeamBundle\Entity\Trait\UuidTrait;
use Uhifadhi\Bundle\TeamBundle\Repository\RankRepository;

/**
 * A RANK — a name, a short code and a place in its scale's seniority order.
 *
 * RANKS GRANT NOTHING. What a person may do is their position's; a rank is a
 * fact about them the organization keeps, filters on and exports.
 *
 * A RANK SOMEBODY HELD IS RETIRED, NEVER DELETED: the history of who held it
 * points at it, and a promotion is a dated fact that must keep reading.
 */
#[ORM\Entity(repositoryClass: RankRepository::class)]
#[ORM\Table(name: 'team_rank')]
#[ORM\HasLifecycleCallbacks]
class Rank
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: RankScale::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RankScale $scale;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 24)]
    private string $shortCode = '';

    /** Seniority within the scale: 1 is the most senior, read first; a new rank is added at the junior end. */
    #[ORM\Column]
    private int $seniority = 1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    public function __construct(RankScale $scale)
    {
        $this->scale = $scale;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScale(): RankScale
    {
        return $this->scale;
    }

    /** The scale the rank compares on from now; its holders and history come along. */
    public function setScale(RankScale $scale): static
    {
        $this->scale = $scale;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function setShortCode(string $shortCode): static
    {
        $this->shortCode = $shortCode;

        return $this;
    }

    public function getSeniority(): int
    {
        return $this->seniority;
    }

    public function setSeniority(int $seniority): static
    {
        $this->seniority = $seniority;

        return $this;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function setRetiredAt(?\DateTimeImmutable $retiredAt): static
    {
        $this->retiredAt = $retiredAt;

        return $this;
    }

    public function isRetired(): bool
    {
        return null !== $this->retiredAt;
    }
}
