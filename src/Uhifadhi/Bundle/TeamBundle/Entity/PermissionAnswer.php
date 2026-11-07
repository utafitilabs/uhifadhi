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
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;

/**
 * ONE CELL A RUN FILED.
 *
 * FOR A PAIR: the holders of one position who pass every question the
 * GrantVoter asks, for one place — a count and their ids. Only cells with
 * somebody in them are filed; a cell not filed is nobody.
 *
 * FOR A POWER: one actor — a position, spoken for by one of its holders, or a
 * tier — about one kind of target, with the verdict and the question that
 * refused it. The count and ids are the people the answer speaks for when it
 * is allowed, and nobody when it is not.
 *
 * THE PEOPLE ARE A JSON LIST OF IDS. A cell is only ever read whole (the
 * people behind one Analysis cell) and never searched by person, so a list
 * in the row is one read where a row per person would be a hundred thousand
 * rows per run; the ids are plain numbers, like the history's.
 */
#[ORM\Entity(repositoryClass: PermissionAnswerRepository::class)]
#[ORM\Table(name: 'team_permission_answer')]
#[ORM\Index(name: 'idx_team_permission_answer_question', columns: ['run_id', 'question_kind', 'question_key'])]
class PermissionAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * @param list<int> $people
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: PermissionRun::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PermissionRun $run,
        #[ORM\Column(length: 10, enumType: QuestionKindEnum::class)]
        private QuestionKindEnum $questionKind,
        #[ORM\Column(length: 120)]
        private string $questionKey,
        #[ORM\Column(length: 20, nullable: true, enumType: PlaceKindEnum::class)]
        private ?PlaceKindEnum $placeKind,
        #[ORM\Column(nullable: true)]
        private ?int $placeId,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $target,
        #[ORM\Column(nullable: true)]
        private ?int $positionId,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $tier,
        #[ORM\Column(type: Types::JSON)]
        private array $people,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $verdict,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $refusedBy,
        #[ORM\Column]
        private int $peopleCount = 0,
    ) {
        $this->peopleCount = \count($people);
    }

    /** @param list<int> $people the holders who pass, never empty */
    public static function forPair(PermissionRun $run, string $pair, PlaceKindEnum $placeKind, ?int $placeId, ?int $positionId, array $people): self
    {
        return new self($run, QuestionKindEnum::Pair, $pair, $placeKind, $placeId, null, $positionId, null, $people, null, null);
    }

    /**
     * @param string    $verdict a CellAnswer value
     * @param list<int> $people  who the answer speaks for, when it is allowed
     */
    public static function forPower(PermissionRun $run, string $power, string $target, ?int $positionId, ?string $tier, string $verdict, ?string $refusedBy, array $people): self
    {
        return new self($run, QuestionKindEnum::Power, $power, null, null, $target, $positionId, $tier, $people, $verdict, $refusedBy);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): PermissionRun
    {
        return $this->run;
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

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getPositionId(): ?int
    {
        return $this->positionId;
    }

    public function getTier(): ?string
    {
        return $this->tier;
    }

    public function getPeopleCount(): int
    {
        return $this->peopleCount;
    }

    /** @return list<int> */
    public function getPeople(): array
    {
        return $this->people;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    public function getRefusedBy(): ?string
    {
        return $this->refusedBy;
    }
}
