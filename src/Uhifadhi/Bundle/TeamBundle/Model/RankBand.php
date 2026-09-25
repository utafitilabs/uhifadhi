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

use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;

/**
 * ONE SCALE OF THE RANKS REGISTER — its rows, and the totals its band row
 * states once there is more than one scale.
 */
final readonly class RankBand
{
    /** @param list<RankRow> $rows */
    public function __construct(
        public RankScale $scale,
        public array $rows,
        public int $ranks,
        public int $holders,
    ) {
    }
}
