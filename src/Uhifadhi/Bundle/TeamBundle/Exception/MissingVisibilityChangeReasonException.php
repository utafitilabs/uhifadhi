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
 * WHO SEES A DEPARTMENT MAY NOT CHANGE WITHOUT A REASON, as its scope may not:
 * both decide who reads it, and the reason is what the permission history's
 * line exists to hold.
 */
final class MissingVisibilityChangeReasonException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A change to who sees a department must record a reason — it is the why of its line in the permission history.');
    }
}
