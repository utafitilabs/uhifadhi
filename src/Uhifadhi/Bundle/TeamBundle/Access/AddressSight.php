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

namespace Uhifadhi\Bundle\TeamBundle\Access;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * WHO READS A SIGN-IN ADDRESS. An address is a personal detail, so a viewer
 * holding `personal-details.read` reads it whole and anybody else reads it
 * masked — the first letter and the domain, `g•••@unr.example` — wherever a
 * page names a person: the register, the sign-in card.
 *
 * It is a display rule beside {@see TierSight}: it changes what a page prints,
 * never what a route allows.
 */
final class AddressSight
{
    public const string PAIR = 'personal-details.read';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /** Whether the signed-in viewer reads addresses whole. */
    public function readsAddresses(): bool
    {
        return $this->authorization->isGranted(self::PAIR);
    }

    /** The person's address as the signed-in viewer may read it. */
    public function addressOf(User $person): string
    {
        $email = (string) $person->getEmail();
        if ($this->readsAddresses()) {
            return $email;
        }

        $at = strrpos($email, '@');

        return false === $at ? '•••' : mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }
}
