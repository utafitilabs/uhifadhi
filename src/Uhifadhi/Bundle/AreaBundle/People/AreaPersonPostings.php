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

namespace Uhifadhi\Bundle\AreaBundle\People;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\StationRecordController;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\People\PersonPosting;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;

/**
 * THE AREA'S ANSWER TO "WHERE DOES THIS PERSON WORK?".
 *
 * IT IS A CONTRACT IMPLEMENTATION AND NOT A SERVICE, which is why it is named
 * for what it fulfils rather than what it does. The person's page belongs to
 * whoever owns people; the postings belong here; nothing in either bundle
 * names a class in the other, and this is the whole of the join.
 *
 * ONE QUERY FOR THE WHOLE PAGE. A register draws a page of people at a time,
 * so the request carries the set and this reads it in one go.
 *
 * THE LINE'S LINK IS A DOOR to the station's record, so it is handed over
 * only where the viewer may read that area's stations.
 */
final readonly class AreaPersonPostings implements PersonPostingProviderInterface
{
    public function __construct(
        private PostingRepository $postings,
        private UrlGeneratorInterface $router,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function postingsFor(array $userUuids): array
    {
        if ([] === $userUuids) {
            return [];
        }

        $byPerson = [];
        foreach ($this->postings->findStandingByPersonUuids($userUuids) as $posting) {
            $uuid = $posting->getPerson()?->getUuidString();
            if (null === $uuid) {
                continue;
            }

            $line = $this->describe($posting);
            if (null !== $line) {
                $byPerson[$uuid][] = $line;
            }
        }

        return $byPerson;
    }

    /**
     * A posting whose station or area has gone is not a line on anybody's
     * page. It cannot happen through the model — both are non-null columns —
     * but a reader that assumed so would be a reader that fataled to prove it.
     */
    private function describe(Posting $posting): ?PersonPosting
    {
        $station = $posting->getStation();
        $area = $station?->getArea();
        $since = $posting->getSince();

        if (null === $station || null === $area || null === $since) {
            return null;
        }

        return new PersonPosting(
            stationUuid: (string) $station->getUuidString(),
            stationName: (string) $station->getName(),
            stationCode: $station->getCode(),
            areaUuid: (string) $area->getUuidString(),
            areaName: (string) $area->getName(),
            zoneName: $station->getZone()?->getName(),
            since: $since,
            leader: $posting->isLeader(),
            url: $this->authorization->isGranted(StationRecordController::READ, $area)
                ? $this->router->generate(StationRecordController::ROUTE, ['uuid' => (string) $area->getUuidString(), 'station' => (string) $station->getUuidString()])
                : null,
        );
    }
}
