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

namespace Uhifadhi\Bundle\ShellBundle\Contract;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE ONE DELETE PAGE (ruled 28 Sep, #48, design C). A bundle or module gives
 * its record a route of its own - `/areas/{uuid}/delete`, under the record -
 * finds the record, and hands it here: the page counts what goes and stays,
 * asks for the reference typed, deletes, and keeps the audit line. Only a
 * Super Admin passes; anybody else is refused.
 *
 * HERE, NOT IN THE CONTRACTS, because it speaks HTTP and the contracts
 * depend on nothing. Published by the core under {@see SERVICE}; a module requires it optionally,
 * so an installation without the Team still boots and simply offers no delete.
 */
interface DeletionPageInterface
{
    public const string SERVICE = 'uhifadhi.deletion_page';

    /** Whether the signed-in account may delete at all: the Delete row's question. */
    public function mayDelete(): bool;

    public function respond(Request $request, object $record): Response;
}
