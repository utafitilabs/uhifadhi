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

namespace Uhifadhi\Bundle\RegistryBundle\Message;

use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * RECOMPUTE THE FIGURES OF THE PERIODS OPEN NOW — what the schedule sends,
 * every hour of the working day and once at night.
 *
 * It carries nothing: "now" is the worker's clock when it is handled, so a
 * message that waited in the queue computes the period open when it runs,
 * not the one open when it was sent. It is queued (the core's marker), so a
 * run that fails is retried and then kept on the failure transport rather
 * than lost with the schedule's tick.
 */
final readonly class RecomputeOpenFacts implements AsyncMessageInterface
{
}
