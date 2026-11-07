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
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;

/**
 * THE CELLS A RUN FILED, read one question at a time — which is how every
 * page asks for them.
 *
 * @extends ServiceEntityRepository<PermissionAnswer>
 */
class PermissionAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionAnswer::class);
    }

    /** @return list<PermissionAnswer> */
    public function findByRunAndQuestion(PermissionRun $run, QuestionKindEnum $kind, string $key): array
    {
        return $this->findBy(['run' => $run, 'questionKind' => $kind, 'questionKey' => $key], ['id' => 'ASC']);
    }

    /** @return list<PermissionAnswer> every cell of the run, for comparing two runs */
    public function findByRun(PermissionRun $run): array
    {
        return $this->findBy(['run' => $run], ['id' => 'ASC']);
    }

    /** @return list<PermissionAnswer> every pair cell filed for one place */
    public function findByRunAndPlace(PermissionRun $run, PlaceKindEnum $placeKind, ?int $placeId): array
    {
        return $this->findBy(['run' => $run, 'questionKind' => QuestionKindEnum::Pair, 'placeKind' => $placeKind, 'placeId' => $placeId], ['id' => 'ASC']);
    }

    public function findOneCell(PermissionRun $run, string $pair, PlaceKindEnum $placeKind, ?int $placeId, int $positionId): ?PermissionAnswer
    {
        return $this->findOneBy([
            'run' => $run,
            'questionKind' => QuestionKindEnum::Pair,
            'questionKey' => $pair,
            'placeKind' => $placeKind,
            'placeId' => $placeId,
            'positionId' => $positionId,
        ]);
    }

    /** @return list<string> the distinct questions the run filed an answer for */
    public function findQuestionKeysByRun(PermissionRun $run, QuestionKindEnum $kind): array
    {
        /** @var list<array{questionKey: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.questionKey')
            ->andWhere('a.run = :run')
            ->andWhere('a.questionKind = :kind')
            ->setParameter('run', $run)
            ->setParameter('kind', $kind)
            ->orderBy('a.questionKey', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['questionKey'], $rows);
    }

    /**
     * HOW MANY ANSWERS THE RUN FILED FOR EACH QUESTION, keyed by the question
     * ("surveys.record", or a power's key); a question with none is absent.
     *
     * @return array<string, int>
     */
    public function countByRunAndKind(PermissionRun $run, QuestionKindEnum $kind): array
    {
        /** @var list<array{questionKey: string, answers: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.questionKey, COUNT(a.id) AS answers')
            ->andWhere('a.run = :run')
            ->andWhere('a.questionKind = :kind')
            ->setParameter('run', $run)
            ->setParameter('kind', $kind)
            ->groupBy('a.questionKey')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['questionKey']] = (int) $row['answers'];
        }

        return $counts;
    }

    public function countByRun(PermissionRun $run): int
    {
        return $this->count(['run' => $run]);
    }

    public function deleteByRun(PermissionRun $run): int
    {
        $deleted = $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.run = :run')
            ->setParameter('run', $run)
            ->getQuery()
            ->execute();

        return \is_int($deleted) ? $deleted : 0;
    }
}
