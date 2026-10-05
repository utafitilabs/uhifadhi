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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

/** One cell of the band: yes, within a limit (and which), never — or nobody to ask. */
final readonly class Mark
{
    public const string YES = 'ok';
    public const string PART = 'part';
    public const string NEVER = 'no';
    public const string NONE = 'none';

    public function __construct(
        public string $kind,
        public string $text,
    ) {
    }
}
