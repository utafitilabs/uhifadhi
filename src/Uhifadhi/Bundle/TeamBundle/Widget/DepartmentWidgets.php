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

namespace Uhifadhi\Bundle\TeamBundle\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE DEPARTMENTS OVERVIEW AS A WIDGET SURFACE.
 *
 * The section's first tab was a fixed page: a band, a strip of figures and six
 * cells in a fixed order, the same for everybody. But what a reader comes to
 * this tab FOR differs by who they are — somebody staffing the organization
 * wants the vacancies and the filled-by-department bars; somebody wiring
 * modules up wants the matrix counts and the departments reading nothing; a
 * director wants the goals. One order cannot be right for all three, and the
 * product already has the answer to that: the surface ships directions, the
 * reader adopts one, copies it and mixes it.
 *
 * WHAT IS NOT A WIDGET. The identity band and the "Where this goes on" doors
 * stay page chrome: the band is what the section IS, drawn the same on every
 * tab, and the doors are the way out of it. Neither is a reading anybody would
 * compose differently, and a page whose every last element is arrangeable is a
 * page with no shape of its own.
 *
 * EVERY DESCRIPTION SAYS WHAT THE CARD BUYS AND WHAT IT COSTS, in that order,
 * because a library of nine cards a reader has never seen is a list of nouns
 * without it.
 *
 * A CATALOGUE IS A STATEMENT OF WHAT A SURFACE SHIPS, so this class has no
 * dependencies and nothing may vary it at runtime.
 */
final class DepartmentWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by — stable across releases. */
    public const string SURFACE = 'departments';

    /** What the composition this bundle ships with is CALLED when it leads the strip. */
    public const string DEFAULT_LABEL = 'The whole reading';

    public const string DEFAULT_DESCRIPTION = 'The organization read from the outside in: the figures, how each department is staffed and scoped, and what it reads. What is waiting on somebody is the banner above.';

    public function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup(
                    'shape',
                    'The shape of the organization',
                    'How the departments are staffed, where each one is read and what each one reads. These describe the establishment rather than judging it, and any composition can carry any of them.',
                ),
                new WidgetGroup(
                    'waiting',
                    'What is waiting on somebody',
                    'A department nothing measures, a post nobody holds, a goal nobody has declared. Every one of these is an ask rather than a number, which is why they are grouped apart from the cells above.',
                ),
            ],
            [
                new Widget('kpis', 'The figures', 'shape', 12, [12], true,
                    'Four counts and where each of them moved since the last closed period. Cheap to read and impossible to act on — it says what changed, never which department it changed in.'),
                new Widget('staffing', 'Positions filled by department', 'shape', 6, [12, 6], true,
                    'Ranked bars, longest first, scaled to the largest department: the one card that makes two departments comparable at a glance. It costs the whole width of half a row.'),
                new Widget('scope', 'Departments by scope', 'shape', 6, [12, 6], true,
                    'Which departments every area reads and which belong to one. The question it answers is asked once when an installation is set up and rarely again.'),
                new Widget('modules', 'Modules per department', 'shape', 6, [12, 6], true,
                    'How much of the installed catalogue each department reads. It is the matrix in one number a row, so it shows the shape and never the detail.'),
                new Widget('unattached', 'Departments with no module', 'waiting', 6, [12, 6], true,
                    'A department with people and positions but no figure of its own never reaches Performance. Short by design, and empty on a healthy installation.'),
                new Widget('vacancies', 'Positions nobody holds', 'waiting', 6, [12, 6], true,
                    'The empty posts, longest vacant first. It is the establishment\'s own backlog; it says nothing about whether the work is being done anyway.'),
                new Widget('goals', 'Goals declared', 'waiting', 6, [12, 6], true,
                    'What the departments have said they will reach. It is a ledger rather than a reading — the judgement of it lives in Performance.'),
            ],
            [
                new WidgetPreset(
                    'default',
                    self::DEFAULT_LABEL,
                    self::DEFAULT_DESCRIPTION,
                    ['kpis' => 12, 'staffing' => 6, 'scope' => 6, 'modules' => 12],
                ),
                new WidgetPreset(
                    'staffing',
                    'Staffing first',
                    'For somebody filling posts: the vacancies and the filled-by-department bars at full width, and the module cells dropped entirely — what a department reads is not their question this week.',
                    ['kpis' => 12, 'staffing' => 12, 'vacancies' => 12],
                ),
                new WidgetPreset(
                    'reach',
                    'What each department reads',
                    'For somebody wiring modules up: the counts per department beside the ones reading nothing at all. It drops the staffing picture, so a department that is empty and a department that is busy look the same here.',
                    ['kpis' => 12, 'modules' => 6, 'unattached' => 6, 'scope' => 12],
                ),
                new WidgetPreset(
                    'asks',
                    'What is waiting',
                    'Only the three cells that name somebody who has to do something, at full width with the figures above them. Nothing on it describes the organization, so it is a working list and not a briefing.',
                    ['kpis' => 12, 'unattached' => 12, 'vacancies' => 12, 'goals' => 12],
                ),
            ],
        );
    }
}
