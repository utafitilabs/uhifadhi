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

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * ONE CELL: a power, asked as one account about one target — the answer, the
 * question that refused it, and the reasons its voters gave.
 */
final readonly class Cell
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public User $actor,
        public Power $power,
        public PowerTarget $kind,
        public string $target,
        public CellAnswer $answer,
        public ?string $refusedBy = null,
        public array $reasons = [],
    ) {
    }
}
