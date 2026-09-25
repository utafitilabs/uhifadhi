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

use Uhifadhi\Bundle\TeamBundle\Entity\Rank;

/** ONE ROW OF THE RANKS REGISTER: the rank, its place, and how many hold it now. */
final readonly class RankRow
{
    public function __construct(
        public Rank $rank,
        public int $order,
        public int $holders,
    ) {
    }
}
