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

namespace Uhifadhi\Contracts\People;

/**
 * A CARD ON A PERSON'S RECORD, from a module that knows something about them.
 *
 * The person's page is the team's: the position, what it grants, where they
 * are stationed, the account's history. A module that holds a fact about a
 * person — the handsets they carry, the last time one reported in — draws it
 * as one more card through this seam, and the page renders every card it is
 * handed as the LAST cards of the main column, after the grants ledger, in
 * the order the container yields the providers.
 *
 * THE CARD IS THE MODULE'S WHOLE MARKUP, one house card (`.c` with its
 * `.tab`), written against the shell's sheet and the module's own. The page
 * adds nothing around it and reads nothing out of it.
 *
 * THE CONTRIBUTION GATES ITSELF. The record page asks every provider for
 * every person; a provider that has nothing to say about this person, or
 * whose fact the viewer may not read, answers null and nothing is drawn — the
 * page never learns why. A card that stated "you may not see this" would be a
 * card about the viewer, not about the person.
 *
 * Tagged explicitly at both ends, like every seam here: a reusable bundle
 * tags its provider in its own service file, because it is not
 * autoconfigured, and an attribute written on this interface would be
 * silently dead.
 */
interface PersonRecordCellProviderInterface
{
    public const string TAG = 'team.record.cells';

    /** The card's markup for this person, or null to draw nothing. */
    public function cellFor(string $personUuid): ?string;
}
