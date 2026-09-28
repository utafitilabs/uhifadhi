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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\TeamBundle\Entity\OneTimePassword;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\OneTimePasswordRepository;

/**
 * A ONE-TIME PASSWORD, ISSUED BY AN ADMINISTRATOR (ruled 27 Sep 2026).
 *
 * For the people who cannot be sent a reset link — most rangers have no
 * reliable email on the phone they carry. An administrator issues a code on
 * the person's configure page, passes it on, and the person signs in with it
 * on the web or on Doria.
 *
 * THE RULES, and where each lives:
 *   - Only the tiers above the matrix issue one ({@see mayIssue()}); a
 *     position grants nothing here, whatever it holds.
 *   - Only a Super Admin issues one for a Super Admin, so an Admin can never
 *     take over a more powerful account; Admins issue them for each other.
 *   - The code is random — eight characters from an alphabet without the
 *     look-alikes (0 O 1 I), so it reads cleanly over a radio — never derived
 *     from the clock, which a guesser could narrow down.
 *   - It is stored only as the person's hashed password and shown once.
 *   - Every issue is kept ({@see OneTimePassword}): who, for whom, when, and
 *     when it was first used.
 *   - A code nobody has used expires after {@see EXPIRES_AFTER}; a used code
 *     is the person's password until they choose another.
 */
final readonly class OneTimePasswordService
{
    /** The characters a code is drawn from: capitals and digits, without 0, O, 1 and I. */
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** How many characters a code has. */
    public const int LENGTH = 8;

    /** How long an unused code stays good. */
    public const string EXPIRES_AFTER = '72 hours';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private OneTimePasswordRepository $issued,
    ) {
    }

    /** Whether this administrator may issue a one-time password for this person. */
    public function mayIssue(?User $issuer, User $person): bool
    {
        if (null === $issuer || !$issuer->getTeamRole()->canManageContent() || $issuer->getId() === $person->getId()) {
            return false;
        }

        // ADMINS ARE PEERS (ruled 28 Sep): an Admin issues one for another
        // Admin; only a Super Admin issues one for a Super Admin.
        return TeamRoleEnum::SuperAdmin !== $person->getTeamRole() || TeamRoleEnum::SuperAdmin === $issuer->getTeamRole();
    }

    /**
     * Issues a code: it becomes the person's password and the issue is kept.
     *
     * @return string the code, to be shown once and never again
     *
     * @throws AccessDeniedException when this administrator may not issue one for this person
     */
    public function issue(User $issuer, User $person): string
    {
        if (!$this->mayIssue($issuer, $person)) {
            throw new AccessDeniedException('Only an administrator above the matrix issues a one-time password, and only a Super Admin for a Super Admin.');
        }

        $code = $this->generate();
        $now = new \DateTimeImmutable();
        $person->setPassword($this->hasher->hashPassword($person, $code))->markOneTimePassword($now);
        $this->entityManager->persist(new OneTimePassword($person, $issuer, $now));
        $this->entityManager->flush();

        return $code;
    }

    /** A fresh random code. */
    public function generate(): string
    {
        $code = '';
        $last = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * AT SIGN-IN, AFTER THE PASSWORD IS KNOWN TO BE RIGHT. False for an
     * unused one-time password older than {@see EXPIRES_AFTER}; otherwise
     * true, and a pending one-time password is marked used, so it no longer
     * expires.
     */
    public function admit(User $person): bool
    {
        $issuedAt = $person->getOneTimePasswordIssuedAt();
        if (null === $issuedAt) {
            return true;
        }

        $now = new \DateTimeImmutable();
        if ($issuedAt < $now->modify('-'.self::EXPIRES_AFTER)) {
            return false;
        }

        $person->markOneTimePassword(null);
        $this->issued->findOneUnusedByPerson($person)?->markUsed($now);
        $this->entityManager->flush();

        return true;
    }
}
