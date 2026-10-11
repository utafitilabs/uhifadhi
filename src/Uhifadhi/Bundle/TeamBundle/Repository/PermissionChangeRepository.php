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

namespace Uhifadhi\Bundle\TeamBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE PERMISSION HISTORY, read newest first: everybody's, one person's — the
 * changes they made and the changes made to them — or the changes behind one
 * of the Permissions page's main powers.
 *
 * @extends ServiceEntityRepository<PermissionChange>
 */
class PermissionChangeRepository extends ServiceEntityRepository
{
    /**
     * WHICH CHANGES STAND BEHIND A MAIN POWER: the uses of it a line records.
     * A power absent here — switching into somebody, changing their check-in —
     * changes nobody's access, and has no line.
     */
    private const array BEHIND = [
        'make-admin' => [PermissionChangeEnum::TierChanged],
        'make-super-admin' => [PermissionChangeEnum::TierChanged],
        'raise-their-own-tier' => [PermissionChangeEnum::TierChanged],
        'seat-themselves-in-a-stronger-position' => [PermissionChangeEnum::Seated],
        'add-a-pair-to-a-position' => [PermissionChangeEnum::GrantsChanged],
        'give-live-locations' => [PermissionChangeEnum::ExceptionGiven, PermissionChangeEnum::ExceptionTaken],
        'send-a-reset-link' => [PermissionChangeEnum::ResetLinkSent],
        'change-their-email' => [PermissionChangeEnum::EmailChanged],
        'issue-a-one-time-password' => [PermissionChangeEnum::OneTimePasswordIssued],
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionChange::class);
    }

    /** Whether a line can stand behind this power at all. */
    public static function hasLines(string $power): bool
    {
        return isset(self::BEHIND[$power]);
    }

    /** @return list<PermissionChange> */
    public function findPage(int $offset, int $limit): array
    {
        return $this->rows($this->newest(), $offset, $limit);
    }

    public function countAll(): int
    {
        return $this->total($this->newest());
    }

    /**
     * The changes a person made, and the changes made to them.
     *
     * @return list<PermissionChange>
     */
    public function findByOrOnPerson(int $personId, string $personUuid, int $limit): array
    {
        return $this->rows($this->byOrOn($personId, $personUuid), 0, $limit);
    }

    public function countByOrOnPerson(int $personId, string $personUuid): int
    {
        return $this->total($this->byOrOn($personId, $personUuid));
    }

    /**
     * The changes made to one record: a person, a position or a department.
     *
     * @return list<PermissionChange>
     */
    public function findOnSubject(string $kind, string $uuid): array
    {
        return $this->rows($this->newest()->andWhere('c.subjectKind = :kind')->andWhere('c.subjectUuid = :uuid')
            ->setParameter('kind', $kind)->setParameter('uuid', $uuid), 0, null);
    }

    /** @return list<PermissionChange> */
    public function findBehind(string $power, int $limit, ?\DateTimeImmutable $since = null): array
    {
        $query = $this->behind($power, $since);

        return null === $query ? [] : $this->rows($query, 0, $limit);
    }

    public function countBehind(string $power, ?\DateTimeImmutable $since = null): int
    {
        $query = $this->behind($power, $since);

        return null === $query ? 0 : $this->total($query);
    }

    private function newest(): QueryBuilder
    {
        return $this->createQueryBuilder('c')->orderBy('c.recordedAt', 'DESC')->addOrderBy('c.id', 'DESC');
    }

    private function byOrOn(int $personId, string $personUuid): QueryBuilder
    {
        $query = $this->newest();

        return $query->andWhere($query->expr()->orX('c.byId = :id', $query->expr()->andX('c.subjectKind = :person', 'c.subjectUuid = :uuid')))
            ->setParameter('id', $personId)->setParameter('person', PermissionChange::PERSON)->setParameter('uuid', $personUuid);
    }

    private function behind(string $power, ?\DateTimeImmutable $since): ?QueryBuilder
    {
        $actions = self::BEHIND[$power] ?? null;
        if (null === $actions) {
            return null;
        }

        $query = $this->newest()->andWhere('c.action IN (:actions)')->setParameter('actions', $actions);
        if (null !== $since) {
            $query->andWhere('c.recordedAt >= :since')->setParameter('since', $since);
        }

        // A TIER LINE STANDS BEHIND THE POWER OVER THE TIER IT MOVED TO OR FROM,
        // and behind raising one's own only when the person moved was the mover.
        match ($power) {
            'make-admin' => $this->eitherSide($query, TeamRoleEnum::Admin->label()),
            'make-super-admin' => $this->eitherSide($query, TeamRoleEnum::SuperAdmin->label()),
            'raise-their-own-tier', 'seat-themselves-in-a-stronger-position' => $query->andWhere('c.byId = c.subjectId')->andWhere('c.subjectKind = :person')->setParameter('person', PermissionChange::PERSON),
            default => null,
        };

        return $query;
    }

    private function eitherSide(QueryBuilder $query, string $tier): void
    {
        $query->andWhere($query->expr()->orX('c.before = :tier', 'c.after = :tier'))->setParameter('tier', $tier);
    }

    /** @return list<PermissionChange> */
    private function rows(QueryBuilder $query, int $offset, ?int $limit): array
    {
        /** @var list<PermissionChange> $rows */
        $rows = $query->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $rows;
    }

    private function total(QueryBuilder $query): int
    {
        return (int) (clone $query)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
    }
}
