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

namespace Uhifadhi\Bundle\RegistryBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\RegistryBundle\Entity\FigureFact;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactValue;

/**
 * THE FACTS LEDGER'S TABLE: the write a run makes and the rows a read asks for.
 *
 * THE WRITE IS AN UPSERT, one statement per figure, so a second run of the
 * same period replaces the row and two workers racing on it leave one row
 * rather than a unique violation:
 *
 *   "ON CONFLICT DO UPDATE guarantees an atomic INSERT or UPDATE outcome;
 *    provided there is no independent error, one of those two outcomes is
 *    guaranteed, even under high concurrency."
 *   "The SET and WHERE clauses in ON CONFLICT DO UPDATE have access to the
 *    existing row using the table's name (or an alias), and to the row
 *    proposed for insertion using the special excluded table."
 *   — https://www.postgresql.org/docs/current/sql-insert.html#SQL-ON-CONFLICT
 *
 * The conflict target is the table's primary key — "For ON CONFLICT DO
 * UPDATE, a conflict_target must be provided" (same page).
 *
 * DBAL, NOT THE UNIT OF WORK. The ORM has no upsert, and a find-then-persist
 * is two statements with a race between them; the entity is mapped only so
 * the schema is the migrations suite's to compare.
 *
 * NOT FINAL, like every repository the registry ships: a Doctrine repository
 * is the documented place to add a query.
 *
 * @extends ServiceEntityRepository<FigureFact>
 */
class FigureFactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FigureFact::class);
    }

    /**
     * FILE WHAT A RUN COMPUTED under one period, stamped with one time, in
     * one transaction: a run that fails half-way leaves the previous run's
     * figures, not a mixture.
     *
     * @param iterable<FactValue> $values
     *
     * @return int how many figures were written
     */
    public function upsert(iterable $values, string $periodKey, \DateTimeImmutable $computedAt): int
    {
        // A key that names no period is refused before anything is written.
        FactPeriod::fromKey($periodKey);

        $connection = $this->getEntityManager()->getConnection();
        $written = 0;

        $connection->transactional(static function () use ($connection, $values, $periodKey, $computedAt, &$written): void {
            foreach ($values as $value) {
                $connection->executeStatement(
                    'INSERT INTO figure_fact (subject_kind, subject_uuid, figure_key, period_key, value, computed_at)
                     VALUES (:kind, :subject, :figure, :period, :value, :computed)
                     ON CONFLICT (subject_kind, subject_uuid, figure_key, period_key)
                     DO UPDATE SET value = EXCLUDED.value, computed_at = EXCLUDED.computed_at',
                    [
                        'kind' => $value->subjectKind,
                        'subject' => $value->subjectUuid,
                        'figure' => $value->figureKey,
                        'period' => $periodKey,
                        'value' => $value->value,
                        'computed' => $computedAt,
                    ],
                    [
                        'value' => Types::FLOAT,
                        'computed' => Types::DATETIMETZ_IMMUTABLE,
                    ],
                );
                ++$written;
            }
        });

        return $written;
    }

    /**
     * THE STORED ROWS for some subjects, figures and periods — one index
     * range on the primary key.
     *
     * @param list<string> $subjectUuids
     * @param list<string> $figureKeys
     * @param list<string> $periodKeys
     *
     * @return list<Fact>
     */
    public function findBySubjects(string $subjectKind, array $subjectUuids, array $figureKeys, array $periodKeys): array
    {
        if ([] === $subjectUuids || [] === $figureKeys || [] === $periodKeys) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT subject_uuid, figure_key, period_key, value, computed_at
               FROM figure_fact
              WHERE subject_kind = :kind
                AND subject_uuid IN (:subjects)
                AND figure_key IN (:figures)
                AND period_key IN (:periods)',
            [
                'kind' => $subjectKind,
                'subjects' => $subjectUuids,
                'figures' => $figureKeys,
                'periods' => $periodKeys,
            ],
            [
                'subjects' => ArrayParameterType::STRING,
                'figures' => ArrayParameterType::STRING,
                'periods' => ArrayParameterType::STRING,
            ],
        );

        $platform = $connection->getDatabasePlatform();
        $instant = Type::getType(Types::DATETIMETZ_IMMUTABLE);
        $facts = [];

        foreach ($rows as $row) {
            ['subject_uuid' => $subject, 'figure_key' => $figure, 'period_key' => $period] = $row;
            \assert(\is_string($subject) && \is_string($figure) && \is_string($period));

            $computedAt = $instant->convertToPHPValue($row['computed_at'], $platform);
            \assert($computedAt instanceof \DateTimeImmutable);

            $facts[] = new Fact(
                $subjectKind,
                $subject,
                $figure,
                $period,
                is_numeric($row['value']) ? (float) $row['value'] : null,
                $computedAt,
            );
        }

        return $facts;
    }

    /**
     * WHEN ANY OF THESE FIGURES WAS LAST COMPUTED FOR A PERIOD, over every
     * subject — what tells the schedule whether a period that has ended was
     * computed once more after it ended.
     *
     * @param list<string> $figureKeys
     */
    public function getLastComputedAt(array $figureKeys, string $periodKey): ?\DateTimeImmutable
    {
        if ([] === $figureKeys) {
            return null;
        }

        $connection = $this->getEntityManager()->getConnection();
        $last = $connection->fetchOne(
            'SELECT MAX(computed_at) FROM figure_fact WHERE figure_key IN (:figures) AND period_key = :period',
            ['figures' => $figureKeys, 'period' => $periodKey],
            ['figures' => ArrayParameterType::STRING],
        );

        if (null === $last || false === $last) {
            return null;
        }

        $at = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($last, $connection->getDatabasePlatform());
        \assert($at instanceof \DateTimeImmutable);

        return $at;
    }
}
