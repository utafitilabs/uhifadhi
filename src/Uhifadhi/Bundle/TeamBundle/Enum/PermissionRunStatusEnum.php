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

/**
 * WHERE A RUN STANDS. A request is a pending run; the worker starts it, and
 * a run that starts takes every request still pending with it, which are
 * then superseded rather than run again.
 */
enum PermissionRunStatusEnum: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
