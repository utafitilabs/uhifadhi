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
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * WHO SEES A TIER (ruled 28 Sep 2026): a Super Admin sees every tier; an
 * Admin sees Admin and Staff tiers and never a Super Admin's — a Super Admin
 * is, to an Admin, a rank and a position; Staff see no tier, anywhere.
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

    /** Whether the signed-in person sees tiers at all. */
    public function seesTiers(): bool
    {
        return self::for($this->viewer());
    }

    /** Whether the signed-in person sees [member]'s tier. */
    public function seesTierOf(User $member): bool
    {
        return self::sees($this->viewer(), $member);
    }

    /** Whether the signed-in person sees the Super Admin tier at all — only a Super Admin does. */
    public function seesSuperAdmins(): bool
    {
        return TeamRoleEnum::SuperAdmin === $this->viewer()?->getTeamRole();
    }

    /**
     * The tiers the signed-in person may see named — for chips, counts and filters.
     *
     * @return list<TeamRoleEnum>
     */
    public function visibleTiers(): array
    {
        return array_values(array_filter(
            TeamRoleEnum::cases(),
            fn (TeamRoleEnum $tier): bool => self::for($this->viewer()) && (TeamRoleEnum::SuperAdmin !== $tier || $this->seesSuperAdmins()),
        ));
    }

    /** Whether [viewer] sees [member]'s tier. */
    public static function sees(?User $viewer, User $member): bool
    {
        return match ($viewer?->getTeamRole()) {
            TeamRoleEnum::SuperAdmin => true,
            TeamRoleEnum::Admin => TeamRoleEnum::SuperAdmin !== $member->getTeamRole(),
            default => false,
        };
    }

    private function viewer(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    /** Whether [viewer] sees tiers: only the two tiers above the matrix do. */
    public static function for(?User $viewer): bool
    {
        return $viewer?->getTeamRole()->canManageContent() ?? false;
    }
}
