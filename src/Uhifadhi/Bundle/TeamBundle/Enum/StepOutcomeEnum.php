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

/** How one question of a check came out. */
enum StepOutcomeEnum: string
{
    case Passed = 'ok';
    case Refused = 'no';
    /** The question did not arise: a Staff member's tier, a pair that lifts no rule, a concern no department runs. */
    case NotAsked = 'skip';
}
