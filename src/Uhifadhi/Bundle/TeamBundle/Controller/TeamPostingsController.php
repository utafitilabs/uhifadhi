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

namespace Uhifadhi\Bundle\TeamBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;
use Uhifadhi\Bundle\TeamBundle\Model\PostingQuery;
use Uhifadhi\Bundle\TeamBundle\Service\PostingBoard;

/**
 * THE POSTINGS TAB — who is posted to which station, across every area at
 * once.
 *
 * IT WRITES NOTHING, AND THE PAGE SAYS SO. A posting is made on the station,
 * in the area that owns the ground; Team reads it. That is why the only
 * control on the page that leaves it goes to the station, and why there is no
 * form here at all — a board that could post somebody would be a second write
 * path for a fact one screen already owns.
 *
 * IT IS THE ONE SCREEN IN THIS SECTION THAT CROSSES A BUNDLE BOUNDARY, and it
 * crosses read-only, through the seam: the ground publishes its stations and
 * the uuids standing at them, this bundle says who they are.
 */
final readonly class TeamPostingsController
{
    /** The section's fourth tab. */
    public const string POSTINGS = 'team_assignments';

    public function __construct(
        private Environment $twig,
        private PostingBoard $board,
        private TablePageService $tablePages,
    ) {
    }

    #[Route('/team/assignments', name: self::POSTINGS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted('directory.read')]
    public function index(Request $request): Response
    {
        $query = PostingQuery::from($request);
        $board = $this->board->read($query);
        $table = $this->tablePages->read($request, \count($board['lines']));

        return new Response($this->twig->render('@Team/team/postings.html.twig', [
            'query' => $query,
            ...$board,
            'lines' => $table->slice($board['lines']),
            'table' => $table,
            'links' => $this->tablePages->links($request, $table),
        ]));
    }
}
