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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Exception\PasswordTooShortException;

/**
 * THE THREE WRITES BEHIND THE DOORS A STRANGER REACHES WITH NOBODY TO ASK — a
 * reset asked for, a reset spent, an invitation accepted.
 *
 * ASKING AGAIN REPLACES THE PREVIOUS LINK, so an old email in an inbox stops
 * working the moment a new one is sent. A link is good for
 * {@see self::LIFETIME_SECONDS} and works ONCE: spending it clears the token, so
 * the same link cannot be walked back to by anybody still holding the message.
 *
 * SETTING A PASSWORD IS WHAT MARKS AN ACCOUNT VERIFIED. That is the same fact on
 * both doors — a person who has chosen their own credential has proved the
 * address reaches them — so the reset door and the invitation door write it the
 * same way rather than each deciding.
 *
 * WHAT IS NOT HERE IS THE SESSION. Signing every OTHER session of the account
 * out is the point of a reset, and it is done by the screen: it is a fact about
 * the request in hand, not about the account. What this side guarantees is the
 * half that makes it work — the hash changes, and a remembered session signed
 * with the old one fails its check.
 */
final readonly class PasswordResetService
{
    /** ONE HOUR, and the screens say so before anybody asks. */
    public const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * ISSUE A LINK. Replaces whatever was outstanding, which is what makes an
     * older email inert the moment a newer one is sent.
     */
    public function begin(User $user): string
    {
        $token = bin2hex(random_bytes(32));

        $user->setPasswordResetToken($token);
        $user->setPasswordResetRequestedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $token;
    }

    /**
     * Whether a token is one this account may still spend. Expired and
     * already-used are ONE answer on purpose: telling a visitor which of the
     * two it was tells whoever holds a stolen link something about the account.
     */
    public function isLive(User $user, string $token): bool
    {
        if (!$user->isActive() || $token !== $user->getPasswordResetToken()) {
            return false;
        }

        $requestedAt = $user->getPasswordResetRequestedAt();

        return null !== $requestedAt && $requestedAt->getTimestamp() + self::LIFETIME_SECONDS >= time();
    }

    /**
     * SPEND THE LINK. The credential is replaced, the token is consumed, and
     * the account is verified — choosing a password is what proves the address
     * reaches the person.
     *
     * @throws PasswordTooShortException
     */
    public function complete(User $user, #[\SensitiveParameter] string $password): void
    {
        $this->setCredential($user, $password);

        $user->setPasswordResetToken(null);
        $user->setPasswordResetRequestedAt(null);
        $user->setVerified(true);

        $this->entityManager->flush();
    }

    /**
     * ACCEPT AN INVITATION — the person's own spelling of their own name, and
     * the password nobody here will ever know.
     *
     * ONE FIELD, SPLIT ONCE. Asking for a "first name" and a "last name"
     * separately is a Western assumption about names; taking the last word as
     * the family name is a smaller one, and it is the one the two stored
     * columns force.
     *
     * @throws PasswordTooShortException
     */
    public function accept(User $user, string $name, #[\SensitiveParameter] string $password): void
    {
        $this->setCredential($user, $password);

        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $last = \count($parts) > 1 ? (string) array_pop($parts) : '';
        $user->setFirstName(implode(' ', $parts));
        $user->setLastName($last);

        $user->setVerified(true);
        $user->setVerificationToken(null);

        $this->entityManager->flush();
    }

    /** @throws PasswordTooShortException */
    /**
     * A PERSON CHANGES THEIR OWN PASSWORD (ruled 26 Sep; built with My profile,
     * #69): the current one first, so nobody at an unlocked screen changes it.
     * False when the current one is wrong; nothing is written then.
     *
     * @throws PasswordTooShortException
     */
    public function changeOwn(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new): bool
    {
        if (!$this->hasher->isPasswordValid($user, $current)) {
            return false;
        }

        $this->setCredential($user, $new);
        $this->entityManager->flush();

        return true;
    }

    private function setCredential(User $user, #[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < User::PASSWORD_MIN_LENGTH) {
            throw new PasswordTooShortException();
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
    }
}
