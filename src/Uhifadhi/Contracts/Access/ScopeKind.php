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
 * HOW FAR A GRANT REACHES — four values, and not a fifth.
 *
 * A position says which of these kinds it ALLOWS; the person's placement says
 * which one they are actually placed at. That split is the model's one lever
 * over reach: a position meant to be local cannot be widened by mistake when
 * somebody is assigned, because the wider kind is not on offer.
 *
 * NOT EVERY CONCERN OFFERS EVERY KIND. Ground concerns — areas, zones,
 * stations — offer organization or area, because ground is where it is. Team
 * concerns offer organization or department. {@see self::Own} is whatever the
 * module says it is, in its own words, and appears under that module's group
 * and nowhere else.
 *
 * ONE THING SCOPE IS NEVER DECIDED BY: who recorded the thing. A page is
 * scoped by the ground and the department it belongs to, never by whose name
 * is on the record.
 */
enum ScopeKind: string
{
    case Organization = 'organization';
    case Area = 'area';
    case Department = 'department';
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Organization',
            self::Area => 'Areas',
            self::Department => 'Department',
            self::Own => 'Own',
        };
    }

    public function reach(): string
    {
        return match ($this) {
            self::Organization => 'Everything.',
            self::Area => 'One or more named areas — every record resolves to an area.',
            self::Department => 'The resource belongs to a module the department runs, and the record lies in ground the department works in.',
            self::Own => 'What the module itself calls mine, in its own words.',
        };
    }
}
