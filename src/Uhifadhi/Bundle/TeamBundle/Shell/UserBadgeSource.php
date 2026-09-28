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

namespace Uhifadhi\Bundle\TeamBundle\Shell;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Bundle\TeamBundle\Access\TierSight;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Shell\UserBadge;
use Uhifadhi\Contracts\Shell\UserBadgeSourceInterface;

/**
 * WHO THE TOP BAR NAMES — team's answer to the shell's user-badge contract.
 *
 * Team is the core bundle that owns the account, its position and its tier, so
 * it is the bundle that can fold them into the card the shell draws. It reaches the
 * shell through {@see UserBadgeSourceInterface}, which lives in
 * the contracts precisely so this bundle can implement it depending only on
 * contracts — the shell stays in team's require-dev, never its require, exactly
 * as the nav row does.
 *
 * THE CONTEXT LINE IS THE POSITION FOR STAFF, THE TIER FOR EVERYONE ELSE. A
 * Staff member's position ("Senior Ranger") is the truest one-line answer to
 * "who is this on the page"; Super Admin and Admin hold their standing by tier,
 * not a position, so they get the tier's label. A Staff account with no position
 * yet, or a position with no name, falls through to the tier label rather than
 * an empty line — the same stand-alone instinct the whole contract layer keeps.
 *
 * NO VIEWER, NO CARD. An anonymous request — the sign-in screen, a fresh
 * installation with nobody signed in — is not a {@see User}, and the source
 * names nobody rather than inventing a card. Read live, per render, off the
 * security token, so signing out takes the card with it on the next request.
 *
 * THE TOKEN IS READ FROM SECURITY-CORE'S {@see TokenStorageInterface}, not from
 * the SecurityBundle `Security` helper: the helper is a class of the BUNDLE, and
 * a bundle is an installation's to register. Reading the storage keeps this
 * module's runtime need at symfony/security-core + symfony/security-http, which
 * is what it actually uses. Patterned on telemetry-module's
 * `Capture/SecurityUserResolver`, and on the component's own contract —
 * <https://symfony.com/doc/current/security.html#fetching-the-user-object>
 * (`vendor/symfony/security-core/Authentication/Token/Storage/TokenStorageInterface.php`
 * returns null when nobody is authenticated).
 */
final readonly class UserBadgeSource implements UserBadgeSourceInterface
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function badge(): ?UserBadge
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            return null;
        }

        // The position; a tier only for somebody who sees tiers — Staff do
        // not, not even their own (ruled 28 Sep 2026).
        $context = $user->getPosition()?->getName() ?? (TierSight::for($user) ? $user->getTeamRole()->label() : 'No position');

        return UserBadge::fromName($user->getFullName(), $context);
    }
}
