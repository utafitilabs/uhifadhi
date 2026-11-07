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
 * THE QUESTIONS THE GRANTVOTER ASKS, in the order it asks them. The values
 * are the words the Check page's walk is keyed by.
 */
enum GrantQuestionEnum: string
{
    /** Is the person a Super Admin or an Admin? */
    case Tier = 'tier';
    /** Is the pair one only the tiers hold? */
    case TierOnly = 'tier-only';
    /** Does their position hold the pair? */
    case Position = 'position';
    /** If the pair lifts a rule, is a written reason in force? */
    case Exception = 'exception';
    /** Does their placement reach anything at all? */
    case Placement = 'placement';
    /** Does it cover this place? */
    case Place = 'place';
    /** Does a department they serve run the concern's module? */
    case Department = 'department';
}
