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

namespace Uhifadhi\Bundle\AreaBundle\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * AN AREA, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48): everything under it
 * goes in the same delete - its stations and zones, the duty recorded in it,
 * and whatever each module keeps there, which each module counts for itself.
 */
final readonly class AreaDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof AreaOfInterest;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof AreaOfInterest);

        return new DeletionSubject(
            kind: 'area',
            reference: (string) $record->getName(),
            title: 'Area '.$record->getName(),
            summary: \sprintf('%d stations · %d zones', $this->count(Station::class, $record), $this->count(Zone::class, $record)),
            recordUrl: $this->router->generate('area_show', ['uuid' => $record->getUuidString()]),
            afterUrl: $this->router->generate('area_index'),
            register: 'areas',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof AreaOfInterest);

        return array_values(array_filter([
            new DeletionLine('areas', 1, detail: 'and its boundary', singular: 'area'),
            new DeletionLine('stations', $this->count(Station::class, $record), detail: 'with their postings', singular: 'station'),
            new DeletionLine('zones', $this->count(Zone::class, $record), singular: 'zone'),
            new DeletionLine('check-ins', $this->count(CheckIn::class, $record), singular: 'check-in'),
            new DeletionLine('position reports', $this->count(PersonPosition::class, $record), singular: 'position report'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    public function delete(object $record): void
    {
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, AreaOfInterest $area): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.area = :area', $entity))
            ->setParameter('area', $area)->getSingleScalarResult();
    }
}
