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

/** ONE ROW OF RANKED BARS, with its two widths worked out — what the template writes down. */
final readonly class DrawnBar
{
    public function __construct(
        public Bar $bar,
        /** The fill's width, a percentage of the track. */
        public float $fill,
        /** The rest's width, a percentage of the track. */
        public float $rest,
    ) {
    }
}
