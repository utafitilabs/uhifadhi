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

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Controller\StationRecordController;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Contracts\People\StationPlate;
use Uhifadhi\Contracts\People\StationPlateProviderInterface;

/**
 * THE AREA'S ANSWER TO "SHOW ME WHERE THEY ARE STATIONED" — the same plate the
 * station's own record draws, the ground around the post at the distance the
 * design draws it, rendered here and handed over as markup.
 *
 * IT IS THE STATION'S GROUND, so it is drawn only for somebody who may read
 * that station's area's stations; anybody else gets no plate at all.
 */
final readonly class AreaStationPlates implements StationPlateProviderInterface
{
    public function __construct(
        private Environment $twig,
        private StationRepository $stations,
        private PostingRepository $postings,
        private ZoneSetService $set,
        private AreaPlateService $plates,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function plateFor(string $stationUuid): ?StationPlate
    {
        if (!Uuid::isValid($stationUuid)) {
            return null;
        }
        $station = $this->stations->findOneBy(['uuid' => Uuid::fromString($stationUuid)]);
        if (null === $station) {
            return null;
        }
        $area = $station->getArea();
        if (null === $area || !$this->authorization->isGranted(StationRecordController::READ, $area)) {
            return null;
        }

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => $post->getId() === $station->getId(),
            ];
        }

        $map = $this->plates->focusOn(
            $this->plates->aroundStation($area, $this->set->view($area)->rows, $posts),
            $station->getPoint(),
            AreaPlateService::POST_ZOOM,
        );

        return new StationPlate($this->twig->render('@Area/station/_person_plate.html.twig', [
            'map' => $map,
            'station' => $station,
        ]));
    }
}
