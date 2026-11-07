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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Uhifadhi\Bundle\TeamBundle\Enum\GrantQuestionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\StepOutcomeEnum;

/**
 * ONE QUESTION OF A CHECK AS THE VOTER ASKED IT: which, how it came out, and
 * the reason the voter gives the decision manager when it refuses there.
 */
final readonly class GrantStep
{
    public function __construct(
        public GrantQuestionEnum $question,
        public StepOutcomeEnum $outcome,
        public ?string $reason = null,
    ) {
    }
}
