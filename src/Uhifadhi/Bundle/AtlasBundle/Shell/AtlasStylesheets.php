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

namespace Uhifadhi\Bundle\AtlasBundle\Shell;

use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\ShellBundle\Contract\StylesheetSourceInterface;

/**
 * EVERY ATLAS SHEET, IN EVERY HEAD — the map's included.
 *
 * THE DEFECT, NAMED. `atlas_calendar()` is written onto a module's Calendar
 * tab and `atlas_chart()` onto a topic's record, by pages in packages that
 * have never heard of this one; they link no atlas sheet, a component cannot
 * link one for itself, and the month rendered as a list of days while the
 * page returned 200. The map used to be exempt on the argument that a page
 * drawing a map knows it draws one — and that argument died the day a plate
 * became something a WIDGET draws: on a composed surface ANY cell may draw
 * one, so the organization Overview composed a map cell onto a page that
 * linked no map sheet and the plate came apart, silently, again. "We have
 * had this problem a million times."
 *
 * THE HEAD CANNOT BE DECIDED BY WHAT A PAGE HAPPENS TO COMPOSE. All three
 * sheets are published here and reach every page, and a hand-written link to
 * one is now a restatement the conformance rule refuses.
 *
 * LEAFLET'S OWN SHEET IS NOT HERE, and does not need to be: the Leaflet
 * bridge's Stimulus controller imports it on the pages that build a map,
 * which is a script's business rather than the head's.
 */
final readonly class AtlasStylesheets implements StylesheetSourceInterface
{
    /** @return list<string> */
    public function stylesheets(): array
    {
        return [AtlasBundle::STYLESHEET, AtlasBundle::CHART_STYLESHEET, AtlasBundle::CALENDAR_STYLESHEET, AtlasBundle::HEAT_STYLESHEET];
    }
}
