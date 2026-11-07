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
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;

/**
 * AN ANSWER THAT CHANGED BETWEEN TWO RUNS WITH NO HISTORY LINE TO EXPLAIN IT:
 * the position (or the person), the question, the place, before and after,
 * and the two runs, whose start times are the window nothing was recorded in.
 *
 * Drift rows are not pruned with the answers: an alarm stays until somebody
 * has looked at it, and marking it reviewed is the one change it takes.
 */
#[ORM\Entity(repositoryClass: PermissionDriftRepository::class)]
#[ORM\Table(name: 'team_permission_drift')]
#[ORM\Index(name: 'idx_team_permission_drift_run', columns: ['run_id', 'reviewed_at'])]
class PermissionDrift
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $reviewedByName = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermissionRun::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PermissionRun $run,
        #[ORM\ManyToOne(targetEntity: PermissionRun::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?PermissionRun $previousRun,
        #[ORM\Column(length: 10, enumType: QuestionKindEnum::class)]
        private QuestionKindEnum $questionKind,
        #[ORM\Column(length: 120)]
        private string $questionKey,
        #[ORM\Column(length: 20, nullable: true, enumType: PlaceKindEnum::class)]
        private ?PlaceKindEnum $placeKind,
        #[ORM\Column(nullable: true)]
        private ?int $placeId,
        #[ORM\Column(length: 160)]
        private string $placeName,
        #[ORM\Column(nullable: true)]
        private ?int $positionId,
        #[ORM\Column(length: 160)]
        private string $subjectName,
        #[ORM\Column(nullable: true)]
        private ?int $personId = null,
        #[ORM\Column(name: 'value_before')]
        private int $before = 0,
        #[ORM\Column(name: 'value_after')]
        private int $after = 0,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): PermissionRun
    {
        return $this->run;
    }

    public function getPreviousRun(): ?PermissionRun
    {
        return $this->previousRun;
    }

    public function getQuestionKind(): QuestionKindEnum
    {
        return $this->questionKind;
    }

    public function getQuestionKey(): string
    {
        return $this->questionKey;
    }

    public function getPlaceKind(): ?PlaceKindEnum
    {
        return $this->placeKind;
    }

    public function getPlaceId(): ?int
    {
        return $this->placeId;
    }

    /** The place as it read, or for a power the kind of target it was asked about. */
    public function getPlaceName(): string
    {
        return $this->placeName;
    }

    public function getPositionId(): ?int
    {
        return $this->positionId;
    }

    /** The position's name, or the person's for an answer that speaks for one. */
    public function getSubjectName(): string
    {
        return $this->subjectName;
    }

    public function getPersonId(): ?int
    {
        return $this->personId;
    }

    /** For a pair, the people who passed; for a power, 1 for allowed and 0 for refused. */
    public function getBefore(): int
    {
        return $this->before;
    }

    public function getAfter(): int
    {
        return $this->after;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedByName(): ?string
    {
        return $this->reviewedByName;
    }

    public function isReviewed(): bool
    {
        return null !== $this->reviewedAt;
    }

    /** Marked once; marking it again keeps the first look. */
    public function review(\DateTimeImmutable $at, string $byName): static
    {
        if (null === $this->reviewedAt) {
            $this->reviewedAt = $at;
            $this->reviewedByName = $byName;
        }

        return $this;
    }
}
