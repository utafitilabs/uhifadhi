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
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\StationPostingsService;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * WHO IS POSTED WHERE — the third page of the stations hub.
 *
 * It reads: posting somebody happens on the station's record, and the Team
 * reads the posting, never writes it. The address is the question
 * (`?zone=&station=&position=&q=`), paged by the house foot.
 */
final readonly class StationPostingsController
{
    public const string ROUTE = 'area_stations_postings';

    /**
     * THE ORGANIZATION'S POSTINGS, the foot's door, offered only where an
     * installation mounts it: this bundle does not require the one that does.
     */
    private const string EVERY_AREA = 'team_assignments';

    public function __construct(
        private Environment $twig,
        private StationPostingsService $postings,
        private RouterInterface $router,
        private TablePageService $pager,
    ) {
    }

    #[Route('/areas/{uuid}/stations/postings', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('stations.read', subject: 'area')]
    public function index(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $ask = [
            StationPostingsService::ZONE => self::answer($request, StationPostingsService::ZONE),
            StationPostingsService::STATION => self::answer($request, StationPostingsService::STATION),
            StationPostingsService::POSITION => self::answer($request, StationPostingsService::POSITION),
            StationPostingsService::SEARCH => trim($request->query->getString(StationPostingsService::SEARCH)),
        ];
        $board = $this->postings->postings($area, $ask);

        $table = $this->pager->read($request, $board['total']);

        return new Response($this->twig->render('@Area/station/postings.html.twig', [
            'area' => $area,
            'board' => $board,
            'rows' => $table->slice($board['rows']),
            'ask' => $ask,
            'table' => $table,
            'links' => $this->pager->links($request, $table),
            'teamPostings' => null === $this->router->getRouteCollection()->get(self::EVERY_AREA) ? null : $this->router->generate(self::EVERY_AREA),
        ]));
    }

    private static function answer(Request $request, string $key): ?string
    {
        $value = trim($request->query->getString($key));

        return '' === $value ? null : $value;
    }
}
