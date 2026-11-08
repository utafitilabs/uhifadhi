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

namespace Uhifadhi\Bundle\ShellBundle\Model;

/**
 * ONE CHOICE OF A CHOICE SETTING: the value its radio posts, the word it
 * reads, and what it does — said under the default's control while it is
 * chosen, or null where the setting says none.
 */
final readonly class CascadeChoice
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $meaning,
    ) {
    }
}
