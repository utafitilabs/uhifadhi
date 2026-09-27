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

namespace Uhifadhi\Bundle\TeamBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Service\OneTimePasswordService;

/**
 * A DEACTIVATED ACCOUNT CANNOT SIGN IN, AND IS TOLD SO.
 *
 * Deactivation is how somebody leaves — the row survives, everything they
 * recorded keeps its author, and they stay on the roster under an inactive
 * filter. What has to stop is the sign-in, and the plug-point for that is a
 * user checker, called by the authenticator BEFORE the password is verified.
 *
 * @see https://symfony.com/doc/current/security/user_checkers.html
 *
 * IT SAYS WHY. `checkPreAuth` could throw the generic account-status exception
 * and let the firewall render "invalid credentials", which is the wrong
 * sentence twice over: it is untrue, and it sends a ranger who left in March to
 * hunt for a password that was never the problem. So the message is explicit,
 * and it names the one thing that fixes it — an administrator, who can
 * reactivate the account in a click.
 *
 * There is nothing to leak here. Deactivation is a state of an account that
 * already exists, and whoever is typing that password already knew it did.
 *
 * The check runs PRE-auth on purpose: an account that may not sign in should
 * not have its password verified at all.
 *
 * AN INSTALLATION HAS TO NAME THIS in its firewall — `user_checker:` — for the
 * same reason it writes its own `security.yaml`: the firewall is the
 * application's file. The README's block includes the line.
 */
final class ActiveUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ?OneTimePasswordService $oneTimePasswords = null,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been deactivated and cannot sign in. Ask an administrator to reactivate it — nothing has been deleted.');
        }
    }

    /**
     * ONCE THE PASSWORD IS RIGHT: an unused one-time password older than its
     * expiry no longer signs in, and one used now stops expiring.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User || null === $this->oneTimePasswords) {
            return;
        }

        if (!$this->oneTimePasswords->admit($user)) {
            throw new CustomUserMessageAccountStatusException('This one-time password has expired: it was not used within 72 hours. Ask an administrator for a new one.');
        }
    }
}
