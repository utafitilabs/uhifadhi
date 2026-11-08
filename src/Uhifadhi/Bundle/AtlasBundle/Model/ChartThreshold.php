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
 * A LIMIT A READING IS COLOURED BY: a bar past the value wears the token
 * above (a status — `var(--warn)`, `var(--fail)`), the rest the token below
 * or their own colour; on a line, the points past it.
 *
 * THE COLOUR IS NEVER ALONE. A limit is drawn as a line as well (a target)
 * and the bars wear their figures; the status token past it means only that.
 */
final readonly class ChartThreshold
{
    public function __construct(
        public float $value,
        public string $above,
        public ?string $below = null,
    ) {
        if ('' === $above) {
            throw new \InvalidArgumentException('A threshold states its value and the token past it.');
        }
    }
}
