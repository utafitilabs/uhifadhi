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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Service\RuleExceptionReview;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSectionOverview;

/**
 * THE TEAM SECTION'S FIRST TAB — who is on this installation, what each of
 * them may do, and where they are posted.
 *
 * IT WRITES NOTHING. Every figure on it belongs to the register, to Positions
 * or to the station in the area; the overview is a reading of them, which is
 * what makes it safe to open first.
 *
 * IT IS SEPARATE FROM THE REGISTER'S CONTROLLER on purpose. The register is a
 * widget surface with eight collaborators; a reading screen that shared its
 * constructor would drag all of them into a page that needs one.
 */
final readonly class TeamSectionController
{
    /** The section's first tab, and what its `Configure` returns you to. */
    public const string OVERVIEW = 'team_overview';

    public function __construct(
        private Environment $twig,
        private TeamSectionOverview $overview,
        private ?RuleExceptionReview $review = null,
    ) {
    }

    #[Route('/team/overview', name: self::OVERVIEW, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted('directory.read')]
    public function overview(): Response
    {
        return new Response($this->twig->render('@Team/team/overview.html.twig', [
            ...$this->overview->read(),
            'exceptionReview' => $this->review?->read(),
        ]));
    }
}
