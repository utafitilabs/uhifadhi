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

namespace Uhifadhi\Contracts\Facts;

/**
 * THE FACTS LEDGER, READ — what a page asks instead of computing.
 *
 * A READ IS ONE INDEX LOOKUP, or for a quarter or year of an additive figure
 * at most twelve month rows added up. It never computes a figure, and a
 * figure nobody has computed yet is null: the page draws its empty state, and
 * the next scheduled run fills it.
 *
 * Implemented by the registry bundle; a module type-hints this interface.
 */
interface FactReaderInterface
{
    /**
     * ONE FIGURE, ONE SUBJECT, ONE PERIOD — the latest the worker wrote.
     *
     * A quarter or a year of an ADDITIVE figure is the sum of its months, as
     * far as they run unbroken from the period's first month; a figure that
     * is not additive is read from its own row.
     */
    public function latest(string $subjectKind, string $subjectUuid, string $figureKey, string $periodKey): ?Fact;

    /**
     * A PAGE'S FIGURES IN ONE READ: several subjects, several figures, one
     * period. A figure nobody computed is absent from the answer.
     *
     * @param list<string> $subjectUuids
     * @param list<string> $figureKeys
     *
     * @return array<string, array<string, Fact>> subject uuid, then figure key, to the fact
     */
    public function batch(string $subjectKind, array $subjectUuids, array $figureKeys, string $periodKey): array;
}
