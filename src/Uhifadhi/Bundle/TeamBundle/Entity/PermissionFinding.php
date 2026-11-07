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
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;

/**
 * ONE ENTRY ON ONE OF A RUN'S SEVEN STANDING LISTS: who or what it is about,
 * and the words the list shows beside it — the pair, why, who wrote the
 * reason and when, or since when a position has been vacant. A self-change
 * keeps its own columns: the kind of change, what changed, its value before
 * and after, and when.
 */
#[ORM\Entity(repositoryClass: PermissionFindingRepository::class)]
#[ORM\Table(name: 'team_permission_finding')]
#[ORM\Index(name: 'idx_team_permission_finding_list', columns: ['run_id', 'list'])]
class PermissionFinding
{
    public const string PERSON = 'person';
    public const string POSITION = 'position';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermissionRun::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PermissionRun $run,
        #[ORM\Column(length: 20, enumType: FindingListEnum::class)]
        private FindingListEnum $list,
        #[ORM\Column(length: 20)]
        private string $subjectKind,
        #[ORM\Column(nullable: true)]
        private ?int $subjectId,
        #[ORM\Column(length: 160)]
        private string $subjectName,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $positionName = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $pair = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $detail = null,
        #[ORM\Column(length: 160, nullable: true)]
        private ?string $byName = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $at = null,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $changeKind = null,
        #[ORM\Column(length: 20, nullable: true)]
        private ?string $what = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $beforeValue = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $afterValue = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $changedAt = null,
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

    public function getList(): FindingListEnum
    {
        return $this->list;
    }

    public function getSubjectKind(): string
    {
        return $this->subjectKind;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectName(): string
    {
        return $this->subjectName;
    }

    public function getPositionName(): ?string
    {
        return $this->positionName;
    }

    public function getPair(): ?string
    {
        return $this->pair;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getByName(): ?string
    {
        return $this->byName;
    }

    public function getAt(): ?\DateTimeImmutable
    {
        return $this->at;
    }

    public function getChangeKind(): ?string
    {
        return $this->changeKind;
    }

    public function getWhat(): ?string
    {
        return $this->what;
    }

    public function getBeforeValue(): ?string
    {
        return $this->beforeValue;
    }

    public function getAfterValue(): ?string
    {
        return $this->afterValue;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }
}
