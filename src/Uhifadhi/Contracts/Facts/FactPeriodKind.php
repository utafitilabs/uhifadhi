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

namespace Uhifadhi\Contracts\Facts;

/** The three lengths a fact is filed under. */
enum FactPeriodKind: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
