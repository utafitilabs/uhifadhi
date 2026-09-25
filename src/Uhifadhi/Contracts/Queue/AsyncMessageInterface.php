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

namespace Uhifadhi\Contracts\Queue;

/**
 * A MESSAGE THAT IS HANDLED BY THE WORKER, NEVER BY THE REQUEST THAT SENT IT.
 *
 * A marker, and nothing else: this interface is routed to the installation's
 * `async` transport once — the core's recipe writes the line, in
 * `config/packages/registry.yaml` — and every
 * message that implements it — the core's and any module's — goes to the
 * queue without a routing line of its own. Messenger matches a routing key
 * against the message's class, its parents and its interfaces:
 *
 *   "route all messages that extend this example base class or interface"
 *   — https://symfony.com/doc/current/messenger.html#routing-messages-to-a-transport
 *
 * @see vendor/symfony/messenger/Transport/Sender/SendersLocator.php — getSenders() walks HandlersLocator::listTypes(), which lists the class, its parents and its interfaces
 *
 * WHAT GOES HERE: work whose cost grows with the data — a figure over a
 * growing set, a backfill, a thumbnail, an outbound call. A message whose
 * handler is O(1) is handled in the request and does not implement this.
 *
 * WITHOUT A ROUTING LINE the message is handled synchronously, in the
 * request that dispatched it — correct, and slow. The line is the
 * installation's file once the recipe has written it.
 */
interface AsyncMessageInterface
{
}
