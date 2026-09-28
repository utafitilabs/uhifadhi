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

namespace Uhifadhi\Bundle\TeamBundle\Api;

use Uhifadhi\Bundle\TeamBundle\Access\TierSight;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * THE CONVERSIONS EVERY `/api` DOCUMENT DEPENDS ON, in one place so they cannot
 * drift between endpoints.
 *
 * A field client is released and then lives on a handset for months. Two
 * documents that spelled a moment differently, or that called the same person by
 * two different identifiers, would be two bugs nobody can fix from the phone —
 * so sign-in, `/api/me` and every roster read the same three lines here.
 *
 * A SHAPE OF A REFUSAL IS NOT HERE. Every failure under `/api` is one document
 * written by {@see \Uhifadhi\Bundle\TeamBundle\EventListener\ApiErrorListener},
 * which is a property of the URL space rather than of any endpoint in it; this
 * class is only about what a SUCCESS says.
 *
 * STATIC, AND DELIBERATELY: these are total functions of their arguments with no
 * collaborator and no state, so an injected instance would be ceremony around
 * three expressions — and a value object cannot ask a container for one.
 */
final class ContractFormat
{
    /** ISO-8601, UTC, to the second, with a literal Z — never an offset, never microseconds. */
    private const string TIMESTAMP = 'Y-m-d\TH:i:s\Z';

    public static function timestamp(\DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::TIMESTAMP);
    }

    /**
     * HOW A PERSON IS IDENTIFIED TO A FIELD CLIENT, everywhere: the service
     * number they know themselves by, falling back to the sign-in address for
     * staff who were never issued one — office staff never are.
     *
     * Whatever this returns in a roster is exactly what comes back on a record's
     * team, so there is one implementation and every caller uses it.
     */
    public static function rangerId(UserInterface $user): string
    {
        return $user->getRangerCode() ?? (string) $user->getEmail();
    }

    /**
     * THE ACCOUNT, AS EVERY DOCUMENT THAT NAMES ONE SPELLS IT.
     *
     * `role` is load-bearing rather than cosmetic: the screens a refused person
     * meets name the POSITION that lacks the permission, because that is what an
     * administrator has to change. It is never blank — somebody with no position
     * is named by their tier, which they always have.
     *
     * @return array{id: string, name: string, role: string}
     */
    public static function ranger(User $user): array
    {
        return [
            'id' => self::rangerId($user),
            'name' => $user->getFullName(),
            // The position; a tier only for somebody who sees tiers — Staff do
            // not, not even their own (ruled 28 Sep 2026).
            'role' => $user->getPosition()?->getName() ?? (TierSight::for($user) ? $user->getTeamRole()->label() : 'No position'),
        ];
    }
}
