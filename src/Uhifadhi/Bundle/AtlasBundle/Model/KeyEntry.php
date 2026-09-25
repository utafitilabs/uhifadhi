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

/** ONE ENTRY OF A DOT KEY: a mark and what it means; no mark is a line of words alone. */
final readonly class KeyEntry
{
    public function __construct(
        public string $label,
        public ?KeyMark $mark = KeyMark::Solid,
    ) {
    }
}
