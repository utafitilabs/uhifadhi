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

namespace Uhifadhi\Bundle\TeamBundle\Message;

use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * WAKE THE PERMISSIONS WORKER: run this request, or with no id the oldest one
 * waiting. The request itself is a pending row, so a message lost or handled
 * twice changes nothing.
 */
final readonly class RecomputePermissions implements AsyncMessageInterface
{
    public function __construct(
        public ?int $runId = null,
    ) {
    }
}
