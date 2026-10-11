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
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionChangeRepository;

/**
 * ONE CHANGE TO WHO MAY DO WHAT (ruled 11 Oct 2026, #75): when, who, on whom
 * or what, the value before and after, and the reason where the change asks
 * for one.
 *
 * IT IS APPEND-ONLY. Nothing here has a setter, and nothing removes a row:
 * a history that can be edited proves nothing, and one that is pruned cannot
 * answer "who gave this person Admin" a year on.
 *
 * IT KEEPS WORDS, NOT LINKS. The names are copied as they read at the time
 * and the ids are plain numbers, so a later rename or delete of the actor or
 * the record never rewrites what happened; the uuid lets a page link to a
 * record that still exists.
 */
#[ORM\Entity(repositoryClass: PermissionChangeRepository::class)]
#[ORM\Table(name: 'team_permission_change')]
#[ORM\Index(name: 'idx_team_permission_change_at', columns: ['recorded_at'])]
#[ORM\Index(name: 'idx_team_permission_change_subject', columns: ['subject_kind', 'subject_id'])]
#[ORM\Index(name: 'idx_team_permission_change_by', columns: ['by_id'])]
class PermissionChange
{
    public const string PERSON = 'person';
    public const string POSITION = 'position';
    public const string DEPARTMENT = 'department';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $recordedAt,
        #[ORM\Column(nullable: true)]
        private ?int $byId,
        #[ORM\Column(length: 160)]
        private string $byName,
        #[ORM\Column(length: 40, enumType: PermissionChangeEnum::class)]
        private PermissionChangeEnum $action,
        #[ORM\Column(length: 20)]
        private string $subjectKind,
        #[ORM\Column(nullable: true)]
        private ?int $subjectId,
        #[ORM\Column(length: 36, nullable: true)]
        private ?string $subjectUuid,
        #[ORM\Column(length: 160)]
        private string $subjectName,
        #[ORM\Column(name: 'value_before', type: Types::TEXT, nullable: true)]
        private ?string $before = null,
        #[ORM\Column(name: 'value_after', type: Types::TEXT, nullable: true)]
        private ?string $after = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $reason = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getById(): ?int
    {
        return $this->byId;
    }

    public function getByName(): string
    {
        return $this->byName;
    }

    public function getAction(): PermissionChangeEnum
    {
        return $this->action;
    }

    public function getSubjectKind(): string
    {
        return $this->subjectKind;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function getSubjectUuid(): ?string
    {
        return $this->subjectUuid;
    }

    public function getSubjectName(): string
    {
        return $this->subjectName;
    }

    public function getBefore(): ?string
    {
        return $this->before;
    }

    public function getAfter(): ?string
    {
        return $this->after;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
