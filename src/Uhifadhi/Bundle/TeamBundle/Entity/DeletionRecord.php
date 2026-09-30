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
use Uhifadhi\Bundle\TeamBundle\Repository\DeletionRecordRepository;

/**
 * THE ONE LINE A DELETE LEAVES BEHIND (ruled 28 Sep, #48): who deleted what,
 * when, and what went with it. Everything else about the record is gone, so
 * this line keeps words, not links: the actor's name and the record's title
 * as they read at the moment, and the actor's id as a plain number, so a
 * later delete of the actor never rewrites the history of their deletes.
 */
#[ORM\Entity(repositoryClass: DeletionRecordRepository::class)]
#[ORM\Table(name: 'team_deletion')]
#[ORM\Index(name: 'idx_team_deletion_at', columns: ['deleted_at'])]
class DeletionRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    public function __construct(
        #[ORM\Column]
        private \DateTimeImmutable $deletedAt,
        #[ORM\Column(length: 160)]
        private string $byName,
        #[ORM\Column(nullable: true)]
        private ?int $byId,
        #[ORM\Column(length: 60)]
        private string $kind,
        #[ORM\Column(length: 160)]
        private string $reference,
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column(type: Types::TEXT)]
        private string $whatWent,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getByName(): string
    {
        return $this->byName;
    }

    public function getById(): ?int
    {
        return $this->byId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getWhatWent(): string
    {
        return $this->whatWent;
    }
}
