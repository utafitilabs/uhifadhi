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

namespace Uhifadhi\Bundle\RegistryBundle\MessageHandler;

use Uhifadhi\Bundle\RegistryBundle\Message\RecomputeOpenFacts;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;

/**
 * THE WORKER'S SIDE OF THE SCHEDULE: the open periods, recomputed.
 *
 * Registered with the `messenger.message_handler` tag and its `handles`
 * attribute, by hand, because a reusable bundle is not autoconfigured:
 *
 *   "If autoconfiguration is disabled, manually register handlers using the
 *    messenger.message_handler tag"
 *   — https://symfony.com/doc/current/messenger.html#manually-configuring-handlers
 *
 * @see vendor/symfony/messenger/DependencyInjection/MessengerPass.php — the tag's `handles` attribute names the message class
 */
final readonly class RecomputeOpenFactsHandler
{
    public function __construct(
        private FactRebuildService $facts,
    ) {
    }

    public function __invoke(RecomputeOpenFacts $message): void
    {
        $this->facts->recomputeOpen();
    }
}
