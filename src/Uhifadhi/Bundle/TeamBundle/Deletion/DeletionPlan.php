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

namespace Uhifadhi\Bundle\TeamBundle\Deletion;

use Uhifadhi\Contracts\Deletion\DeletionLine;
use Uhifadhi\Contracts\Deletion\DeletionSubject;

/** What a delete would do, as the delete page reads it before anything happens. */
final readonly class DeletionPlan
{
    /**
     * @param list<DeletionLine> $goes
     * @param list<DeletionLine> $stays
     */
    public function __construct(
        public DeletionSubject $subject,
        public array $goes,
        public array $stays,
    ) {
    }

    /** "1 patrol, 3 observations": what went, as the audit line keeps it. */
    public function whatGoes(): string
    {
        return implode(', ', array_map(static fn (DeletionLine $line): string => $line->phrase(), array_values(array_filter($this->goes, static fn (DeletionLine $line): bool => $line->count > 0))));
    }
}
