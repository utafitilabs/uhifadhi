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

/**
 * WHAT A FACT IS ABOUT: the kinds of subject the platform files figures
 * under. The subject's uuid says which one.
 *
 * A module files a figure under the subject a page reads it for — a
 * department's KPI under the department, a zone's coverage under the zone —
 * so a page reads one row and never adds anything up. A kind of its own is
 * allowed (a string of the module's choosing, `<module>.<kind>`); these are
 * the ones the core's pages read.
 */
final class FactSubject
{
    public const string AREA = 'area';
    public const string ZONE = 'zone';
    public const string STATION = 'station';
    public const string DEPARTMENT = 'department';
    public const string PERSON = 'person';

    /**
     * THE WHOLE INSTALLATION, for a figure that belongs to no one subject.
     * Filed under {@see INSTALLATION_UUID}.
     */
    public const string INSTALLATION = 'installation';

    /** The nil uuid — the one installation there is. */
    public const string INSTALLATION_UUID = '00000000-0000-0000-0000-000000000000';

    private function __construct()
    {
    }
}
