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
 * ONE PLACE UNDER A SETTING: it follows the default, or holds its own value
 * (`input` for its field, `text` as the read page prints it).
 */
final readonly class CascadePlace
{
    public function __construct(
        public string $uuid,
        public string $name,
        public string $kind,
        public bool $own,
        public string $text,
        public string $input,
    ) {
    }
}
