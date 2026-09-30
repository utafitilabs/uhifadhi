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

namespace Uhifadhi\Bundle\TeamBundle\Exception;

/**
 * A position was handed a pair only the tiers above the matrix hold (ruled
 * 30 Sep, #67): who holds which seat, a person's address and sign-in, and the
 * ranks. The matrix never draws one, so a save naming it is a forged form.
 */
final class TierOnlyGrantException extends \InvalidArgumentException
{
    /**
     * @param list<string> $values the submitted pairs only the tiers hold
     */
    public function __construct(public readonly array $values)
    {
        parent::__construct(\sprintf(
            'Only Admins and Super Admins hold %s; a position never does.',
            implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values)),
        ));
    }
}
