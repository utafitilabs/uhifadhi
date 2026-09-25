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

namespace Uhifadhi\Bundle\AtlasBundle\Model\Heatmap;

/** ONE STATE IN A CELL THAT COUNTS STATES: the publisher's word and the platform's tone. */
final readonly class HeatChip
{
    public function __construct(
        public string $label,
        /** 'good', 'bad', or '' where the state makes no claim. */
        public string $tone = '',
        public string $title = '',
    ) {
    }
}
