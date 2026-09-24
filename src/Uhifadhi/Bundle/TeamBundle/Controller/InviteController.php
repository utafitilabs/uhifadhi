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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Exception\PositionFullException;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\Mail;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSettingsService;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * ADDING SOMEBODY — and BOTH WAYS SHIP.
 *
 * They are not two settings and not a migration: they are two answers to a real
 * difference, which is whether the person is standing next to you.
 *
 * CREATE WITH A PASSWORD needs nothing at all from the deployment — no mailer,
 * no outbound network, no reachable inbox — which is exactly why it is the one
 * that is always available. The person is verified the moment they exist,
 * because an administrator who typed the password has already proved the
 * account is real. It is the only path when a ranger's only address is one they
 * cannot reach from the field. Its cost is operational rather than technical:
 * the password is hashed on save and the product can never show it again, so it
 * has to be handed over in the room.
 *
 * INVITE BY EMAIL ships beside it and starts working by itself the moment an
 * installation has a mailer. Nobody ever knows anybody else's password, which is
 * the whole argument for it.
 *
 * WHERE THERE IS NO MAILER THE PATH IS OFFERED AND REFUSED, NOT HIDDEN. Hiding
 * it would leave an administrator hunting for a feature the product does have;
 * failing silently after the click would leave a colleague waiting for an email
 * nobody sent. So the form is visible, the button is inert, and the reason is
 * written on it. That is the one deliberate exception to this workspace's
 * never-a-disabled-control rule, and the rule's own wording allows it: absence
 * is for a thing that will NEVER exist, and this one is an environment variable
 * away.
 *
 * WHICHEVER WAY IN, THE POSITION IS AREA-SCOPED
 * . A bounded (area-X) administrator adds people only
 * into positions their authority reaches, so the picker offers only those and
 * {@see assignablePosition()} refuses a pick past their boundary (a 403). A tier or
 * org-level holder is unbounded and adds anyone anywhere. Leaving the position
 * empty is always fine — a position-less account grants nothing to police.
 */
final readonly class InviteController
{
    public const string CSRF_CREATE = 'team_member';
    public const string CSRF_INVITE = 'team_invite';

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        private UserService $accounts,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private Mail $mail,
        private TeamSettingsService $settings,
    ) {
    }

    #[Route('/team/invite', name: 'team_invite', methods: ['GET'])]
    #[IsGranted('directory.read')]
    public function show(): Response
    {
        return new Response($this->twig->render('@Team/team/invite.html.twig', [
            // §5.6(a): a bounded administrator adds people only into their own
            // area's positions, so the picker offers only those.
            // A RETIRED POSITION IS ABSENT FROM THE PICKER. It is not
            // deleted — the register still carries it, greyed — but it
            // cannot be given to anybody, and offering a thing that is
            // closed is offering a refusal.
            'positions' => $this->positions->findAssignable(),
            // The one deployment fact this page turns on.
            'mailerConfigured' => $this->mail->isConfigured(),
            'passwordMinLength' => User::PASSWORD_MIN_LENGTH,
            // THE TEAM'S RULE: whether somebody may be created with a password
            // at all, or by invitation only. Withheld here and refused below.
            'withPassword' => $this->settings->current()->isInvitationWithPassword(),
            'createToken' => $this->csrf->getToken(self::CSRF_CREATE)->getValue(),
            'inviteToken' => $this->csrf->getToken(self::CSRF_INVITE)->getValue(),
        ]));
    }

    /** THE PATH THAT NEEDS NOTHING FROM THE DEPLOYMENT. */
    #[Route('/team', name: 'team_member_create', methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF_CREATE);

        if (!$this->settings->current()->isInvitationWithPassword()) {
            // WITHHELD AND REFUSED. The team's rule says people arrive by
            // invitation only; a POST that arrives anyway gets the sentence.
            return $this->back($request, 'This team adds people by invitation only — the rule is set on Team › Configure › People.', 'error');
        }

        $email = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');

        if ('' === $email) {
            return $this->back($request, 'An email address is the sign-in identifier, so it is required.', 'error');
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return $this->back($request, \sprintf('An account with the email %s already exists. Emails are folded to lower case, so a different capitalisation is the same person.', $email), 'error');
        }
        if (mb_strlen($password) < User::PASSWORD_MIN_LENGTH) {
            return $this->back($request, \sprintf('A password must be at least %d characters.', User::PASSWORD_MIN_LENGTH), 'error');
        }

        // A FULL POSITION REFUSES HERE TOO, and the refusal names the holder.
        // The account is not created: seating is part of adding somebody, and
        // half-adding them would leave a person nobody meant to make.
        try {
            $user = $this->accounts->create(
                $email,
                (string) $request->request->get('firstName'),
                (string) $request->request->get('lastName'),
                $password,
                position: $this->assignablePosition((string) $request->request->get('position')),
                rangerCode: trim((string) $request->request->get('rangerCode')),
            );
        } catch (PositionFullException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error');
        }

        return $this->toMember($request, $user, \sprintf('%s exists and can sign in now. The password is hashed and the product cannot show it again, so hand it over before you close this.', $user->getFullName()));
    }

    /** THE PATH THAT NEEDS A MAILER, and says so when there is none. */
    #[Route('/team/invite', name: 'team_invite_send', methods: ['POST'])]
    #[IsGranted('directory.manage')]
    public function invite(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF_INVITE);

        if (!$this->mail->isConfigured()) {
            // OFFERED AND REFUSED. The form was visible and the reason was
            // written on it; a POST that arrives anyway gets the same sentence
            // rather than a silent success.
            return $this->back($request, 'This installation has no mail transport configured, so there is nothing to send the invitation with. Set MAILER_DSN and try again — or add the person with a password, which needs nothing from the deployment.', 'error');
        }

        $email = strtolower(trim((string) $request->request->get('email')));
        if ('' === $email) {
            return $this->back($request, 'An invitation needs an address to go to.', 'error');
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return $this->back($request, \sprintf('An account with the email %s already exists.', $email), 'error');
        }

        // AN INVITATION TAKES THE SEAT, so a full position refuses before the
        // letter goes out: a post two people have been promised is worse than
        // an invitation somebody has to send again.
        try {
            $user = $this->accounts->invite(
                $email,
                $this->assignablePosition((string) $request->request->get('position')),
                // RULED IN, so the roster can say who invited somebody and when
                // rather than only that they have not arrived.
                $this->signedIn(),
            );
        } catch (PositionFullException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error');
        }

        $this->mail->sendInvitation($user, $this->router->generate(
            'team_invite_accept',
            ['token' => (string) $user->getVerificationToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));

        return $this->toMember($request, $user, \sprintf('Invitation sent to %s. They set their own password from the link, so nobody here ever knows it.', $email));
    }

    private function assignablePosition(string $chosen): ?Position
    {
        $chosen = trim($chosen);
        if ('' === $chosen || !Uuid::isValid($chosen)) {
            // OPTIONAL, AND HONESTLY SO: leaving it empty creates somebody who
            // can sign in and do nothing. Better than guessing.
            return null;
        }

        $position = $this->positions->findOneByUuid(Uuid::fromString($chosen));

        // A POSITION CARRIES NO GROUND, so which one an invited person is put
        // into says nothing about whose boundary the invitation crosses. What
        // does is where they will be placed, and that is decided on their
        // record - so the pick itself is open, and the placement is fenced
        // where it is written.

        return $position;
    }

    private function signedIn(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function flash(Request $request, string $message, string $kind): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }
    }

    private function back(Request $request, string $message, string $kind = 'success'): RedirectResponse
    {
        $this->flash($request, $message, $kind);

        return new RedirectResponse($this->router->generate('team_invite'));
    }

    /** Somebody who now exists is somebody whose record is the useful next page. */
    private function toMember(Request $request, User $user, string $message): RedirectResponse
    {
        $this->flash($request, $message, 'success');

        return new RedirectResponse($this->router->generate('team_member', ['uuid' => $user->getUuidString()]));
    }
}
