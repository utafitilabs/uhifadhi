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

namespace Uhifadhi\Bundle\TeamBundle\Enum;

/**
 * WHAT AN INVITATION'S VALIDITY IS COUNTED IN. Two units, because a link that
 * lives for hours and one that lives for days are the two shapes an
 * organization asks for; a week is seven days and needs no third.
 */
enum InvitationUnitEnum: string
{
    case Days = 'days';
    case Hours = 'hours';

    public function label(): string
    {
        return $this->value;
    }

    public function interval(int $amount): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Days => \sprintf('P%dD', $amount),
            self::Hours => \sprintf('PT%dH', $amount),
        });
    }
}
