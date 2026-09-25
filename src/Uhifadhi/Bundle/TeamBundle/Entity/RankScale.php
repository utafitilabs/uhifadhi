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
use Uhifadhi\Bundle\TeamBundle\Repository\RankScaleRepository;

/**
 * ONE SCALE OF RANKS — an ordered set of ranks that compare with each other.
 *
 * AN ORGANIZATION HAS ONE, and it carries no name while it is the only one:
 * nothing on a page says "scale" until a second exists. A second is added
 * only for ranks that do not compare with the first (a uniformed scale and a
 * civil one), and from then on every scale is named for what it is.
 *
 * NEVER A DEPARTMENT. A scale is the organization's; nothing here points at a
 * department, and a person's rank is independent of their department and of
 * their position.
 */
#[ORM\Entity(repositoryClass: RankScaleRepository::class)]
#[ORM\Table(name: 'team_rank_scale')]
#[ORM\HasLifecycleCallbacks]
class RankScale
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** Null while it is the organization's only scale. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $name = null;

    /** The order the scales are read in: the one the organization had first, first. */
    #[ORM\Column]
    private int $sortOrder = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
