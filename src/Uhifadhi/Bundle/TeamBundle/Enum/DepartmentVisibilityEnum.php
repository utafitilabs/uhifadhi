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
 * WHO OUTSIDE A DEPARTMENT SEES IT (ruled 11 Oct 2026, #74). Asana lets each
 * team choose its privacy — private, membership by request, public to the
 * organization; here the same three levels, with nobody joining a department
 * on their own, so the middle one lists it rather than taking requests.
 *
 * Inside it — who belongs to it, who supports it, whoever reaches the whole
 * organization — every level reads it; the level is about everybody else
 * who reads departments.
 *
 * @see https://help.asana.com/s/article/sharing-and-limiting-access-to-teams
 */
enum DepartmentVisibilityEnum: string
{
    /** Nobody outside it knows it exists. */
    case Private = 'private';

    /** Its name, area and head are in the register; its people, figures and records are not. */
    case Listed = 'listed';

    /** Its record and figures are read by everybody who reads departments. */
    case Open = 'open';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Listed => 'Listed',
            self::Open => 'Open to the organization',
        };
    }

    /** The choice as the form offers it. */
    public function choice(): string
    {
        return match ($this) {
            self::Private => 'Private — its own people',
            self::Listed => 'Listed — everybody sees it exists',
            self::Open => 'Open — everybody reads it',
        };
    }
}
