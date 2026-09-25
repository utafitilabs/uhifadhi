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

use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\RecomputeFacts;

/**
 * THE WORKER FILING ONE MODULE'S FIGURES AGAIN, for the months a module named
 * — the same writer the schedule and the operator's command use, asked for
 * exactly those months (and the quarters and years they fall in) and that
 * module alone. A closed month is computed when asked: the module knows a
 * record arrived late, the schedule does not.
 *
 * Registered by hand with the message it handles, as the bundle's other
 * handler is:
 *   "If autoconfiguration is disabled, manually register handlers using
 *    the messenger.message_handler tag with the handles attribute"
 *   — https://symfony.com/doc/current/messenger.html#manually-configuring-handlers
 *   (vendor/symfony/messenger/Handler/HandlersLocator.php reads the tag)
 */
final readonly class RecomputeFactsHandler
{
    public function __construct(
        private FactRebuildService $facts,
    ) {
    }

    public function __invoke(RecomputeFacts $message): void
    {
        $this->facts->rebuild(
            array_map(static fn (string $key): FactPeriod => FactPeriod::fromKey($key), $message->monthKeys),
            $message->moduleSlug,
            $message->subjectUuid,
        );
    }
}
