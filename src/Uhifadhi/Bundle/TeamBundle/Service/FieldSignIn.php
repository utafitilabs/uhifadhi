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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;

/**
 * WHETHER AN IDENTIFIER AND A PASSCODE NAME SOMEBODY WHO MAY SIGN IN.
 *
 * The web door asks this of a firewall, which loads the account through a
 * provider and refuses a deactivated one through a checker. The field door has
 * no firewall — it is the door a client reaches BEFORE it holds anything — so
 * it asks here, and the three refusals a firewall would make separately are
 * made together and answered identically.
 *
 * ONE ANSWER FOR THREE DIFFERENT FAILURES: no such person, the wrong passcode,
 * and an account that has been deactivated. Replying differently would turn the
 * one endpoint reachable without a credential into a directory of who works
 * here, and of who used to.
 *
 * THE VERIFY RUNS EVEN WHEN NOBODY MATCHED, against a hash of nothing. Returning
 * early on an unknown identifier makes the reply the same and the TIMING say
 * what the reply would not.
 */
final readonly class FieldSignIn
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        /** An unused one-time password past its expiry is refused like a wrong passcode. */
        private ?OneTimePasswordService $oneTimePasswords = null,
    ) {
    }

    public function authenticate(string $identifier, string $passcode): ?User
    {
        $user = $this->users->findOneByFieldIdentifier($identifier);

        $valid = $this->passwordHasher->isPasswordValid($user ?? $this->nobody(), $passcode);

        if (!$valid || !$user instanceof User || !$user->isActive()) {
            return null;
        }

        return null === $this->oneTimePasswords || $this->oneTimePasswords->admit($user) ? $user : null;
    }

    /**
     * An account nothing can sign in as, carrying a real stored hash so that
     * checking it costs what checking a real one costs.
     */
    private function nobody(): User
    {
        return new User()->setPassword($this->passwordHasher->hashPassword(new User(), bin2hex(random_bytes(16))));
    }
}
