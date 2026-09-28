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

namespace Uhifadhi\Contracts\Access;

/**
 * The permission to change a PERSON — their account, position, rank, areas,
 * departments or postings — asked of the voter with the person as the subject:
 * `isGranted(PersonAccess::CONFIGURE, $person)`.
 *
 * It is a contract so that any bundle which changes a person (the team's
 * record, the area's station postings, a module's own) asks the same
 * question by the same name, without knowing who answers it. The team
 * bundle's voter answers: only a Super Admin configures a Super Admin, only
 * an Admin or a Super Admin an Admin (ruled 28 Sep 2026).
 */
final class PersonAccess
{
    public const string CONFIGURE = 'team.member.configure';
}
