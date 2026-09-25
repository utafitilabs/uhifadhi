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
 * ONE FIGURE A MODULE COMPUTES ON A SCHEDULE, declared once.
 *
 * ADDITIVE SAYS WHETHER A QUARTER IS ITS MONTHS ADDED UP. Patrols logged and
 * metres walked are: the ledger stores months only and reads a quarter or a
 * year as the sum of at most twelve month rows. A share, an average, a
 * distinct count or a covered area is not — coverage of a quarter is not
 * the sum of three months' coverage — so the module is asked for the
 * quarter and the year as periods of their own and the ledger stores those
 * rows too.
 */
final readonly class FigureDefinition
{
    /**
     * @param string $key         `<module>.<figure>` — the same name the performance history files it under
     * @param string $subjectKind one of {@see FactSubject}'s kinds, or the module's own
     * @param bool   $additive    true when a quarter or a year is the sum of its months
     */
    public function __construct(
        public string $key,
        public string $subjectKind,
        public bool $additive,
    ) {
        if (1 !== preg_match('/^[a-z0-9_-]+\.[a-z0-9_.-]+$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A figure key is "<module>.<figure>", lower case; "%s" is not.', $key));
        }
        if ('' === $subjectKind) {
            throw new \InvalidArgumentException('A figure names the kind of subject it is filed under.');
        }
    }
}
