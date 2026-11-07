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

namespace Uhifadhi\Bundle\TeamBundle\Enum;

/** A stored answer is to a pair (`patrols.record`) or to a power (`make-admin`). */
enum QuestionKindEnum: string
{
    case Pair = 'pair';
    case Power = 'power';
}
