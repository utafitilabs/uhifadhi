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
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunStatusEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;

/**
 * ONE RUN OF THE PERMISSIONS WORKER: who or what asked for it, when it ran,
 * what it asked about, and how many of each thing it filed.
 *
 * A REQUEST IS A PENDING RUN. Asking for a run writes this row; the worker
 * starts it and files its answers, findings and drift against it. The run
 * rows are kept; the answers and findings of every run but the latest two
 * finished ones are pruned (see PermissionRunService).
 *
 * IT KEEPS WORDS, NOT LINKS, like the history: who asked is a plain id and
 * the name as it read, so removing that account never rewrites the run.
 */
#[ORM\Entity(repositoryClass: PermissionRunRepository::class)]
#[ORM\Table(name: 'team_permission_run')]
#[ORM\Index(name: 'idx_team_permission_run_status', columns: ['status', 'started_at'])]
class PermissionRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 20, enumType: PermissionRunStatusEnum::class)]
    private PermissionRunStatusEnum $status = PermissionRunStatusEnum::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * The places the run asked about, as they read when it ran.
     *
     * @var list<array{kind: string, id: int|null, name: string}>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $places = [];

    /**
     * The pairs the catalogue offered when it ran: a pair absent here was not
     * asked, which is different from a pair nobody holds.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $pairs = [];

    /**
     * What it counted: people, staff, superAdmins, admins, questions (voter
     * calls), shapes, answers, findings, drift, and ms, how long it ran.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $counts = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failure = null;

    public function __construct(
        #[ORM\Column(name: 'run_trigger', length: 20, enumType: PermissionRunTriggerEnum::class)]
        private PermissionRunTriggerEnum $trigger,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $requestedAt,
        #[ORM\Column(nullable: true)]
        private ?int $requestedById = null,
        #[ORM\Column(length: 160, nullable: true)]
        private ?string $requestedByName = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrigger(): PermissionRunTriggerEnum
    {
        return $this->trigger;
    }

    public function getStatus(): PermissionRunStatusEnum
    {
        return $this->status;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getRequestedById(): ?int
    {
        return $this->requestedById;
    }

    public function getRequestedByName(): ?string
    {
        return $this->requestedByName;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return list<array{kind: string, id: int|null, name: string}> */
    public function getPlaces(): array
    {
        return $this->places;
    }

    /** @return list<string> */
    public function getPairs(): array
    {
        return $this->pairs;
    }

    /** @return array<string, int> */
    public function getCounts(): array
    {
        return $this->counts;
    }

    public function getCount(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    /** How long it ran: measured by the run itself, else from the two instants, which are kept to the second. */
    public function getDurationMs(): ?int
    {
        if (isset($this->counts['ms'])) {
            return $this->counts['ms'];
        }
        if (null === $this->startedAt || null === $this->finishedAt) {
            return null;
        }

        return (int) round(((float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000);
    }

    /**
     * @param list<array{kind: string, id: int|null, name: string}> $places
     * @param list<string>                                          $pairs
     */
    public function start(\DateTimeImmutable $at, array $places, array $pairs): static
    {
        $this->status = PermissionRunStatusEnum::Running;
        $this->startedAt = $at;
        $this->places = $places;
        $this->pairs = $pairs;

        return $this;
    }

    /** @param array<string, int> $counts */
    public function finish(\DateTimeImmutable $at, array $counts): static
    {
        $this->status = PermissionRunStatusEnum::Finished;
        $this->finishedAt = $at;
        $this->counts = $counts;

        return $this;
    }

    public function fail(\DateTimeImmutable $at, string $failure): static
    {
        $this->status = PermissionRunStatusEnum::Failed;
        $this->finishedAt = $at;
        $this->failure = $failure;

        return $this;
    }

    /** Taken along by a run that started after it was asked for. */
    public function supersede(\DateTimeImmutable $at): static
    {
        $this->status = PermissionRunStatusEnum::Superseded;
        $this->finishedAt = $at;

        return $this;
    }
}
