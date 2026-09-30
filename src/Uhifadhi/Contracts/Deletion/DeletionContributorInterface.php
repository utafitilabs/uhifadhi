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

namespace Uhifadhi\Contracts\Deletion;

/**
 * WHOEVER HOLDS ROWS A DELETE REACHES ANSWERS FOR THEM HERE (ruled 28 Sep,
 * #48: a Super Admin deletes a record and everything under it, counted
 * first, and modules delete their own rows - the core never names a module).
 *
 * ONE CONTRIBUTOR OWNS A KIND OF RECORD: its {@see describe()} answers, and
 * its {@see delete()} removes the record itself, last. Every other contributor
 * that supports the record describes nothing, adds what of its own goes or
 * stays, and removes or unlinks its own rows first - so no foreign key is
 * left pointing at a row about to go.
 *
 * NOTHING HERE ASKS WHO IS DELETING. The core has already checked that the
 * actor is a Super Admin and that the reference was typed; a contributor
 * counts and deletes.
 *
 * TAG IT BY HAND, in your own extension, because a reusable bundle's services
 * are not autoconfigured.
 */
interface DeletionContributorInterface
{
    public const string TAG = 'uhifadhi.deletion_contributor';

    /** Whether this contributor has anything to say about the record. */
    public function supports(object $record): bool;

    /** The record as its owner describes it, or null from every other contributor. */
    public function describe(object $record): ?DeletionSubject;

    /**
     * What of this contributor's goes with the record.
     *
     * @return list<DeletionLine>
     */
    public function whatGoes(object $record): array;

    /**
     * What of this contributor's is linked to the record and stays.
     *
     * @return list<DeletionLine>
     */
    public function whatStays(object $record): array;

    /**
     * Remove this contributor's rows; the owner removes the record itself.
     * Called inside the core's transaction, non-owners before the owner.
     */
    public function delete(object $record): void;
}
