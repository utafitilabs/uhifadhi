# Changelog — Contracts

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * `Shell\NavGroup` — the four sidebar groups the shell draws, their meaning
   and their order, published as constants: Observatory (what the
   organization watches), Organization (what it is and holds), System (what
   the system raises to you) and Settings (configuration, last). A module
   joins one by constant, and anything else is refused with the four named
   rather than grown into a fifth heading
 * `Shell\OrgPagesInterface`, `Shell\OrgPage`, `Shell\Scope` and
   `Shell\ScopeSourceInterface` — a module answers at organization level by
   contributing its page set, and the shell mounts it and supplies the scope
   control. Every figure is the area query one scope wider, never a second
   aggregate
 * a performance topic publishes EXACTLY FOUR headline figures, one row
   (ruled) — `PerformanceTopicProviderInterface::kpis()`; a topic with more to
   say folds two verdicts into one card rather than dropping one
 * `KpiRole::ItemsResolved` — what a module closed in the period, without
   which a rising count of raised items cannot be read
 * `Performance\TopicLedgerInterface` — a topic whose reading is a ledger
   rather than one moving number, declared beside the topic provider so the
   across-the-topics strip can be four cards without a page naming a topic
 * `PlatePalette::CATEGORIES` — eighteen, the nine and the lightness ring
   after them; `category()` resolves every one of them and refuses a
   nineteenth rather than wrapping it into somebody else's colour
 * `Storage\FileSourceInterface` — a module saying that it stores files and
   what it calls one. They are the only two facts the files hub cannot work
   out for itself: an installed module with nothing stored yet and one that
   will never store anything look identical on a register, and the hub has no
   word of its own for somebody else's files.

 * `Area\StationDirectoryInterface` and its values — every station on the
   installation and who stands at each, across every area at once. It is the
   station-shaped half of `People\PersonPosting`: that seam answers where one
   person works, and a board assembled out of it loses every station nobody
   stands at, which is the reading the board exists for.

 * the module provider contract, the permissions a module declares, the area and
   user entity contracts, the user badge and the devkit contracts
 * `Kpi\DepartmentKpiProviderInterface` and its value objects — how a module
   puts a figure on the performance surfaces of a department that attaches it
 * BREAKING: `Shell\AreaNavChild::$swatch` and `Performance\ChartSeries::$swatch`
   are gone; both now take `cat: ?int` (1..9), the category's position in its own
   declared order, and the HOST resolves it to the palette. A module never hands
   the host a colour — a hex is right in one theme and wrong in the other, and
   wrong again on imagery. Callers in the patrol and incident modules follow.
 * `KpiRole::ItemsRaised` and `::ItemsUnowned`, and a `MatrixColumn` that may
   carry a role — the host adds a figure up per department across topics by the
   role a column declares, never by the word a module chose for it
 * `Performance\CellMark`, and a `MatrixCell` that may count STATES rather than
   measure a figure — a department's goals pace is a chip a goal, and averaging
   four states would answer a question nobody asked
 * `MatrixColumn::$total` and `::$totalDelta` — what a whole column comes to,
   PUBLISHED and never summed by the page: a host that added the cells up
   would be right about seats and wrong about days-to-settle, where a sum of
   averages is a number nobody measured. A column that stays silent simply has
   no total in its header.
 * `Kpi\FigurePeriod::shortLabel()` — the period's own words in one word
   ("aug", "q3", "2026"), so a band never formats a date and no surface prints
   an instant in whatever zone the server runs in
 * `Kpi\FigurePeriod::against()`, `comparedWith()` and `sameLastYear()` — what a
   period is READ AGAINST, stated once on the period so every figure on a page
   is compared the same way. **A provider reads `against()` and never
   `previous()` directly**: the topics were hardcoding "one month back", which
   compared a QUARTER against a month and was wrong by two
 * `Performance\TopicDecisionsInterface` and `TopicDecision` — what somebody
   has to DECIDE, which is neither a figure nor a movement: what is wrong, the
   ask, and the department it belongs to, all in the topic's own words. The
   third optional seam, and it carries the same refusal as the other two —
   a topic with nothing to raise is not asked. Every decision comes from
   figures the topic already holds; a seam that ran its own queries for a
   briefing would be a second, slower answer
 * `Performance\TopicMovementInterface`, `TopicMovement` and `MovementTone` —
   what moved this period, in the TOPIC's own words. An optional second
   interface, because a topic with nothing to say has no answer to give and a
   contract that made it answer anyway has started guessing. The host has
   every figure and still cannot say that distance rose while coverage fell:
   knowing which of a topic's figures explain each other is knowing what they
   MEAN, which is the one thing a seam never carries
 * `Atlas\PlatePalette` — the token NAMES a module may colour a map layer with,
   and never values: the five semantic ones, and `category($position)` for one
   of a set, which resolves to the PLATE's reading of the nine. **BREAKING for
   a module** that published a colour: the three layer/event value objects
   refuse a hex now
 * `Area\LivePositionsInterface`, `Area\LivePresence` and `Area\LivePosition` —
   where everybody on an OPEN watch is, at one instant: person, post, the fix
   and when it was taken, the accuracy, the derived presence state and whether
   it fell inside the post's catchment. A second interface rather than a method
   on `PresenceProviderInterface`, because that one answers about a DAY and is
   settled once the day is over while this is never settled; the area answers
   both with one service, so a ranger cannot read "at post, verified" on a map
   and unverified on the board. The area's PING INTERVAL travels with the
   answer — twenty minutes of silence is four missed pings at five and none at
   thirty — and `isStale()` calls a fix old after two intervals rather than one
 * `FigurePeriod::quarter()` and `::year()` — the other two windows the
   performance page offers, CALENDAR quarters and years rather than ninety
   and three hundred and sixty-five days: a reader asking for "this quarter"
   is asking about the quarter the organization reports in
 * `MatrixRow::$note` and `TopicMatrix::$bandNotes` — what a department and a
   band say about THEMSELVES, in the topic's own words: "3 positions ·
   org-wide" under a name and "each reads every area · 32 of 40 seats filled"
   under a boundary. The host draws the boundary because it made the placing
   inside it; what the boundary means is a sentence about somebody else's
   figures
 * `Atlas\CalendarFeedInterface` and its value objects — how a module has a
   month drawn: days, bounded pills, a hue role rather than a colour, and the
   surface naming the feed it wants as it names a plate's subject
 * `Area\PersonWatch`, and `Area\PersonDay` as a LIST of them with the day's
   totals — a day holds any number of check-in/check-out pairs, so the shape
   that could hold only one from-to is gone
 * `Area\PresenceProviderInterface` and its value objects — how a day at a post
   reads, derived on every read and never stored
 * `Roster\WatchProviderInterface` — the watches an area expects, which the area
   reads and does not own
 * `Area\StationSectionsInterface` and its value objects — how a module puts a
   banded section on a station's record and a block on its configure card
 * `Api\FieldErrorDocument` — the header that marks a refusal already written in
   the field API's own shape, so the URL space's safety net leaves it alone
 * `Devkit\CommandIo::readSecret()` — additive to the surface but breaking for an
   implementor: an existing implementation of the interface no longer satisfies it
   until it declares the method
