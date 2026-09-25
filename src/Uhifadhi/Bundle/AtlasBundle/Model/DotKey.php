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

namespace Uhifadhi\Bundle\AtlasBundle\Model;

/**
 * THE KEY THAT READS A ROW OF BARS OR A MATRIX OF DOTS — a dot and a word per
 * entry, in the small mono line under the thing it reads.
 */
final readonly class DotKey
{
    /** @param list<KeyEntry> $entries */
    public function __construct(public array $entries)
    {
    }
}
