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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\LastSuperAdminException;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionFullException;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionRetiredException;
use Uhifadhi\Bundle\TeamBundle\Model\PositionCard;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Bundle\TeamBundle\Service\Mail;
use Uhifadhi\Bundle\TeamBundle\Service\MemberHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PasswordResetService;
use Uhifadhi\Bundle\TeamBundle\Service\PersonRankService;
use Uhifadhi\Bundle\TeamBundle\Service\PositionBoard;
use Uhifadhi\Bundle\TeamBundle\Service\PostingDoorService;
use Uhifadhi\Bundle\TeamBundle\Service\SuperAdminInvariant;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Contracts\People\PersonPosting;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;
use Uhifadhi\Contracts\People\PersonRecordCellProviderInterface;
use Uhifadhi\Contracts\People\StationPlateProviderInterface;

/**
 * ONE PERSON'S RECORD — the four fields the table has, the tier that decides
 * whether they stand above the matrix, and the position that decides what they
 * may actually do.
 *
 * THERE IS NO DELETE, AND THERE IS NO ROUTE FOR ONE. Accounts are never
 * hard-deleted: "this ranger left in March" and "this ranger never existed" are
 * different facts, and everything the person recorded keeps its author. The
 * action is Deactivate and its opposite is Reactivate. A `deletedAt` marker
 * exists on the model so a future recycle bin is not foreclosed, and nothing
 * here writes it.
 *
 * TWO CHANGES CAN BE REFUSED, and they are refused with their reason rather
 * than greyed out. Demoting or deactivating the last ACTIVE Super Admin leaves
 * nobody who can administer the team, so {@see SuperAdminInvariant} says no —
 * and the page prints the refusal where the control would have been, because a
 * disabled button says "not now" and leaves the reader guessing.
 *
 * THERE IS NO PER-PERSON PERMISSION ANYWHERE ON THIS PAGE, because the model has
 * no such thing. Authority lives on the position; giving one person an exception
 * would mean giving them a position of their own.
 *
 * ASSIGNING A POSITION IS AREA-SCOPED
 * . `#[IsGranted('directory.manage')]` is the coarse gate; the
 * position write REFINES it. A bounded (area-X) administrator may reassign only
 * among positions their authority reaches — the one the person holds now and the
 * one they move to must both be in their area — and the picker offers only those.
 * A tier or org-level holder is unbounded and touches anyone. {@see AreaAuthority}
 * computes the boundary; {@see assertMayAssign()} refuses anything past it (a 403).
 *
 * MANAGING THE PERSON THEMSELVES IS AREA-SCOPED TOO. Editing the record,
 * deactivating and reactivating are refused when the person is PLACED outside
 * the administrator's ground. It is the person's placement that decides it,
 * not their position: a position carries no ground of its own, so which one
 * somebody holds says nothing about whose boundary touching them crosses.
 * Somebody placed nowhere is beyond a bounded administrator too, because the
 * model fails closed. {@see assertMayManage()} draws that line (a 403), the
 * person-record twin of {@see assertMayAssign()}.
 *
 * EVERY WRITE IS A POST AND EVERY POST IS CSRF-CHECKED.
 */
final readonly class MemberController
{
    /** One token id for the whole record, because it is one screen. */
    public const string CSRF_ID = 'team_member';

    /** The way an administrator hands somebody back their own account. */
    public const string RESET_LINK = 'team_member_reset_link';

    /** And the way they chase an invitation nobody opened. */
    public const string INVITE_AGAIN = 'team_member_invite_again';

    /**
     * How many lines the history card shows before it states the bound. A
     * bounded card never grows to the data and never scrolls inside itself.
     */
    private const int HISTORY = 8;

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        /**
         * EVERYTHING THERE IS TO HAVE A PERMISSION ABOUT in this
         * installation, which is what the effective-grants ledger walks.
         */
        private ConcernCatalogue $concerns,
        private SuperAdminInvariant $invariant,
        private UserService $accounts,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private AreaAuthority $authority,
        private MemberHistory $history,
        private PasswordResetService $resets,
        private Mail $mail,
        /**
         * WHERE THIS PERSON WORKS, from whoever owns the ground. An
         * installation with no area package yields no provider and the record
         * says they are posted nowhere, which is true.
         *
         * @var iterable<PersonPostingProviderInterface>
         */
        private iterable $postingProviders,
        /** WHERE A POSTING IS MADE, so an unstationed record can carry a door to it. */
        private PostingDoorService $postingDoor,
        /**
         * WHO DRAWS THE GROUND AROUND A STATION, from whoever owns it.
         *
         * @var iterable<StationPlateProviderInterface>
         */
        private iterable $stationPlates,
        private PositionBoard $board,
        private DepartmentRepository $departments,
        private EntityManagerInterface $entityManager,
        /** THIS PERSON'S RANK — held now, held before, and the ranks that may be given. */
        private PersonRankService $personRank,
        /**
         * A MODULE'S CARD ON THIS PERSON'S RECORD, from whoever holds a fact
         * about them. Drawn last in the main column, after the ledger; a
         * provider with nothing to say answers null and nothing is drawn.
         *
         * @var iterable<PersonRecordCellProviderInterface>
         */
        private iterable $recordCells = [],
    ) {
    }

    /**
     * THE RECORD, READ. Ruled 21 Sep: it carries no control at all — the
     * position, where it applies, the departments, where the person is
     * stationed, what that grants right now, and the account's history.
     * Everything that writes is on the configure page beside it.
     */
    #[Route('/team/{uuid}', name: 'team_member', requirements: ['uuid' => Requirement::UUID], defaults: TeamController::SURFACE_RECORD, methods: ['GET'])]
    #[IsGranted('directory.read')]
    public function show(string $uuid): Response
    {
        $member = $this->member($uuid);
        $postings = $this->postingsFor($member);
        $ranks = $this->personRank->historyOf($member);
        $history = $this->history->of($member, $postings, $ranks);
        $position = $member->getPosition();
        $card = null === $position ? null : $this->board->card($position);

        return new Response($this->twig->render('@Team/team/member.html.twig', [
            'usesRanks' => $this->personRank->usesRanks(),
            'ranks' => $ranks,
            'rankNow' => null !== ($ranks[0] ?? null) && null === $ranks[0]->getUntil() ? $ranks[0] : null,
            'member' => $member,
            'card' => $card,
            'placement' => $member->getPlacement(),
            'figures' => $this->figures($card, $member->getTeamRole()->canManageContent()),
            'byTier' => $member->getTeamRole()->canManageContent(),
            'departmentsTotal' => \count($this->departments->findAllActiveOrdered()),
            'stationedAt' => $postings[0] ?? null,
            'stationPlate' => $this->plateFor($postings[0] ?? null),
            'postingDoor' => $this->postingDoor->url(),
            'postings' => $postings,
            'reach' => null === $position ? 0 : $this->users->countActiveHoldingAnyPosition([$position]),
            'history' => \array_slice($history, 0, self::HISTORY),
            'historyTotal' => \count($history),
            'recordCells' => $this->recordCellsFor($member),
        ]));
    }

    /**
     * THE CONFIGURE PAGE — the record mirrored, and everything that writes:
     * the details, the sign-in and tier, the position with where it applies
     * and which departments, and the account actions in the side column.
     */
    #[Route('/team/{uuid}/configure', name: 'team_member_configure', requirements: ['uuid' => Requirement::UUID], defaults: TeamController::SURFACE_RECORD, methods: ['GET'])]
    #[IsGranted('directory.manage')]
    public function configure(string $uuid): Response
    {
        $member = $this->member($uuid);
        $postings = $this->postingsFor($member);
        $ranks = $this->personRank->historyOf($member);
        $history = $this->history->of($member, $postings, $ranks);
        $position = $member->getPosition();
        $card = null === $position ? null : $this->board->card($position);

        return new Response($this->twig->render('@Team/team/member_configure.html.twig', [
            'usesRanks' => $this->personRank->usesRanks(),
            'rankNow' => null !== ($ranks[0] ?? null) && null === $ranks[0]->getUntil() ? $ranks[0] : null,
            'rankChoices' => $this->personRank->usesRanks() ? $this->personRank->choices() : [],
            'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'member' => $member,
            'card' => $card,
            'placement' => $member->getPlacement(),
            'tiers' => TeamRoleEnum::cases(),
            'choices' => $this->choices(),
            'areas' => $this->areas(),
            'departments' => $this->departments->findAllActiveOrdered(),
            'allowsOrganization' => null === $position || \in_array(ScopeKind::Organization, $position->getAllowedKinds(), true),
            'allowsArea' => null === $position || \in_array(ScopeKind::Area, $position->getAllowedKinds(), true),
            'isLastSuperAdmin' => $this->invariant->isLastActiveSuperAdmin($member),
            'mayImpersonate' => $this->signedIn()?->getTeamRole()->canSwitch() ?? false,
            'isSelf' => $this->signedIn()?->getId() === $member->getId(),
            'mayChangeTier' => $this->authority->isUnbounded(),
            'stationedAt' => $postings[0] ?? null,
            'stationPlate' => $this->plateFor($postings[0] ?? null),
            'postingDoor' => $this->postingDoor->url(),
            'reach' => null === $position ? 0 : $this->users->countActiveHoldingAnyPosition([$position]),
            'history' => \array_slice($history, 0, 7),
            'historyTotal' => \count($history),
            'mailReady' => $this->mail->isConfigured(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    #[Route('/team/{uuid}/reset-link', name: self::RESET_LINK, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('personal-details.manage')]
    public function sendResetLink(Request $request, string $uuid): RedirectResponse
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($member);

        if (!$member->isActive() || !$this->mail->isConfigured()) {
            return $this->back($request, $member, 'No reset link was sent.', 'error');
        }

        $this->mail->sendPasswordReset($member, $this->router->generate(
            'team_reset',
            ['token' => $this->resets->begin($member)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));

        return $this->back($request, $member, \sprintf('A reset link is on its way to %s.', $member->getEmail()));
    }

    /**
     * THE INVITATION, SENT AGAIN — for somebody who never opened the first
     * one.
     *
     * IT ROTATES THE TOKEN AND TOUCHES NO PASSWORD: the person still chooses
     * their own, which is the whole difference between an invitation and a
     * handover. Asking again replaces the previous link, so an old email in
     * an inbox stops working.
     *
     * NOT OFFERED ONCE THEY HAVE SIGNED IN. The token is spent, the account is
     * theirs, and the way back in is a password reset.
     */
    #[Route('/team/{uuid}/invite-again', name: self::INVITE_AGAIN, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('personal-details.manage')]
    public function resendInvitation(Request $request, string $uuid): RedirectResponse
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($member);

        if ($member->isVerified() || !$member->isActive() || !$this->mail->isConfigured()) {
            return $this->back($request, $member, 'No invitation was sent.', 'error');
        }

        $token = $this->accounts->reinvite($member, $this->signedIn());

        $this->mail->sendInvitation($member, $this->router->generate(
            'team_invite_accept',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));

        return $this->back($request, $member, \sprintf('The invitation is on its way to %s again. The previous link no longer works.', $member->getEmail()));
    }

    #[Route('/team/{uuid}', name: 'team_member_update', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function update(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($member);

        /*
         * THE ADDRESS IS ONLY WRITTEN BY SOMEBODY WHO MAY READ IT. The page
         * omits the email field for a reader without `personal-details.read`,
         * so the field simply does not arrive, and a form that never showed
         * the address must not be able to blank it. Falling back to the stored
         * one is the honest reading of a submission that said nothing about it.
         */
        $this->accounts->updateRecord(
            $member,
            (string) $request->request->get('firstName'),
            (string) $request->request->get('lastName'),
            (string) $request->request->get('email', $member->getEmail()),
            trim((string) $request->request->get('rangerCode')),
        );

        return $this->back($request, $member, 'Saved.');
    }

    #[Route('/team/{uuid}/tier', name: 'team_member_tier', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function tier(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        // §5.6(c): a bounded (area-X) administrator may not change a person's tier.
        // Super Admin and Admin are org-wide authority, so promoting somebody to
        // one confers power past the administrator's own boundary — escalation. A
        // tier or org-level holder is unbounded and touches anyone's tier.
        if (!$this->authority->isUnbounded()) {
            throw new AccessDeniedException('An area administrator may not change a person’s tier — Super Admin and Admin are org-wide authority.');
        }

        $tier = TeamRoleEnum::tryFrom((string) $request->request->get('tier'));
        if (null === $tier) {
            return $this->back($request, $member, 'That is not a tier this installation has.', 'error');
        }

        try {
            $this->accounts->changeTier($member, $tier);
        } catch (LastSuperAdminException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        return $this->back($request, $member, \sprintf('%s is now %s.', $member->getFullName(), $tier->label()));
    }

    /**
     * THE RANK THIS PERSON HOLDS FROM A DAY ON — a promotion is a dated fact.
     * The rank held until then closes on that day and stays in the history;
     * "no rank" closes it and opens nothing. Ranks grant nothing, so this
     * touches no permission.
     */
    #[Route('/team/{uuid}/rank', name: 'team_member_rank', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function rank(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        if (!$this->personRank->usesRanks()) {
            throw new NotFoundHttpException('This organization does not use ranks.');
        }

        $chosen = trim((string) $request->request->get('rank'));
        $rank = '' === $chosen ? null : $this->personRank->rankFor($chosen);
        if ('' !== $chosen && null === $rank) {
            return $this->back($request, $member, 'That rank is retired or no longer exists.', 'error');
        }

        $since = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('since'));
        if (false === $since) {
            return $this->back($request, $member, 'A rank is held from a day: give the date.', 'error');
        }

        try {
            $this->personRank->assign($member, $rank, $since, $this->signedIn());
        } catch (\InvalidArgumentException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        return $this->back($request, $member, null === $rank
            ? \sprintf('%s holds no rank from %s.', $member->getFullName(), $since->format('j M Y'))
            : \sprintf('%s holds %s from %s.', $member->getFullName(), $rank->getName(), $since->format('j M Y')));
    }

    #[Route('/team/{uuid}/position', name: 'team_member_position', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function position(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        $chosen = trim((string) $request->request->get('position'));
        if ('' === $chosen) {
            // NULLABLE, AND THE NULL IS A REAL CHOICE. A Staff member with no
            // position is verified, can sign in, and can do nothing at all.
            // §5.6(a): unassigning is still touching a person, so a bounded
            // administrator may do it only to somebody already in their area.
            $this->assertMayAssign($member);
            $this->accounts->assignPosition($member, null);

            return $this->back($request, $member, \sprintf('%s now holds no position, and therefore no permissions at all.', $member->getFullName()));
        }

        $position = Uuid::isValid($chosen) ? $this->positions->findOneByUuid(Uuid::fromString($chosen)) : null;
        if (null === $position) {
            return $this->back($request, $member, 'That position no longer exists.', 'error');
        }

        // A bounded administrator may work only on somebody placed inside
        // their own ground — the position itself carries none.
        $this->assertMayAssign($member);

        /*
         * A FULL POSITION REFUSES, AND THE REFUSAL IS A SENTENCE RATHER THAN
         * A CRASH. The seat count is enforced in the service, because a
         * second door that forgot to ask would quietly seat one person too
         * many; what the door owes is the reading of it. The exception's
         * message already names the post and whoever stands in it, which is
         * the only thing that makes the refusal actionable — the
         * administrator's next move is to end that holding or pick another
         * position, and they cannot choose without the name.
         */
        try {
            $this->accounts->assignPosition($member, $position);
        } catch (PositionFullException|PositionRetiredException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        // WHERE IT APPLIES, AND WHICH DEPARTMENTS — the placement's two
        // dimensions, written in the same save as the seat. A request that
        // says nothing about them keeps the placement that stands.
        if ($request->request->has('where') || $request->request->has('all_departments') || $request->request->has('departments')) {
            try {
                $this->accounts->place($member, $this->placementFrom($request, $position));
            } catch (\InvalidArgumentException $refusal) {
                return $this->back($request, $member, $refusal->getMessage(), 'error');
            }
        }

        return $this->back($request, $member, \sprintf('%s now holds %s.', $member->getFullName(), (string) $position->getName()));
    }

    /**
     * THE WAY SOMEBODY LEAVES. Not a delete, and there is no delete: the row
     * stays, everything they recorded keeps its author, and reactivating is one
     * click.
     */
    #[Route('/team/{uuid}/deactivate', name: 'team_member_deactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function deactivate(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($member);

        try {
            $this->accounts->deactivate($member);
        } catch (LastSuperAdminException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        return $this->back($request, $member, \sprintf('%s can no longer sign in. Nothing has been deleted — everything they recorded keeps its author, and they stay on the roster under the inactive filter.', $member->getFullName()));
    }

    #[Route('/team/{uuid}/reactivate', name: 'team_member_reactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function reactivate(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);
        $this->assertMayManage($member);

        $this->accounts->reactivate($member);

        return $this->back($request, $member, \sprintf('%s can sign in again.', $member->getFullName()));
    }

    private function member(string $uuid): User
    {
        return $this->users->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such person on this installation.');
    }

    /**
     * REFUSE AN OUT-OF-AUTHORITY ASSIGNMENT (§5.6(a)). A tier or org-level
     * administrator is unbounded and may assign anyone anywhere; a bounded
     * (area-X) administrator may reassign only among positions their authority
     * reaches — the one the person holds now and the one they are moving to must.
     * /**
     * REFUSE MANAGING A PERSON PLACED OUTSIDE THE AUTHORITY. A tier or an
     * organization-wide administrator is unbounded and may edit, deactivate
     * and reactivate anyone; a bounded (area-X) administrator may do so only
     * to somebody whose placement lies inside their own ground.
     *
     * A PERSON PLACED NOWHERE IS BEYOND A BOUNDED ADMINISTRATOR, and that is
     * the model failing closed rather than an oversight: an unplaced person
     * lies in no area, so there is no area in which a bounded administrator
     * could be said to reach them. Somebody unbounded places them first.
     * {@see AreaAuthority::reachesPerson()} computes the boundary; this is
     * the 403 behind it, the person-record twin of {@see assertMayAssign()}.
     */
    private function assertMayManage(User $member): void
    {
        if ($this->authority->isUnbounded()) {
            return;
        }

        if (!$this->authority->reachesPerson($member)) {
            throw new AccessDeniedException('An area administrator may manage only people placed in their own area.');
        }
    }

    /**
     * REACH IS THE PERSON'S, NOT THE POSITION'S. A position carries no ground
     * of its own any more, so which position somebody is moved between says
     * nothing about whose boundary the move crosses; the person's placement
     * does, and it is the same question either way.
     */
    private function assertMayAssign(User $member): void
    {
        if ($this->authority->isUnbounded()) {
            return;
        }

        if (!$this->authority->reachesPerson($member)) {
            throw new AccessDeniedException('An area administrator may assign only people placed in their own area.');
        }
    }

    private function signedIn(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    /**
     * WHERE THIS PERSON WORKS, from whoever owns the ground — one call, even
     * for one person, because the seam is list-shaped and a second shape would
     * be a second thing to keep true.
     *
     * @return list<PersonPosting>
     */
    private function postingsFor(User $member): array
    {
        $uuid = (string) $member->getUuidString();

        $postings = [];
        foreach ($this->postingProviders as $provider) {
            foreach ($provider->postingsFor([$uuid])[$uuid] ?? [] as $posting) {
                $postings[] = $posting;
            }
        }

        return $postings;
    }

    /**
     * Back to the record with a sentence. A refusal is a flash rather than a
     * status code, because it is not an error the browser made — it is the
     * model saying no, and the reader needs the reason beside the control.
     */
    private function back(Request $request, User $member, string $message, string $kind = 'success'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        // A SAVE MADE ON THE CONFIGURE PAGE COMES BACK TO IT — one save bar
        // per card, and the next card is right there.
        $route = 'configure' === $request->request->get('return') ? 'team_member_configure' : 'team_member';

        return new RedirectResponse($this->router->generate($route, ['uuid' => $member->getUuidString()]));
    }

    /**
     * THE PLACEMENT AS THE FORM SAYS IT. Where is the organization OR named
     * areas — the first pill is exclusive with the rest, and a kind the
     * position does not allow is refused in the entity's own words.
     * Departments are all OR a chosen few, several allowed.
     *
     * @throws \InvalidArgumentException when the form names nowhere, or a kind the position does not allow
     */
    private function placementFrom(Request $request, Position $position): Placement
    {
        $placement = new Placement();
        $where = (string) $request->request->get('where', 'areas');

        if ('organization' === $where) {
            if (!\in_array(ScopeKind::Organization, $position->getAllowedKinds(), true)) {
                throw new \InvalidArgumentException(\sprintf('%s is not placed across the organization — it allows named areas only.', (string) $position->getName()));
            }
            $placement->acrossTheOrganization();
        } else {
            if (!\in_array(ScopeKind::Area, $position->getAllowedKinds(), true)) {
                throw new \InvalidArgumentException(\sprintf('%s is not placed in an area — it applies across the organization.', (string) $position->getName()));
            }
            $areas = [];
            foreach ($this->areas() as $area) {
                if (\in_array((string) $area->getUuidString(), $this->listOf($request, 'areas'), true)) {
                    $areas[] = $area;
                }
            }
            if ([] === $areas) {
                throw new \InvalidArgumentException('Name at least one area, or place them across the whole organization.');
            }
            $placement->inAreas($areas);
        }

        if ($request->request->getBoolean('all_departments')) {
            $placement->acrossAllDepartments();
        } else {
            $chosen = [];
            foreach ($this->departments->findAllActiveOrdered() as $department) {
                if (\in_array((string) $department->getUuidString(), $this->listOf($request, 'departments'), true)) {
                    $chosen[] = $department;
                }
            }
            if ([] === $chosen) {
                $placement->acrossAllDepartments();
            } else {
                $placement->inDepartments($chosen);
            }
        }

        return $placement;
    }

    /** @return list<string> */
    private function listOf(Request $request, string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => \is_string($v) ? $v : '',
            (array) $request->request->all($key),
        )));
    }

    /**
     * EVERY AREA OF THE INSTALLATION, through the association the placement
     * already declares — this bundle never names the class that holds them.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Placement::class)->getAssociationTargetClass('areas');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }

    /**
     * THE PICKER'S ROWS: every position that may be given, with its seats and,
     * when it is full, who holds it — a full one is listed and refused, never
     * hidden.
     *
     * @return list<array{uuid: string, label: string, full: bool}>
     */
    private function choices(): array
    {
        $rows = [];
        foreach ($this->board->register() as $card) {
            if ($card->position->isRetired()) {
                continue;
            }
            $rows[] = [
                'uuid' => $card->uuid(),
                'label' => $card->name().' — '.$this->seatsLabel($card),
                'full' => $card->isFull(),
            ];
        }

        return $rows;
    }

    private function seatsLabel(PositionCard $card): string
    {
        if (null === $card->seats()) {
            return \sprintf('%d held · unlimited', $card->seatsFilled());
        }
        if (!$card->isFull()) {
            return \sprintf('%d of %d seats', $card->seatsFilled(), $card->seats());
        }
        $names = array_map(
            static fn ($h): string => preg_replace('/^(\w)\S* /u', '$1. ', $h->name) ?? $h->name,
            \array_slice($card->holders, 0, 2),
        );

        return \sprintf('%d seat%s · held by %s · full', $card->seats(), 1 === $card->seats() ? '' : 's', implode(', ', $names));
    }

    /**
     * THE FOUR FIGURES OF THE BAND — how many concerns the position lets this
     * person read, record, manage and export, with a sample of names.
     *
     * @return array<string, array{n: int, note: string}>
     */
    private function figures(?PositionCard $card, bool $byTier = false): array
    {
        $out = [];
        foreach ([Verb::Read, Verb::Record, Verb::Manage, Verb::Export] as $verb) {
            $names = [];
            $sensitive = 0;
            if ($byTier) {
                foreach ($this->concerns->all() as $concern) {
                    if (\in_array($verb, $concern->verbs(), true)) {
                        $names[] = strtolower($concern->label());
                    }
                }
            } elseif (null !== $card) {
                foreach ($card->groups as $group) {
                    foreach ($group->rows as $row) {
                        if ($row->cells[$verb->value] ?? false) {
                            $names[] = strtolower($row->label);
                            if ($row->sensitive) {
                                ++$sensitive;
                            }
                        }
                    }
                }
            }
            $note = implode(', ', \array_slice($names, 0, 3));
            if ($sensitive > 0) {
                $note = \sprintf('%d sensitive · %s', $sensitive, $note);
            }
            $out[$verb->value] = ['n' => \count($names), 'note' => $note];
        }

        return $out;
    }

    /** THE PLATE FOR WHERE THEY ARE STATIONED — the first provider that owns the station answers. */
    /**
     * EVERY CARD A MODULE DRAWS ON THIS PERSON, in the order the container
     * yields the providers; the ones answering null are simply absent.
     *
     * @return list<string>
     */
    private function recordCellsFor(User $member): array
    {
        $uuid = $member->getUuidString();
        if (null === $uuid) {
            return [];
        }

        $cells = [];
        foreach ($this->recordCells as $provider) {
            $cell = $provider->cellFor($uuid);
            if (null !== $cell) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    private function plateFor(?PersonPosting $posting): ?string
    {
        if (null === $posting) {
            return null;
        }
        foreach ($this->stationPlates as $provider) {
            $plate = $provider->plateFor($posting->stationUuid);
            if (null !== $plate) {
                return $plate->html;
            }
        }

        return null;
    }
}
