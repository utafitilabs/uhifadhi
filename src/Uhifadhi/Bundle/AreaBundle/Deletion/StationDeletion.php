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
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\StationEvent;
use Uhifadhi\Contracts\Deletion\DeletionContributorInterface;
use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/**
 * A STATION, DELETED BY A SUPER ADMIN (ruled 28 Sep, #48). Its postings and
 * its history go with it; the check-ins made at it stay, without it. For a
 * station that is merely closed, Deactivate keeps everything.
 */
final readonly class StationDeletion implements DeletionContributorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function supports(object $record): bool
    {
        return $record instanceof Station;
    }

    public function describe(object $record): DeletionSubject
    {
        \assert($record instanceof Station);
        $area = $record->getArea();
        $configure = $this->router->generate(StationConfigureController::ROUTE, ['uuid' => $area?->getUuidString()]);

        return new DeletionSubject(
            kind: 'station',
            reference: (string) $record->getName(),
            title: 'Station '.$record->getName().' · '.$area?->getName(),
            summary: trim(($record->getCode() ?? '').' · '.$area?->getName(), ' ·'),
            recordUrl: $configure,
            afterUrl: $configure,
            register: 'stations',
        );
    }

    public function whatGoes(object $record): array
    {
        \assert($record instanceof Station);

        return array_values(array_filter([
            new DeletionLine('stations', 1, singular: 'station'),
            new DeletionLine('postings', $this->count(Posting::class, 'station', $record), detail: 'standing and ended', singular: 'posting'),
            new DeletionLine('history lines', $this->count(StationEvent::class, 'station', $record), singular: 'history line'),
        ], static fn (DeletionLine $line): bool => $line->count > 0));
    }

    public function whatStays(object $record): array
    {
        \assert($record instanceof Station);
        $checkIns = $this->count(CheckIn::class, 'station', $record);

        return $checkIns > 0 ? [new DeletionLine('check-ins made at it', $checkIns, detail: 'stay, without the station', singular: 'check-in made at it')] : [];
    }

    public function delete(object $record): void
    {
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    /** @param class-string $entity */
    private function count(string $entity, string $field, Station $station): int
    {
        return (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(x) FROM %s x WHERE x.%s = :station', $entity, $field))
            ->setParameter('station', $station)->getSingleScalarResult();
    }
}
