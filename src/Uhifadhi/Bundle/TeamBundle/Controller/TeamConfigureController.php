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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Enum\InvitationUnitEnum;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * HOW THE TEAM SECTION IS SET UP — three configure sections, one address each.
 *
 * AN ORG-LEVEL SECTION'S CONFIGURE SCREENS ARE ITS OWN ROUTES. The shell's
 * configure page renders a section into an AREA's frame, and this section has
 * no area in its address; so the sections are addresses here, declared to the
 * shell through the sections contract, and the shell builds the strip from
 * them. That is a difference of address, not of idiom: the strip, the lit
 * Configure action and the crumb are the frame's.
 *
 * THE TEST A CARD HAS TO PASS TO BE HERE (ruled 2026-09-24): it sets a rule
 * for the WHOLE team. A fact about one person, one position or one posting is
 * that record's own page; a restatement of the permission model is the matrix;
 * a rule the model fixes is not a field. So there are three sections and one
 * card on two of them:
 *
 *   PEOPLE        the invitation rules — how long a link lives, how many uses
 *                 it has, whether somebody may be created with a password.
 *   POSITIONS     adding a position — creation is configuration, the register
 *                 shows what exists. The create card posts to the register's
 *                 own write, so it carries that write's token.
 *   ASSIGNMENTS   the stationing rules — what every posting an area writes is
 *                 held to. Team never writes a posting; the area does.
 *
 * THERE IS NO ROLES SECTION: the tiers are the model's and held on the
 * person, the matrix is edited on the position. And the sign-in policy is the
 * installation's, under Settings › Organization, not a Team rule.
 *
 * EACH CARD IS A FORM. The current value sits in its control and the primary
 * action is in the save row; each save writes its own card's rules and no
 * other's.
 */
final readonly class TeamConfigureController
{
    /** The three sections, in the order the strip reads them. */
    public const string PEOPLE = 'team_configure_people';
    public const string POSITIONS = 'team_configure_positions';
    public const string ASSIGNMENTS = 'team_configure_assignments';

    /** The invitation rules and the stationing rules are directory rules; adding a position is composing positions. */
    public const string DIRECTORY = TeamConcerns::DIRECTORY.'.'.Verb::Manage->value;

    public const string CSRF_ID = 'team_settings';

    public function __construct(
        private Environment $twig,
        private TeamSettingsService $settings,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/team/configure/people', name: self::PEOPLE, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::DIRECTORY)]
    public function people(): Response
    {
        return new Response($this->twig->render('@Team/team/configure/people.html.twig', [
            'rules' => $this->settings->current(),
            'units' => InvitationUnitEnum::cases(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /** THE INVITATION RULES, SAVED — the one write on the People section. */
    #[Route('/team/configure/people', name: 'team_configure_people_save', methods: ['POST'])]
    #[IsGranted(self::DIRECTORY)]
    public function savePeople(Request $request): Response
    {
        $this->assertCsrf($request);

        $unit = InvitationUnitEnum::tryFrom((string) $request->request->get('validUnit'));
        if (null === $unit) {
            return $this->back($request, self::PEOPLE, 'An invitation is valid for a number of days or of hours.', 'error');
        }

        try {
            $this->settings->setInvitationRules(
                $this->number($request, 'validAmount'),
                $unit,
                $this->number($request, 'uses'),
                'allowed' === $request->request->get('withPassword'),
            );
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, self::PEOPLE, $refusal->getMessage(), 'error');
        }

        return $this->back($request, self::PEOPLE, 'Saved. Applies to every invitation sent from now on.');
    }

    /**
     * ADDING A POSITION. The create card posts to the positions register's
     * own write ({@see PositionController::create()}), so this screen carries
     * that write's token and nothing of its own.
     */
    #[Route('/team/configure/positions', name: self::POSITIONS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(PositionController::CONFIGURE)]
    public function positions(): Response
    {
        return new Response($this->twig->render('@Team/team/configure/positions.html.twig', [
            // WHAT A PLACEMENT MAY BE MADE AT, and the whole of it: at the
            // organization or at named areas. Department is the placement's
            // other dimension and `own` is a scope a concern offers.
            'placeableKinds' => [ScopeKind::Organization, ScopeKind::Area],
            'csrfToken' => $this->csrf->getToken(PositionController::CSRF_ID)->getValue(),
        ]));
    }

    #[Route('/team/configure/assignments', name: self::ASSIGNMENTS, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::DIRECTORY)]
    public function assignments(): Response
    {
        return new Response($this->twig->render('@Team/team/configure/assignments.html.twig', [
            'rules' => $this->settings->current(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /** THE STATIONING RULES, SAVED — the one write on the Assignments section. */
    #[Route('/team/configure/assignments', name: 'team_configure_assignments_save', methods: ['POST'])]
    #[IsGranted(self::DIRECTORY)]
    public function saveAssignments(Request $request): Response
    {
        $this->assertCsrf($request);

        try {
            $this->settings->setStationingRules(
                'allowed' === $request->request->get('twoStations'),
                $this->number($request, 'leaders'),
                'allowed' === $request->request->get('emptyStation'),
            );
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, self::ASSIGNMENTS, $refusal->getMessage(), 'error');
        }

        return $this->back($request, self::ASSIGNMENTS, 'Saved. Applies to every posting written in an area from now on.');
    }

    /** A posted count, or nought for anything that is not one — the service says what is too few. */
    private function number(Request $request, string $field): int
    {
        $posted = $request->request->get($field);

        return is_numeric($posted) ? (int) $posted : 0;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    /** BACK TO THE CARD THE EDIT WAS MADE ON, so the sentence is read beside the thing it is about. */
    private function back(Request $request, string $section, string $message, string $kind = 'success'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        return new RedirectResponse($this->router->generate($section));
    }
}
