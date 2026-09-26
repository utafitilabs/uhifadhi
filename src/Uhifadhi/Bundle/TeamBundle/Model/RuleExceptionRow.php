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

use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;

/**
 * ONE EXCEPTION TO A RULE, AS A POSITION'S SCREENS DRAW IT — apart from the
 * matrix. Whether the seat holds it, and when it does, the reason in force;
 * the history is every giving and taking, newest first.
 */
final readonly class RuleExceptionRow
{
    /**
     * @param list<GrantJustification> $history every row this seat has had for the pair, newest first
     */
    public function __construct(
        public string $key,
        public string $pair,
        public string $label,
        public string $description,
        public string $lifts,
        public bool $held,
        public ?GrantJustification $current,
        public array $history = [],
    ) {
    }
}
