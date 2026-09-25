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
 * ASK A NEWLY INSTALLED MODULE ABOUT THE PERIODS THAT HAVE ALREADY CLOSED —
 * queued by the install, handled by the worker.
 */
final readonly class BackfillModuleHistory implements AsyncMessageInterface
{
    public function __construct(
        public string $slug,
    ) {
    }
}
