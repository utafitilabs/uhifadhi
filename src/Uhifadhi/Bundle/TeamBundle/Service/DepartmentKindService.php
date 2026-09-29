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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentKind;
use Uhifadhi\Bundle\TeamBundle\Exception\DuplicateDepartmentKindException;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentKindRepository;

/**
 * THE ONE DOOR A DEPARTMENT KIND IS WRITTEN THROUGH.
 *
 * A KIND IS A WORD, AND THE RULES ABOUT IT ARE RULES ABOUT WORDS: it is
 * trimmed, it is not empty, and no two kinds share one. They live here rather
 * than in the screen that happens to ask, so the day a second screen asks —
 * an importer, a console command, the devkit's seed content — it gets the same
 * answer instead of a second opinion.
 *
 * AN EMPTY MEANING IS NULL, NOT "". The column is nullable because "nobody has
 * said what this means" is a real state; storing the empty string for it gives
 * every reader two ways to spell the same absence.
 */
final readonly class DepartmentKindService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DepartmentKindRepository $kinds,
    ) {
    }

    /**
     * @throws DuplicateDepartmentKindException when the name is already a kind
     */
    public function create(string $name, string $meaning): DepartmentKind
    {
        $name = trim($name);
        $this->refuseADuplicate($name, null);

        $kind = new DepartmentKind($name, self::meaning($meaning));
        $this->entityManager->persist($kind);
        $this->entityManager->flush();

        return $kind;
    }

    /**
     * @throws DuplicateDepartmentKindException when another kind holds the name
     */
    public function rename(DepartmentKind $kind, string $name, string $meaning): DepartmentKind
    {
        $name = trim($name);
        $this->refuseADuplicate($name, $kind);

        $kind->setName($name)->setMeaning(self::meaning($meaning));
        $this->entityManager->flush();

        return $kind;
    }

    private function refuseADuplicate(string $name, ?DepartmentKind $itself): void
    {
        $clash = $this->kinds->findOneByName($name);

        if (null !== $clash && $clash->getId() !== $itself?->getId()) {
            throw new DuplicateDepartmentKindException($name);
        }
    }

    private static function meaning(string $meaning): ?string
    {
        $meaning = trim($meaning);

        return '' === $meaning ? null : $meaning;
    }
}
