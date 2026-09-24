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

namespace Uhifadhi\Bundle\RegistryBundle\Service;

/**
 * What one reconciliation did, by slug, so the command that ran it can say so.
 *
 * `added` are the modules the catalogue had no row for; `kept` are the ones it
 * had, refreshed from their providers; `retired` are the rows whose provider is
 * no longer installed — LEFT IN PLACE, never deleted, and named so an operator
 * sees what the catalogue still remembers. `areaAssignments` counts the
 * per-area rows created for areas that lacked one.
 *
 * `skipped` is the fresh-install answer: the registry tables are not there yet,
 * so nothing was reconciled and nothing went wrong. It is a distinct fact from
 * "reconciled nothing", which is what an installation with no modules reports.
 */
final readonly class RegistrySyncResult
{
    /**
     * @param list<string> $added
     * @param list<string> $kept
     * @param list<string> $retired
     */
    public function __construct(
        public array $added = [],
        public array $kept = [],
        public array $retired = [],
        public int $areaAssignments = 0,
        public bool $skipped = false,
    ) {
    }

    public static function skipped(): self
    {
        return new self(skipped: true);
    }

    /** How many modules the installed providers declare — added and kept together. */
    public function modules(): int
    {
        return \count($this->added) + \count($this->kept);
    }
}
