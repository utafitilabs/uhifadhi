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
use Uhifadhi\Bundle\AreaBundle\Model\PostingQuery;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingBoardService;
use Uhifadhi\Bundle\AreaBundle\Service\StationOverviewService;
use Uhifadhi\Bundle\AreaBundle\Service\StationRegisterService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\ShellBundle\Model\TablePage;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * THE STATIONS HUB — the Overview and the register, two pages under one strip
 * (Overview · Stations · Postings; the third is StationPostingsController).
 *
 * THE OVERVIEW STATES THE SET: the stations nobody is posted to in the banner,
 * the strip, four figures, every station on the ground and two plots. It
 * lists nothing; the register does.
 *
 * THE REGISTER IS ONE FLAT TABLE, FOUND BY FILTER. There is no zone-grouped
 * twin: the way an operator arrives is a name or a code, so the zone is a
 * filter here, and a column. The address is the question, so the banner's
 * door (`?posted=no`) arrives already chosen.
 */
final readonly class StationsController
{
    public const string ROUTE = 'area_stations';
    public const string LIST_ROUTE = 'area_stations_list';

    public function __construct(
        private Environment $twig,
        private StationRegisterService $register,
        private StationRepository $stations,
        private PostingRepository $postings,
        private PostingBoardService $board,
        private ZoneSetService $set,
        private AreaPlateService $plates,
        private StationOverviewService $overview,
        private TablePageService $pager,
    ) {
    }

    #[Route('/areas/{uuid}/stations', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('stations.read', subject: 'area')]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $view = $this->set->view($area);

        $cats = [];
        foreach ($view->rows as $zone) {
            $cats[$zone->name] = $zone->cat;
        }

        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $zone = $post->getZone()?->getName();
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
                'zone' => $zone,
                'cat' => null === $zone ? null : ($cats[$zone] ?? null),
            ];
        }

        return new Response($this->twig->render('@Area/station/index.html.twig', [
            'area' => $area,
            'overview' => $this->overview->overview($area),
            'moduleCards' => StationOverviewService::MODULE_CARDS,
            'map' => [] === $posts ? null : $this->plates->stationsPlate($area, $view->rows, $posts),
        ]));
    }

    #[Route('/areas/{uuid}/stations/list', name: self::LIST_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('stations.read', subject: 'area')]
    public function list(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $query = StationConfigureController::queryFrom($request);
        $size = self::sizeFrom($request);
        $register = $this->register->register($area, $query, TablePage::ALL === $size ? \PHP_INT_MAX : (int) $size);
        $table = $this->pager->page($register->total, $size, (string) $register->page);

        $leads = [];
        foreach ($this->board->board($this->postings->findStandingByArea($area), new PostingQuery())['rows'] as $row) {
            if ($row->leader) {
                $leads[$row->stationUuid] = $row->name;
            }
        }

        return new Response($this->twig->render('@Area/station/list.html.twig', [
            'area' => $area,
            'register' => $register,
            'query' => $query,
            'table' => $table,
            'links' => $this->pager->links($request, $table),
            'leads' => $leads,
        ]));
    }

    /** The foot's page size from the address, twenty where it names none it offers. */
    public static function sizeFrom(Request $request): string
    {
        $size = $request->query->getString(TablePageService::PER, TablePage::DEFAULT_SIZE);

        return \in_array($size, TablePage::SIZES, true) ? $size : TablePage::DEFAULT_SIZE;
    }
}
