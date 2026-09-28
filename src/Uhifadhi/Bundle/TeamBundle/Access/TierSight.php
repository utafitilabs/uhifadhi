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

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * WHO SEES A TIER (ruled 28 Sep 2026): Admins and Super Admins see every
 * person's tier, their own and each other's; Staff see none, anywhere.
 *
 * Below the matrix a person works by rank and position, and the tiers mean
 * nothing to them; a list of who holds the top tiers is a list of targets.
 * So every surface that would name a tier asks this first — a template
 * through `sees_tiers()`, a controller or the API through [self::for()].
 *
 * @see https://symfony.com/doc/current/security.html#fetching-the-user-object — the token storage is what `Security::getUser()` reads
 */
final class TierSight
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    /** Whether the signed-in person sees tiers. */
    public function seesTiers(): bool
    {
        $user = $this->tokens->getToken()?->getUser();

        return self::for($user instanceof User ? $user : null);
    }

    /** Whether [viewer] sees tiers: only the two tiers above the matrix do. */
    public static function for(?User $viewer): bool
    {
        return $viewer?->getTeamRole()->canManageContent() ?? false;
    }
}
