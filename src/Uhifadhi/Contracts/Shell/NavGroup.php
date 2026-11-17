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

namespace Uhifadhi\Contracts\Shell;

/**
 * THE FIVE GROUPS THE SIDEBAR DRAWS, AND WHAT EACH ONE MEANS.
 *
 * A group is a PLACE in the sidebar, not a thing a module owns, and there are
 * exactly five of them. They are named here, in the order they are drawn, so
 * that a module joins one by CONSTANT instead of by retyping a string: a
 * near-miss label ("Organization", "system", "Org") used to make a fifth
 * heading rather than an error, and a sidebar with two Systems in it answers
 * "where am I" with a lie.
 *
 * WHAT EACH GROUP HOLDS — the meaning, written where the constants are,
 * because a module author choosing between them reads this and nothing else:
 *
 *   OBSERVATORY   what the organization WATCHES. Areas and everything under
 *                 them, Performance, and a module's organization-level page
 *                 set. If it is a reading of the work, it belongs here.
 *   ORGANIZATION  what the organization IS AND HOLDS. Departments, Team,
 *                 Files. Not readings — the standing facts about the body
 *                 doing the work.
 *   SYSTEM        what the system RAISES TO YOU. Alerts, Telemetry. Rows that
 *                 exist because the installation has something to tell you,
 *                 rather than because you went looking.
 *   SETTINGS      configuration, after everything the organization does.
 *                 One row with its tabs as children today; the group may
 *                 grow, which is why it is a group and not a row filed under
 *                 System.
 *   HELP          what is ABOUT the installation rather than part of it: the
 *                 installation's own manual. It is the foot of the sidebar,
 *                 under Settings (#24, ruled 17 Nov).
 *
 * ORDER IS PUBLISHED, NOT NEGOTIATED. {@see self::ORDER} is the order the
 * shell draws the headings in, whatever order the container registered the
 * bundles in and whatever position a contribution declared —
 * `NavSection::$position` orders the ROWS inside a group, which is the
 * question a contributing module can actually answer.
 *
 * THE STRING VALUES ARE THE LABELS. A group's value is the heading as drawn,
 * so a module still on a literal keeps working; use the constant.
 */
final class NavGroup
{
    /** What the organization watches. */
    public const string OBSERVATORY = 'Observatory';

    /** What it is and holds. */
    public const string ORGANIZATION = 'Organization';

    /** What the system raises to you. */
    public const string SYSTEM = 'System';

    /** Configuration, after the work. */
    public const string SETTINGS = 'Settings';

    /** About the installation: its manual, at the foot. */
    public const string HELP = 'Help';

    /**
     * The five, in the order the sidebar draws them.
     *
     * @var list<string>
     */
    public const array ORDER = [
        self::OBSERVATORY,
        self::ORGANIZATION,
        self::SYSTEM,
        self::SETTINGS,
        self::HELP,
    ];

    /** A place, not an object: there is nothing to construct. */
    private function __construct()
    {
    }

    /** Whether a label names one of the five. */
    public static function knows(string $group): bool
    {
        return \in_array($group, self::ORDER, true);
    }

    /**
     * Where a group is drawn — 0 for the first, 4 for the last.
     *
     * @throws \InvalidArgumentException when the label names no group, with
     *                                   the five in the message, because the
     *                                   author who typed it is choosing
     *                                   between them right now
     */
    public static function position(string $group): int
    {
        $position = array_search($group, self::ORDER, true);

        if (false === $position) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a sidebar group. The five the shell draws are %s — join one by constant, e.g. Uhifadhi\Contracts\Shell\NavGroup::OBSERVATORY.', $group, implode(', ', self::ORDER)));
        }

        return $position;
    }
}
