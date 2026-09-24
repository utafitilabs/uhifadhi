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
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Exception\NameNotUniqueException;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionHeldException;
use Uhifadhi\Bundle\TeamBundle\Exception\SeatsBelowHoldersException;
use Uhifadhi\Bundle\TeamBundle\Exception\UnknownGrantException;
use Uhifadhi\Bundle\TeamBundle\Model\PositionCard;
use Uhifadhi\Bundle\TeamBundle\Model\PositionQuery;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Bundle\TeamBundle\Service\PositionBoard;
use Uhifadhi\Bundle\TeamBundle\Service\PositionHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * POSITIONS AND GRANTS — the heart of this bundle, as three screens.
 *
 * A POSITION IS THE ONLY THING THAT GRANTS A STAFF MEMBER ANY CAPABILITY AT
 * ALL. A position belongs to no department, and its name is unique across
 * the whole organization: there is one Sergeant, and where each holder works
 * is written on their own record.
 *
 * THE THREE SCREENS, AND WHY THEY ARE THREE.
 *
 *   THE REGISTER is one collapsible card per position, on the department
 *   register's idiom: seats and holders in the head, the concern chips
 *   grouped by declaring module in the body, the verb summary and the holder
 *   avatars in the foot. It is a PREVIEW and never an editor.
 *
 *   THE RECORD wears the station record's skeleton — header, fact band, one
 *   `.recgrid` of cards — and carries the matrix READ-ONLY, with the holders
 *   and the history beside it. No tabs, and no uuid on the page: an
 *   identifier nobody types is not a fact a reader needs.
 *
 *   CONFIGURE mirrors the record: the same skeleton, the same split, ONE
 *   page. Everything a position can be changed to is on it — the identity,
 *   the matrix, and retiring it — with the holders beside the editor,
 *   because changing what this position grants changes what those people may
 *   do.
 *
 * A GRANT IS A (CONCERN, VERB) PAIR, AND THE MATRIX IS DRAWN FROM THE
 * DECLARATIONS. One group per DECLARER, a row per CONCERN, a box only where
 * the concern declares the verb — so there is never a checkbox that would
 * mean nothing, and no list of permissions is maintained by hand in the
 * middle of the product.
 *
 * WHAT THERE IS TO GRANT IS NOT FIXED. The team's concerns are this
 * bundle's; the rest arrive when a module is installed and leave when it is
 * removed. So the screens hand every rendering the honest state that
 * outlives a module: an ORPHANED GRANT — still held, declared by nothing,
 * drawn muted and still revocable.
 *
 * POSITIONS ARE RETIRED, NEVER DELETED, and retiring is refused while
 * anybody holds it.
 */
final readonly class PositionController
{
    /** The section's third tab: the register. */
    public const string REGISTER = 'team_positions';

    /**
     * THE TWO PAIRS THESE SCREENS ARE ABOUT, and they are not the same pair.
     * Reading the register is `positions.read`; composing what a position
     * grants is `positions.configure`, which is administering the team.
     */
    public const string READ = TeamConcerns::POSITIONS.'.'.Verb::Read->value;
    public const string CONFIGURE = TeamConcerns::POSITIONS.'.'.Verb::Configure->value;

    public const string CSRF_ID = 'team_position';

    /** How many lines of a position's history a bounded card shows. */
    private const int HISTORY_SHOWN = 8;

    public function __construct(
        private Environment $twig,
        private PositionRepository $positions,
        private UserRepository $users,
        private ConcernCatalogue $catalogue,
        private PositionBoard $board,
        private PositionHistory $historian,
        private PositionService $positionWrites,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private AreaAuthority $authority,
    ) {
    }

    /**
     * THE REGISTER. One card per position, filtered by the scope kinds a
     * position allows — a segmented row rather than a dropdown, because
     * there are five one-click choices and burying five of those in a panel
     * is worse than showing them.
     */
    #[Route('/team/positions', name: self::REGISTER, defaults: TeamController::SURFACE, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function index(Request $request): Response
    {
        // ONE TABLE (ruled 2026-09-22, option B): the dropdowns count the
        // whole register, the filter and the sort read the cards, and the
        // rows the address names are rendered open.
        $query = PositionQuery::from($request);
        $cards = $this->board->register();
        $shown = $query->order(array_values(array_filter($cards, $query->matches(...))));

        $count = static fn (callable $test): int => \count(array_filter($cards, $test));
        $placementOptions = [];
        foreach ([ScopeKind::Organization, ScopeKind::Area, ScopeKind::Department, ScopeKind::Own] as $kind) {
            $placementOptions[] = ['value' => $kind->value, 'label' => ucfirst($kind->value), 'count' => $count(static fn (PositionCard $c): bool => \in_array($kind, $c->allowedKinds(), true))];
        }
        $placementOptions[] = ['value' => PositionQuery::PLACES_NOWHERE, 'label' => 'Placed nowhere', 'count' => $count(static fn (PositionCard $c): bool => [] === $c->allowedKinds())];

        return new Response($this->twig->render('@Team/positions/index.html.twig', [
            'query' => $query,
            'rows' => $shown,
            'total' => \count($cards),
            'placementOptions' => $placementOptions,
            'seatsOptions' => [
                ['value' => PositionQuery::SEATS_VACANT, 'label' => 'Vacancies', 'count' => $count(static fn (PositionCard $c): bool => $c->seatsFree() > 0)],
                ['value' => PositionQuery::SEATS_FULL, 'label' => 'Full', 'count' => $count(static fn (PositionCard $c): bool => $c->isFull())],
                ['value' => PositionQuery::SEATS_UNLIMITED, 'label' => 'Unlimited', 'count' => $count(static fn (PositionCard $c): bool => null === $c->seats())],
            ],
            'grantsOptions' => [
                ['value' => PositionQuery::GRANTS_SOME, 'label' => 'Grants something', 'count' => $count(static fn (PositionCard $c): bool => !$c->grantsNothing())],
                ['value' => PositionQuery::GRANTS_SENSITIVE, 'label' => 'Sensitive', 'count' => $count(static fn (PositionCard $c): bool => $c->sensitiveGranted > 0)],
                ['value' => PositionQuery::GRANTS_NONE, 'label' => 'Grants nothing', 'count' => $count(static fn (PositionCard $c): bool => $c->grantsNothing())],
            ],
            'columns' => ['name' => 'Position', 'seats' => 'Seats', 'grants' => 'Grants', 'sensitive' => 'Sensitive', 'holders' => 'Holders'],
            // WHAT A PLACEMENT MAY BE MADE AT, and the whole of it: a
            // placement is at the organization or at named areas. Department
            // is the placement's other dimension and `own` is a scope a
            // concern offers, so neither is a box the create form draws.
            'placeableKinds' => [ScopeKind::Organization, ScopeKind::Area],
            'holding' => $this->users->countActiveHoldingAnyPosition($this->positions->findAllOrdered()),
            'declared' => \count($this->catalogue->all()),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    #[Route('/team/positions/{uuid}', name: 'team_position_show', requirements: ['uuid' => Requirement::UUID], defaults: TeamController::SURFACE_RECORD, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(string $uuid): Response
    {
        $position = $this->position($uuid);
        $card = $this->board->card($position);
        $history = $this->historian->of($position, $card->holders);

        return new Response($this->twig->render('@Team/positions/show.html.twig', [
            'card' => $card,
            'history' => \array_slice($history, 0, self::HISTORY_SHOWN),
            'historyTotal' => \count($history),
            'orphans' => $this->board->orphans($position),
        ]));
    }

    /**
     * CONFIGURE. One page, no tabs: the identity, the matrix, the holders
     * read-only beside them, and retiring.
     */
    #[Route('/team/positions/{uuid}/configure', name: 'team_position_configure', requirements: ['uuid' => Requirement::UUID], defaults: TeamController::SURFACE_RECORD, methods: ['GET'])]
    #[IsGranted(self::CONFIGURE)]
    public function configure(string $uuid): Response
    {
        $position = $this->position($uuid);
        $card = $this->board->card($position);

        return new Response($this->twig->render('@Team/positions/configure.html.twig', [
            'card' => $card,
            'orphans' => $this->board->orphans($position),
            // §5.6(c): what the signed-in administrator may confer — null when
            // unbounded (everything grantable), a list of pairs for a bounded
            // (area-X) one. The matrix draws the cells outside it disabled.
            'grantable' => $this->authority->grantableGrants(),
            'placeableKinds' => [ScopeKind::Organization, ScopeKind::Area],
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * CREATING ONE IS A NAME, A SEAT COUNT AND THE KINDS OF PLACEMENT IT
     * ALLOWS. A position belongs to no department, so there is nothing to
     * file it under and the name is unique across the whole organization.
     */
    #[Route('/team/positions', name: 'team_position_create', methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A position needs a name.', 'error');
        }

        try {
            $position = $this->positionWrites->create($name);
            // THE ADD CARD IS A NAME, A SEAT COUNT AND A PLACEMENT, as the
            // design draws it; a create that posts no placement keeps the
            // entity's own default until somebody opens the configure page.
            $kinds = $this->kindsFrom($request);
            $this->positionWrites->setIdentity(
                $position,
                $name,
                $this->seatsFrom($request),
                [] === $kinds ? $position->getAllowedKinds() : $kinds,
            );
        } catch (NameNotUniqueException) {
            // The index would have said this in SQL. The person who typed the
            // name wants the sentence — and the sentence says ORGANIZATION,
            // because a position belongs to no department and there is one of
            // each name.
            return $this->back($request, \sprintf(
                'This organization already has a position called “%s”. A position belongs to no department, so its name is unique across the whole organization — rename one of them.',
                $name,
            ), 'error');
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error');
        }

        return $this->back($request, \sprintf('“%s” exists. It grants nothing until you tick something.', (string) $position->getName()));
    }

    /**
     * THE IDENTITY SAVE — the name, the seats and the kinds of placement,
     * written together because they are refused together.
     */
    #[Route('/team/positions/{uuid}/identity', name: 'team_position_identity', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function identity(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A position needs a name.', 'error', $position);
        }

        try {
            $this->positionWrites->setIdentity($position, $name, $this->seatsFrom($request), $this->kindsFrom($request));
        } catch (NameNotUniqueException) {
            return $this->back($request, \sprintf('This organization already has a position called “%s”.', $name), 'error', $position);
        } catch (SeatsBelowHoldersException|\InvalidArgumentException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error', $position);
        }

        return $this->back($request, 'Saved.', 'success', $position);
    }

    /**
     * THE MATRIX SAVE. Every ticked cell, as a `<concern>.<verb>` pair,
     * through the position's one validated write path.
     *
     * THE FORM POSTS ONLY WHAT IS TICKED, so what is absent is what was
     * revoked — except for the orphans, which the template draws as ticked
     * boxes of their own precisely so that a save that does not touch them
     * keeps them. Editing a position is not a migration.
     */
    #[Route('/team/positions/{uuid}/permissions', name: 'team_position_permissions', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function permissions(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            array_map(
                static fn (mixed $v): string => \is_string($v) ? $v : '',
                (array) $request->request->all('grants'),
            ),
            static fn (string $v): bool => '' !== $v,
        ));

        // §5.6(c): a bounded (area-X) administrator may grant only what their
        // own position holds, and never team administration — anything wider
        // is escalation.
        $granted = $this->withinGrantAuthority($position, $granted);

        try {
            $this->positionWrites->setGrants($position, $granted);
        } catch (UnknownGrantException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error', $position);
        }

        $reaches = $this->users->countActiveHoldingAnyPosition([$position]);

        return $this->back($request, \sprintf(
            '“%s” now holds %d grant%s, and the change reaches %d %s.',
            (string) $position->getName(),
            \count($granted),
            1 === \count($granted) ? '' : 's',
            $reaches,
            1 === $reaches ? 'person' : 'people',
        ), 'success', $position);
    }

    /**
     * CLOSING A POSITION. We do not delete things: the row stays, everything
     * it granted keeps its history, and it can come back. Refused while
     * anybody holds it, and the refusal names the count.
     */
    #[Route('/team/positions/{uuid}/retire', name: 'team_position_retire', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function retire(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        if ($position->isRetired()) {
            $this->positionWrites->reinstate($position);

            return $this->back($request, \sprintf('“%s” is open again, and can be given to somebody.', (string) $position->getName()), 'success', $position);
        }

        try {
            $this->positionWrites->retire($position);
        } catch (PositionHeldException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error', $position);
        }

        return $this->back($request, \sprintf('“%s” is retired. The record is kept and it can be reinstated.', (string) $position->getName()), 'success', $position);
    }

    /**
     * RENAMING ON ITS OWN, kept for one release. The identity save writes the
     * name with the seats and the kinds; an installation that generated this
     * route name still reaches somewhere that works.
     *
     * @deprecated since 1.0, to be removed in 1.1 — post the identity instead
     */
    #[Route('/team/positions/{uuid}/rename', name: 'team_position_rename', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::CONFIGURE)]
    public function rename(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A position needs a name.', 'error', $position);
        }

        try {
            $this->positionWrites->rename($position, $name);
        } catch (NameNotUniqueException) {
            return $this->back($request, \sprintf('This organization already has a position called “%s”.', $name), 'error');
        }

        return $this->back($request, 'Renamed.', 'success', $position);
    }

    /**
     * THE SEAT COUNT AS THE FORM STATES IT: one, a number, or unlimited.
     * Unlimited is null, and the number is only read when the form says the
     * answer is a number — so switching to unlimited does not have to blank
     * the field the reader was just typing in.
     */
    private function seatsFrom(Request $request): ?int
    {
        $mode = trim((string) $request->request->get('seatMode', 'number'));
        if ('unlimited' === $mode) {
            return null;
        }

        if ('one' === $mode) {
            return 1;
        }

        $seats = $request->request->get('seats');

        return is_numeric($seats) ? (int) $seats : null;
    }

    /**
     * WHICH KINDS OF PLACEMENT THE POSITION ALLOWS — organization and area
     * only, because that is what a placement is. Department is the
     * placement's other dimension and `own` is a scope a concern offers; the
     * entity refuses either, and the form never offers them.
     *
     * @return list<ScopeKind>
     */
    private function kindsFrom(Request $request): array
    {
        $posted = array_map(
            static fn (mixed $v): string => \is_string($v) ? $v : '',
            (array) $request->request->all('allows'),
        );

        return array_values(array_filter(array_map(
            static fn (string $value): ?ScopeKind => ScopeKind::tryFrom($value),
            $posted,
        )));
    }

    /**
     * REFUSE A GRANT PAST THE ADMINISTRATOR'S OWN AUTHORITY (§5.6(c)), and freeze
     * what is beyond it. An unbounded administrator (a tier or org-level holder)
     * grants exactly what was posted. A bounded (area-X) one may grant only the
     * pairs their own position holds, never team administration:
     *
     *   · a posted pair that is neither grantable NOR already on the position is
     *     a crafted grant past the boundary — refused with a 403, defence in depth
     *     behind the disabled control the matrix draws;
     *   · a pair the position already held beyond the administrator's reach
     *     is FROZEN to what it was, so a bounded save can neither strip it (an
     *     unrelated edit must not silently revoke it) nor is it a way around the
     *     fence.
     *
     * @param list<string> $granted
     *
     * @return list<string> the pairs to persist
     */
    private function withinGrantAuthority(Position $position, array $granted): array
    {
        $grantable = $this->authority->grantableGrants();
        if (null === $grantable) {
            return $granted;
        }

        $existing = $position->getGrantValues();

        foreach ($granted as $pair) {
            if (!\in_array($pair, $grantable, true) && !\in_array($pair, $existing, true)) {
                throw new AccessDeniedException('An area administrator may grant only what their own position holds.');
            }
        }

        $frozen = array_values(array_filter($existing, static fn (string $value): bool => !\in_array($value, $grantable, true)));
        $chosen = array_values(array_filter($granted, static fn (string $value): bool => \in_array($value, $grantable, true)));

        return array_values(array_unique([...$chosen, ...$frozen]));
    }

    private function position(string $uuid): Position
    {
        return $this->positions->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such position on this installation.');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    /**
     * BACK TO WHERE THE EDIT WAS MADE. A write about one position goes back
     * to that position's configure page, so the sentence is read beside the
     * thing it is about; a write with no position — creating one — goes to
     * the register.
     */
    private function back(Request $request, string $message, string $kind = 'success', ?Position $position = null): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        return new RedirectResponse(null === $position
            ? $this->router->generate(self::REGISTER)
            : $this->router->generate('team_position_configure', ['uuid' => $position->getUuidString()]));
    }
}
