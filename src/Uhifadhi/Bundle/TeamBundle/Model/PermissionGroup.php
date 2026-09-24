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

namespace Uhifadhi\Bundle\TeamBundle\Model;

/**
 * THE BAND A GROUPED PERMISSION TABLE BREAKS ON — an umbrella of the host's
 * own, or one installed module.
 *
 * AN INSTALLED MODULE THAT DECLARES NOTHING KEEPS ITS BAND and says so.
 * Leaving it off would read as "that module is not installed", which is a
 * different and wrong fact.
 */
final readonly class PermissionGroup
{
    /** @param list<PermissionRow> $rows */
    public function __construct(
        public string $heading,
        public string $source,
        public array $rows = [],
    ) {
    }

    /** "4 permissions", or the sentence an installed module with none gets. */
    public function note(): string
    {
        return match (\count($this->rows)) {
            0 => 'declares none',
            1 => '1 grant',
            default => \sprintf('%d grants', \count($this->rows)),
        };
    }
}
