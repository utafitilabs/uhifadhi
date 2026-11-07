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

namespace Uhifadhi\Bundle\TeamBundle\MessageHandler;

use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;

/**
 * THE WORKER RUNS THE REQUEST. A run takes minutes on a large installation,
 * which is why it is queued: the message carries the core's queue marker and
 * the recipe routes it to `async`.
 *
 * @see https://symfony.com/doc/current/messenger.html#manually-configuring-handlers — without autoconfiguration a handler is tagged 'messenger.message_handler' with the class it handles
 * @see vendor/symfony/messenger/DependencyInjection/MessengerPass.php — the `handles` attribute config/services.php tags this with
 */
final readonly class RecomputePermissionsHandler
{
    public function __construct(
        private PermissionRunService $runs,
    ) {
    }

    public function __invoke(RecomputePermissions $message): void
    {
        $this->runs->handle($message->runId);
    }
}
