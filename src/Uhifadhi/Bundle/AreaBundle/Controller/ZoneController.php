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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneListQuery;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneListService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverviewService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * THE ZONES HUB — the Overview and the register, two pages under one strip
 * (Overview · Zones). Picking one zone is {@see ZoneRecordController}.
 *
 * THE OVERVIEW STATES THE SET: the zones no station stands in in the banner,
 * the strip, four figures, the whole ground and two plots. It lists nothing;
 * the register does, and the banner's door (`?stations=none`) arrives there
 * already chosen.
 *
 * A LENS, NOT A FENCE. Reading how an area is divided is for anybody who may
 * see the area; every write lives on the configure section.
 */
final readonly class ZoneController
{
    public const string ROUTE = 'area_zones';
    public const string LIST_ROUTE = 'area_zones_list';

    public function __construct(
        private Environment $twig,
        private ZoneSetService $set,
        private StationRepository $stations,
        private PostingRepository $postings,
        private AreaPlateService $plates,
        private ZoneListService $zoneList,
        private ZoneOverviewService $overview,
        private TablePageService $pager,
    ) {
    }

    #[Route('/areas/{uuid}/zones', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('zones.read', subject: 'area')]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $view = $this->set->view($area);

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
            ];
        }

        return new Response($this->twig->render('@Area/zone/index.html.twig', [
            'area' => $area,
            'set' => $view,
            'overview' => $this->overview->overview($area, $view),
            'moduleCards' => ZoneOverviewService::MODULE_CARDS,
            'map' => $this->plates->picker($area, $view->rows, $posts),
        ]));
    }

    #[Route('/areas/{uuid}/zones/list', name: self::LIST_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('zones.read', subject: 'area')]
    public function list(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $query = ZoneListQuery::from($request);
        $size = StationsController::sizeFrom($request);
        $zones = $this->zoneList->register($area, $query, TablePage::ALL === $size ? \PHP_INT_MAX : (int) $size);
        $table = $this->pager->page($zones->total, $size, (string) $zones->page);

        return new Response($this->twig->render('@Area/zone/list.html.twig', [
            'area' => $area,
            'zones' => $zones,
            'query' => $query,
            'table' => $table,
            'links' => $this->pager->links($request, $table),
            'unzonedKm2' => $this->overview->unzonedKm2($area, $this->set->view($area)),
        ]));
    }
}
