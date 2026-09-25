# UPGRADE FROM 0.x to 1.0

## Positions are a register, a record and a configure page

**What changed** (ruled 2026-09-21). The positions screen used to be ONE page:
a widget canvas of thirteen widgets and seven presets, with the grants matrix
edited on it behind a `?position=` query parameter. It is three screens now,
and each of them does one thing.

| Route | What it is |
| --- | --- |
| `team_positions` (`GET /team/positions`) | the REGISTER — one collapsible card per position, on the department register's idiom. A preview, never an editor. |
| `team_position_show` (`GET /team/positions/{uuid}`) | the RECORD — the station record's skeleton, the matrix READ-ONLY, the holders and the history beside it. No tabs. |
| `team_position_configure` (`GET /team/positions/{uuid}/configure`) | ONE page holding everything a position can be changed to: the identity, the matrix, and retiring it. No tabs. |

**The writes.** `POST /team/positions/{uuid}/identity` (`team_position_identity`)
writes the name, the seat count and the kinds of placement together, because
they are refused together. `POST /team/positions/{uuid}/permissions` is
unchanged and still posts `grants[]`. `POST /team/positions/{uuid}/retire`
(`team_position_retire`) closes a position, and closes nothing else.

**`team_position_rename` is deprecated** and still works: post the identity
instead. It goes in 1.1.

**Every write now redirects to the position's configure page** rather than to
the register, so the sentence is read beside the thing it is about. Creating
one still goes to the register, because there is no position to go back to.

### The matrix widget surface is retired

`team_position_widgets` and its eight sibling routes — save, reset, the two
preset routes and the three custom-preset routes — are **302 redirects to the
register** for this release. Nothing 404s, and nothing arranges anything: the
register's whole content was replaced, so there is no layout left to keep.

`TeamBundle\Widget\PositionWidgets`, its seven presets and the thirteen
`templates/positions/_w_*.html.twig` partials are marked `@deprecated` and
**deleted in 1.1**. If your installation overrode one of those templates, the
override is dead markup now; delete it with the release that deletes ours.

`TeamBundle\Controller\PositionWidgetsController` keeps its class name and its
route names and takes **one** constructor argument (the router) instead of
five.

### Positions are retired, never deleted

Owner, 21 Sep 2026: *"we don't delete things."* A position is closed the way a
shift is closed and an account is deactivated.

| Member | Meaning |
| --- | --- |
| `Position::getRetiredAt(): ?\DateTimeImmutable` | the day it was closed; **null is a position still in use**, which is a positive fact rather than "unknown" |
| `Position::isRetired(): bool` | the same question, read positively |
| `Position::retire(\DateTimeImmutable)` / `::reinstate()` | the stamp, and clearing it |

**A retired position cannot be given to anybody.**
`UserService::assignPosition()` throws
`TeamBundle\Exception\PositionRetiredException`, and
`PositionRepository::findAssignable()` — not `findAllOrdered()` — is what the
member and invite pickers read, so a retired one is **absent from every picker
and present in the register, greyed**. An administrator told a name is already
taken has to be able to find the row that is holding it.

**Retiring is refused while anybody holds it**, and the refusal names the
count: `TeamBundle\Exception\PositionHeldException`. **The seat count is
refused the same way** — it cannot fall below the people already sitting in it
(`TeamBundle\Exception\SeatsBelowHoldersException`), because choosing which
two of six holders lose their seat is not a decision a product may make.

### A holding carries the day it started

`User::getPositionSince()` / `::setPositionSince()`, column
`team_user.position_since`, stamped by `UserService::assignPosition()` **only
when the holding actually changes** — re-saving a form that names the position
somebody already holds must not reset a date a reader trusts. **Null is
"unknown"**, the honest answer for a holding written before the day was
recorded; the migration invents nothing.

### Two constructors changed

- `TeamBundle\Service\PositionService` is now
  `(EntityManagerInterface, ConcernCatalogue, UserRepository)` — the repository
  is new and last, and it is what answers "how many people hold this" for the
  two refusals above. It gained `setIdentity()`, `retire()` and `reinstate()`.
- `TeamBundle\Controller\PositionController` is
  `(Environment, PositionRepository, UserRepository, ConcernCatalogue,
  PositionBoard, PositionHistory, PositionService, CsrfTokenManagerInterface,
  UrlGeneratorInterface, AreaAuthority)`. It no longer takes the department
  repository, the department membership, the token storage or the widget
  service, and **`widgetContext()` is gone** — the widget surface it fed is
  retired.

### Two new services, and what they are for

`TeamBundle\Service\PositionBoard` (`team.position_board`) is the ONE
derivation all three screens read: `register()`, `card(Position)`,
`holders(Position)` and `orphans(Position)`. "12 of 21 concerns · 2 sensitive"
is on the register card's head, in the record's fact band and in the configure
page's bar, and the moment a template counts it for itself the three disagree.
`TeamBundle\Service\PositionHistory` (`team.position_history`) answers what
the installation can truthfully say happened to a position, from the stored
facts that carry a date — the same discipline as `MemberHistory`.

Their value objects are `TeamBundle\Model\{PositionCard, GrantGroup, GrantRow,
HolderRow}`.

### The migration

`TeamBundle\Migrations\Version20260921003000`, expand only and nothing to
backfill: `team_position.retired_at` and `team_user.position_since`, both
nullable for the life of the table.

**It writes no `COMMENT ON COLUMN … (DC2Type:datetime_immutable)`, and that is
correct.** DBAL 4 removed type comments — there is no `DC2Type` left in the
library — so `doctrine:migrations:diff` on this platform emits the bare
`ALTER`, and a hand-written comment would make every installation's next diff
generate a migration removing it. `tests/Core/MigrationsCoverSchemaTest` is
what holds the two together.

### Four new Stimulus controllers

`uhifadhi--team-bundle--grants` (`assets/controllers/grants_controller.js`),
lazy: the configure page's per-module **grant all / none** and the save bar's
**change preview**. It writes nothing — the matrix saves by hand with no
JavaScript at all — and it leaves a disabled box alone, because a disabled box
is a pair beyond a bounded administrator's own position.

`uhifadhi--team-bundle--folds` (`assets/controllers/folds_controller.js`),
lazy: the grants matrix's **Fold all / Open all**, on the position record and
on the configure page. The folds are native `<details>` and still open one at
a time with no JavaScript; this is only the pair of shortcuts over them.

`uhifadhi--team-bundle--yes-no` (`assets/controllers/yes_no_controller.js`),
lazy: the two-button **On / Off** control every configure section states a
rule with. The pair is a radio group underneath and posts with no JavaScript.

`uhifadhi--team-bundle--rank-order` (`assets/controllers/rank_order_controller.js`),
lazy: the **grip** on every row of a rank scale under Team › Configure ›
Ranks — drag, or focus it and use the arrow keys — and the row numbers that
follow it. The order is posted as the form's own field order; without the
controller the rows still save, but nothing moves.

**All four arrive through `assets/controllers.json`**, and Flex keeps that
file: on every `composer install` and `composer update` it reads each
package's `assets/package.json` and adds, under `@uhifadhi/uhifadhi`, any
controller the file does not yet name, with the `enabled` and `fetch` the
package declares (`Symfony\Flex\PackageJsonSynchronizer::updateControllersJsonFile()`,
run from `Flex::finish()` after install and update). An installation that
updates the core through Composer therefore has nothing to do. Only a
working copy that reaches the core through a link, where Composer never ran
for the new controller, adds the entries itself:

```json
"yes-no":     { "enabled": true, "fetch": "lazy" },
"rank-order": { "enabled": true, "fetch": "lazy" }
```

A controller a host never enables is a file nobody loads, and the button is
drawn and dead.

## The rank is set on the Position card

**What changed** (ruled 2026-09-25). A person's rank is written on their
configure page inside the **Position** card, under the assigned position: the
rank select and its **From** date post with the seat, the placement and the
departments in the card's one save. The card is `#position` and reads
"Position and rank" while the organization uses ranks; the record's "Change
the position or rank" door opens it.

| Route | What it is now |
| --- | --- |
| `POST /team/{uuid}/position` (`team_member_position`) | the one write. Two more fields: `rank` (a rank's uuid, or empty for "no rank") and `since` (`Y-m-d`). A request that names no `rank` field keeps the rank that stands; a retired rank, or a date before the day the current rank started, refuses the whole save and writes nothing. |
| `POST /team/{uuid}/rank` (`team_member_rank`) | **gone.** There is no rank card and no rank route; the address answers 404. |

`MemberController::rank()` is gone with it. An installation that posted to the
rank route by hand posts `rank` and `since` to the position route instead.

## The People register filters by station and by what a module contributes

**What changed** (ruled 2026-09-25). The bar at `/team` has two more grouped
dropdowns. **Station** lists where people stand, read through
`Contracts\People\PersonPostingProviderInterface` — grouped by area once
postings span several, with **Not stationed** last — and writes `?station=`.
After Rank come the dropdowns modules contribute through the new seam
`Contracts\People\PeopleFacetProviderInterface`, tag `uhifadhi.people_facets`;
each writes its own key (the area's is **Status**, `?status=`). Both narrow the
rows and the CSV export; the CSV's columns are the table's and gain none.

**For a module.** Implement `PeopleFacetProviderInterface::facetFor(array
$userUuids): ?PeopleFacet` and tag the service `uhifadhi.people_facets` by
hand. The facet's `key` is a query parameter name and may not be one the
register owns (`q`, `tier`, `position`, `department`, `station`, `rank`,
`state`, `page`) — the register refuses the container with a `LogicException`
naming the key. Every option carries the people it applies to, so the count
and the filter are one fact; null draws no dropdown.

**`RosterQuery`** carries `station`, `facets` (the contributed choices, key →
value), and `only` (the people a seam choice leaves; null when none is chosen);
`fromRequest()` takes the contributed keys as its second argument. The area
bundle's `People\AreaPeopleStatus` is the first provider.

## A department is a placement, not an owner

**What changed** (ruled 2026-09-21). A position used to look as though it
belonged to a department, and that was the wrong shape. A department is not a
thing a position sits inside; it is one of the two dimensions of **where
somebody is placed**. Three facts moved at once, because they are one fact:

1. **`Position` carries no department.** `Position::getDepartment()`,
   `setDepartment()` and `getQualifiedName()` are **gone**, and so is
   `Department::getPositions()`. A position is written and read as its bare
   name.
2. **A position's name is unique across the whole organization.** There is one
   Sergeant, not one per department, and a reader of somebody's record never
   has to ask which one. The index `uniq_team_position_department_name` is
   replaced by `uniq_team_position_name`.
3. **Reach is recorded against the person.** `User::getPlacement()` answers a
   new `TeamBundle\Entity\Placement` with two dimensions — the **ground** (the
   whole organization, or one or more named areas) and the **departments** (all
   of them, or a named set, several allowed). `User::getDepartment()` is gone;
   `User::getDepartments()` answers `null` (all), `[]` (none) or the named list,
   and `User::getDepartmentLabel()` is the one-line fragment a row shows.

The case that settles it is the ordinary one: a data scientist supporting
Ecology and Protection but not ICT is **still one position**, placed against two
departments. Under the old shape that person needed either a second position or
a department of convenience invented to hold them.

**It fails closed.** Somebody with **no** placement reaches no ground, so every
area-scoped check refuses. Unplaced is not "everywhere".

**What the migration does.** `TeamBundle\Migrations\Version20260921001000`,
expand → backfill → contract in one transaction. It preserves the reach each
person had the moment before the upgrade, which the old code DERIVED as
`position → department → area`:

| Before | After |
| --- | --- |
| a position under an **area-level** department | its holders are placed at that area, and in that department |
| a position under an **organization-level** department | its holders are placed across the organization, and in that department |
| a position under **no** department, or a person holding **no** position | placed across the organization and across all departments |

**Nobody gains or loses a permission.** What changes is where the answer is
stored.

**Duplicate names are renamed, not refused.** `Analyst` in Ecology and `Analyst`
in Protection were two legal rows and are now one name twice, so the second and
any further one take a numeric suffix — `Analyst (2)` — and the migration
`RAISE NOTICE`s a line naming each. **Read the migration output** and rename
them into your organization's own words; the product cannot choose the word for
you. Refusing the migration instead would leave an installation unable to
upgrade over rows it was told to write.

**Destructive, and signed.** `team_position.department_id` and the
department-scoped name index are dropped **in this release**, which is the same
release that stops reading them. That is deliberate rather than an exception to
the two-release rule: no code path reads the column after this version, an
unmapped column would make `doctrine:migrations:diff` report a change on every
installation forever, and there is no window in which anything could still be
using it. **Take a backup and run `doctrine:migrations:migrate --dry-run`
first** — `down()` puts the column and its index back, but not the values.

**What a position gained.**

| Member | Meaning |
| --- | --- |
| `getSeatCount(): ?int` / `setSeatCount(?int)` | how many people may hold it; **null is unlimited**, and a count below 1 is refused |
| `hasUnlimitedSeats(): bool` | the same question, read positively |
| `getAllowedKinds(): list<ScopeKind>` / `setAllowedKinds()` | which kinds of placement it allows — **`Organization` and/or `Area` only**, because that is what a placement says. `Department` is the placement's *other* dimension and is not gated by the position; `Own` is a scope a **concern** offers, not a way of placing somebody. Anything else is refused, and so is an empty list. |
| `allows(ScopeKind): bool` | whether a placement of that kind may be made |

Backfilled per position from the reach its holders already had: `["area"]` where
its department was an area's, `["organization"]` otherwise. Nothing is widened.

**Assigning to a full position is refused,** and the refusal names the holder:
`UserService::assignPosition()` throws
`TeamBundle\Exception\PositionFullException`. A deactivated holder does not
occupy a seat. `UserRepository::findActiveHolders(Position)` is the query behind
it, and `UserService::place(User, ?Placement)` is how a placement is written.

**`UserService` takes one more argument.** Its constructor is now
`(EntityManagerInterface, UserPasswordHasherInterface, SuperAdminInvariant,
PositionVacancy, UserRepository)` — the repository is new and last.

**`AreaAuthority` reads the placement.** `authorityArea()` is replaced by
**`authorityAreas(): ?list<AreaInterface>`** (null means unbounded), and
`reaches(Position)` and `assignable(array)` are **gone** — a position carries no
ground, so which position somebody is moved between says nothing about whose
boundary the move crosses. **`reachesPerson(User)`** asks the question that
replaced them.

**A department's members and positions are derived, in one place.**
`TeamBundle\Service\DepartmentMembership` (service `team.department_membership`)
answers `membersOf(Department)`, `positionsIn(Department)` and
`covers(User, Department)`; every screen reads it rather than deriving its own.
A department's positions are the distinct positions **its members hold**.

**Routes and screens that are gone.** `team_department_file` (filing a position
under a department) and the departments register's "No department yet" band: a
position has nothing to be filed under. The Positions-vocabulary configure
screen no longer groups by department — `PositionVocabulary::read()` returns
`['names' => list<string>, 'positions' => int]`, and the "appears in more than
one department" footer is gone with the case it reported. The position pickers
on the member and invite screens are a flat list in the template variable
`positions`, not `groupedPositions`.

**What a module must do.** If your module reads `Position::getDepartment()`,
`Position::getQualifiedName()`, `Department::getPositions()` or
`User::getDepartment()`, none of them exists. Read the person's placement, or
ask `DepartmentMembership`. If your module's own screens grouped positions under
departments, they group under nothing now — the name is unique.

## One word for a station, and one for being at it

**What changed** (ruled 2026-09-20). "Post", "posted to" and "posting" meant
the place, the act and the placement all at once, so every screen had to be
read twice. Three words now, each meaning one thing:

| The idea | The word | In a sentence |
| --- | --- | --- |
| the place | **station** | "Eastgate is a station" |
| a person's placement there | **stationed at** | "3 rangers stationed at Eastgate" |
| the placement as a noun | **assignment** | "End the assignment" |

Every user-facing word in the core is swept: page titles, tab captions, table
headings, empty states, flashes, hints and the station event log ("T. Ndosi
stationed here", "J. Mollel's assignment ended"). `CheckInStatusKind::AtPost`
now reads **"at a station"**.

**Park-given names are untouched.** A station called "Eastgate Post" keeps
its name — it is a proper noun the organization chose, not the product's word
for the idea, and the sweep deliberately leaves such names alone.

**Identifiers have NOT moved,** and that is the two-release rule rather than an
oversight: anything an installation, a module or the handset references keeps
its old spelling this release so nothing written against it breaks in the same
release that changed the copy.

| Identifier | Kind | Referenced outside the core? |
| --- | --- | --- |
| `team_postings` | route name | possibly — an installation may generate it |
| `area_posting_end`, `area_posting_lead` | route names | possibly |
| `/postings/{uuid}/lead`, `/postings/{uuid}/end`, `/stations/{uuid}/postings` | URL segments | **yes** — people bookmark and link to them |
| `area.postings` | service id | possibly, by a module reading the board |
| `Entity\Posting` | entity and table `posting` | **yes** — a module may join it |
| `PostingQuery`, `PostingRow`, `PostingStation`, `PostingBoard`, `PostingService`, `PostingDoorService`, `PostingException` | class names | possibly |
| `StationEventKind::Posted`, `::PostingEnded` | enum cases (stored values) | **yes** — stored in `station_event` |
| `CheckInStatusKind::AtPost` | enum case (stored value `at_post`) | **yes** — the handset sends it |
| `posted` | sort key and filter value in station/zone URLs | **yes** — a bookmarked filter |

**When they turn over.** The stored enum values (`at_post`, the station-event
kinds) and the URL segments need a release that accepts both spellings before
one is dropped; the roster module owns the `at_post` → `at_station` move on the
handset's side. Class and service names are ordinary renames once the release
after this one is open.

## Every gate names a (concern, verb) pair

**What changed.** A permission used to be a flat value — `area.view`,
`team.manage`. It is now a **pair**: the concern it is about and the verb being
done to it, written `<concern>.<verb>`, with the verb the segment after the
**last** dot so a concern key may carry hyphens (`personal-details.read`).
Routes, doors and stored grants all spell it that way, which is what lets the
build tests hold the three together.

**One voter, one column.** `GrantVoter` answers pairs and nothing answers the
old flat values. A position carries `grants` alone: the flat `permissions`
column is backfilled into it by `Version20260921002000` and
`Version20260921004000`, and dropped by `Version20260924000100`.

### The mapping, site by site

Scan this: it is the whole of what changed about who can do what. Nobody gains
a power here, and the two places a distinction was **lost** are called out.

| Old value | Where | New pair |
| --- | --- | --- |
| `area.view` | `AreaController::index`, `::show`; `AreaWidgetsController` (all) ; `OrgDashboardController` (all) | `areas.read` |
| `area.view` | `ZoneController::index`; `ZoneConfigureController::configure` | `zones.read` |
| `area.view` | `ZoneConfigureController::export` | `zones.export` |
| `area.view` | `StationsController::index`; `StationConfigureController::configure` | `stations.read` |
| `area.edit` | `AreaController::settings`; `AreaEditController::edit`, `::replaceBoundary` | `areas.configure` |
| `area.create` | `AreaCreateController::new` | `areas.configure` |
| `area.delete` | *(no route enforced it)* | `areas.configure` in the backfill |
| `area.edit` | `ZoneEditController::rename`, `::ring`; `ZoneImportController::preview`, `::confirm` | `zones.configure` |
| `area.edit` | `ZoneEditController::remove`, `::clear` | `zones.delete` |
| `area.edit` | `StationEditController::add`, `::rename`, `::describe`, `::move`, `::catchment`, `::activity` | `stations.configure` |
| `area.edit` | `StationEditController::post`, `::end`, `::lead` | `assignments.manage` |
| `module.view` | `AreaModulesController::grid` | `modules.read` |
| `module.create` | `AreaModulesController::customize`, `::install`, `::reorder`, `::uninstall` | `modules.configure` |
| `duty.checkin` | the handset's check-in endpoints | `duty.record` |
| `team.manage` | `MemberController::show`; `InviteController::show`; `TeamController::index`; `TeamRolesController::index`; `TeamSectionController::overview`; `TeamWidgetsController` (all); `TeamPostingsController::index` | `directory.read` |
| `team.manage` | `MemberController::update`, `::position`, `::deactivate`, `::reactivate`, `::tier`; `InviteController::create`, `::invite` | `directory.manage` |
| `team.manage` | `MemberController::resendInvitation`, `::sendResetLink` | `personal-details.manage` |
| — | the member record's contact/sign-in block (a door) | `personal-details.read` |
| `team.manage` | `PositionController::index`; `PositionWidgetsController` (all) | `positions.read` |
| `team.manage` | `PositionController::create`, `::rename`, `::permissions`; `TeamConfigureController::settings`, `::vocabulary`, `::createTitle`, `::renameTitle` | `positions.configure` |
| `team.manage` | `DepartmentController::index`, `::show`; `DepartmentSectionController::overview`, `::modules`; `DepartmentWidgetsController` (all); `AreaDepartmentController::tab`, `::configure`; `PerformanceController` (all) | `departments.read` |
| `team.manage` | `DepartmentController::create`, `::rename`, `::changeScope`, `::toggleModule`, `::declareGoal`, `::withdrawGoal`, `::deactivate`, `::reactivate`; `DepartmentConfigureController` (all); `PerformanceConfigureController::settings` | `departments.configure` |

### And the modules' own values, carried across generically

`TeamBundle\Migrations\Version20260921004000`. The table above is the CORE's
values; a module's — `patrols.record`, `incidents.manage`, `roster.plan`,
`observation-kinds.configure` — were stored in the same column and are the
core's to carry, because `team_position.grants` is the core's table and a
module cannot write a migration against a column it does not own. Without
this, an installation upgrades and goes dark on every module at once.

It names no module, and cannot: the core does not know which are installed.
It translates by SHAPE, over the values that are actually in the rows.

| Old value | New pairs |
| --- | --- |
| `<slug>.<verb>` (anything not in the table above) | `<slug>.<verb>` unchanged, **and** `<slug>.read` |

**Why `<slug>.read` comes with it.** Reading is a declared power now and was
not one before: somebody who could record a patrol could obviously see the
patrols, and under the new model that is a pair they were never given. Without
this line the screen they recorded from yesterday refuses them.

The slug is everything before the **last** dot, so a concern key may carry
hyphens. The migration **only adds** — nothing is removed from `grants`,
`permissions` is untouched, and running it twice writes what running it once
wrote. A value whose module has since been uninstalled is carried across as an
**orphaned grant**, which the position screens draw muted and revocable rather
than dropping.

**What a module's own upgrade note should say:** re-tick only for the new
`configure` / `export` pairs your module declares and the old values did not
distinguish. Everything a position could already do, it can still do.

**Two judgements worth disagreeing with, if you do.**

- **The three area values collapse into `areas.configure`.** Identity, boundary
  and settings are plainly configuration; creating an area and deleting one are
  neither field facts nor settings, and the six verbs give nothing that fits
  them better. An installation that deliberately gave somebody `area.view` and
  `area.create` while withholding `area.edit` will find that distinction gone —
  **re-read those positions.** It also means `area.create`'s old status as the
  one inherently global permission is no longer expressible.
- **`TeamPostingsController::index` is `directory.read`, not
  `assignments.read`.** Who is stationed where is the ground's concern, declared
  by the area bundle — and a Team page must not gate on a concern that vanishes
  when the ground package is absent. `AreaDepartmentController` moved from
  `area.view` to `departments.read` for the same reason.

**`team.manage` fans out to eight pairs in the backfill,** deliberately
generously: everybody who could administer the team keeps being able to, and an
organization that wants the finer grain now has the rows to take away. Guessing
which half of team administration each holder was meant to have would lock
somebody out of a page they used yesterday.

### A verb is declared where something enforces it

The declarations were trimmed to what routes and doors actually gate. There is
no `areas.delete` because nothing deletes an area; no `positions.delete` or
`departments.delete` because this bundle **deactivates and never destroys**; no
`directory.export` because nothing exports the roster yet. A declared power
nothing enforces is a box an administrator can tick that changes nothing, and
`tests/Core/EveryRouteNamesItsPairTest` fails the build in both directions —
on a route naming an undeclared pair, and on a declared pair nothing enforces.
**Declare the verb in the same change that ships the thing enforcing it.**

### A per-area gate is asked WITH its area

**What changed.** Every route whose path names an area and whose pair is about
the ground now passes it:

```php
#[Route('/areas/{uuid}/zones', name: 'area_zones', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('zones.read', subject: 'area')]
public function index(
    Request $request,
    #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
): Response
```

**Why.** `#[IsGranted('zones.read')]` with no `subject:` asks the voter with a
null subject, and a null subject means *no area in context* — which any
placement satisfying any ground at all passes. So the second of the three
questions was asked and always answered yes, and somebody placed only at one
area opened another area's page. Fourteen routes were in that state.

**Which concerns this is about is the declaration's answer, not the path's.** A
concern is per-area when it offers `ScopeKind::Area` — `areas`, `zones`,
`stations`, `assignments`, `duty`, `modules`. `departments`, `directory`,
`positions` and `personal-details` are about people and offer organization or
department, so an area's Departments tab is **not** gated on the ground and
must not be made to look as though it is.

**It is a build refusal now, not a list.**
`tests/Core/EveryRouteNamesItsPairTest::testEveryRouteGatingAPerAreaConcernPassesItsArea`
fails a route that names an area in its path and asks a per-area pair without
it, and
`tests/Core/RouteByComposedPositionTest::testEveryAreaScopedRouteRefusesTheRightPositionInTheWrongArea`
drives every one of them as somebody holding exactly the right pairs at a
different area and requires a 403.

**A gate written in code passes its subject as an argument**, and two did not:
`DutyApiContext::requireRanger()` now **takes the area** —
`requireRanger(AreaOfInterest $area)` — so a handset whose ranger is posted at
one area can no longer write a day into another; callers resolve the area
first. The area tab strip (`AreaShellSource`) asks `modules.read` with the area
it is drawn for, so the tab is not offered to somebody who would be refused on
the click.

**Upgrading a module.** Sweep your own `#[IsGranted]` attributes: any route
under `/areas/{uuid}/…` gating one of your per-area concerns needs
`subject: 'area'` and a controller signature taking the resolved area rather
than a bare `string $uuid`. `AccessConformanceTestCase` holds you to it.

### Doors go through one helper

A door — a link, a button, a section leading somewhere a permission guards — is
written `{% if door('zones.configure', area) %}`, never `is_granted`. Both take
the same string; the difference is that `door()` is enumerable, which is what
lets a test walk every door and hold it against the routes.
`tests/Core/EveryDoorGoesThroughTheHelperTest` refuses `is_granted(` in any
shipped template. In PHP, inject `Uhifadhi\Bundle\TeamBundle\Access\Door`.

### What a module must do

Declare concerns through `ConcernSourceInterface` (tag
`uhifadhi.access.concerns`, applied by hand), gate routes on pairs, draw doors
with `door()`, and extend
`Uhifadhi\Bundle\TeamBundle\Test\AccessConformanceTestCase` in its own CI —
it holds the same rules the core holds itself to: one declaration per key, a
sentence on every concern, a module concern naming its module, `own` carrying
the module's own words, sensitive concerns named explicitly, and every door
through the helper.

### Two rulings recorded here so they are not re-litigated

- **`Position::allowedKinds` offers `Organization` and `Area` only, and that is
  correct.** A placement is made at the organization or at named areas — that is
  what a placement *is*. `Department` is the placement's **other dimension**,
  asked separately and not gated by the position; `Own` is a scope a **concern**
  offers, not a way of placing somebody. Allowing either as a placement kind
  would mean nothing, so the setter refuses them.
- **The department-first position widgets are kept, flattened, pending a design
  verdict.** `positions/_w_dept_a`, `_w_dept_b`, `_w_dept_c`, `_w_dept_e`, the
  rail in `_w_matrix_b` and `team/_w_roster_d` were five layouts of one idea:
  make the department the structure of the page rather than a column on it.
  **That premise is gone** — a position belongs to no department. They are
  flattened so they stay correct and are marked in the widget catalogue as
  "premise changed — redesign or delete pending a design verdict". They are not
  deleted, because which of them still earns its place is a design decision.

## The old permission machinery is deprecated, not yet gone

**What changed.** Everything that spoke the fixed seven permissions is gone.
An installation upgrades through the backfills, which translate what it held.

| Removed | Replaced by |
|---|---|
| `TeamBundle\Enum\PermissionEnum` | declared concerns × the six verbs, spelled `Contracts\Access\Grant` |
| `TeamBundle\Service\PermissionCatalogue` | `TeamBundle\Access\ConcernCatalogue` |
| `TeamBundle\Security\PermissionVoter` | `TeamBundle\Security\GrantVoter` |
| `TeamBundle\Model\Permission` | `Contracts\Access\Concern` |
| `Contracts\ModulePermission` | `Contracts\Access\Concern` |
| `Contracts\PermissionDeclarationInterface` | `Contracts\Access\ConcernSourceInterface` |
| `RegistryBundle\Service\ModulePermissionCatalogue` | the tagged concern sources |
| `AreaBundle\Access\AreaPermissions` | `AreaBundle\Access\AreaConcerns` |
| `Position::$permissions`, the `permissions` column | `Position::$grants`, the `grants` column |

**Why it is one release and not two.** A rename is a rename everywhere while
nobody runs this in production: a deprecation stub left in place only lets the
old word survive, and the backfills are what carry an upgrading installation's
grants across. What a position held is translated, not lost.

**What a module must do.** Declare a `ConcernSourceInterface` (tag
`uhifadhi.access.concerns`, by hand), gate on pairs, draw doors with `door()`,
and extend `AccessConformanceTestCase`. There is no second catalogue to keep
in step.

## The access vocabulary: concerns, six verbs, four scopes

**What changed.** `Uhifadhi\Contracts\Access` publishes the vocabulary every
permission is spelled in, and a bundle or module declares what there is to have
a permission about:

| Class | What it is |
| --- | --- |
| `Verb` | the six, fixed: **read · record · manage · configure · delete · export**. A module cannot invent a seventh. |
| `ScopeKind` | the four: **organization · area · department · own**. |
| `ConcernInterface` / `Concern` | a thing the product lets somebody act on: a key, a label, one sentence, the verbs it supports, the scope kinds it offers, whether it is **sensitive**, and the module's own words for "own". |
| `ConcernSourceInterface` | the seam. Tag `uhifadhi.access.concerns`, applied **by hand** — a reusable bundle is not autoconfigured. |
| `Grant` | one cell of the matrix, and the one spelling of a pair: `<concern>.<verb>`, the verb being the segment after the **last** dot, so a concern key may carry hyphens and never a dot. |

**A concern exists only by declaration,** and whoever enforces one declares it.
The core declares its own through the same seam a module uses: the area bundle
declares areas, zones, stations and assignments; the team bundle the directory,
personal details, positions and departments; the registry, modules. There is no
privileged list in the middle of the product, and a module's power cannot appear
on a page without its owner having said so — or survive the module being
uninstalled.

**Declaring grants nobody anything.** The matrix gains a group of rows; who
ticks them is the organization's business. Installing a module must never hand
an existing person a new power.

**How a module declares its concerns.**

```php
final readonly class RosterConcerns implements ConcernSourceInterface
{
    public function declaredBy(): string { return 'Roster'; }

    public function concerns(): iterable
    {
        yield new Concern(
            key: 'live-positions',
            label: 'Live positions',
            description: 'See where a ranger is now, and their ping history.',
            verbs: [Verb::Read],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area, ScopeKind::Own],
            sensitive: true,
            ownWords: 'Own team',
            moduleSlug: 'roster',
        );
    }
}
```

```php
// config/services.php — the tag goes on by hand.
$services->set('roster.access.concerns', RosterConcerns::class)
    ->tag(ConcernSourceInterface::TAG);
```

**A fact about a person or a case is its own concern** — live positions, case
files, money, personal details, the bytes of a file — declared `sensitive: true`
so an organization can withhold it without withholding the page it sits on.


## One spelling: "organization"

**What changed.** Every word a reader can see now spells it **organization**
(ruled 2026-09-21). The sidebar heading already did; the Settings tab beside
it read "Organization", the scope control read "Organization — all areas",
and the dashboard's own captions read "the organization" — three spellings on
two screens. Templates, labels, page titles, sublines, hints, docs and these
upgrade notes are swept, and `tests/Core/OneSpellingOfOrganizationTest` fails
the build on the other spelling in any shipped template or translation
catalogue.

**What did NOT change: identifiers.** Anything an installation or a module
REFERENCES keeps the old spelling this release and turns over on the
two-release rule, so nothing you wrote against breaks in the same release
that changed the copy:

| Identifier | Kind | Referenced by an installation? |
| --- | --- | --- |
| `organization_dashboard` | route name | **yes** — it is the default of `shell.home_route`, and an installation may generate it |
| `organization_widgets`, `…_save`, `…_reset`, `…_preset`, `…_preset_copy`, `…_preset_create`, `…_preset_apply`, `…_preset_rename`, `…_preset_delete` | route names | possibly — a host may generate or override them |
| `/settings/organization` | URL segment (`SettingsTab::Organization`'s value) | **yes** — people bookmark and link to it |
| `shell.settings.organization_identity` | service id (the alias a host sets) | **yes** — a host aliases it to publish its identity |
| `Uhifadhi\Contracts\Settings\OrganizationIdentity`, `OrganizationIdentitySourceInterface` | contract class names | **yes** — a host implements the interface |
| `Scope::organization()`, `Scope::isOrganization()` | contract methods | **yes** — modules call both |
| `PerformanceScope::organization()`, `isOrganization()` | contract methods | **yes** — a module's performance topic calls them |
| `PerformanceController::ORGANIZATION` | public constant (its VALUE now reads "Organization — all areas") | unlikely, but public |
| `TeamBundle\Performance\OrganizationBand`, service `team.performance.organization_band` | class and service id | no |
| `AreaBundle\Service\AreaMapService::organization()` | method | no |
| `OrgOverviewWidgets::SLUG` = `'organization'` | contributor slug | no, but it keys `by.<slug>` in cell context |
| `SettingsTab::Organization` | enum case | no |

**When they turn over,** the route names and the `/settings/organization`
address are the two that need a deprecation period rather than a rename: a
route keeps its old name as an alias for one release, and the old URL
redirects. The rest are ordinary renames once the release after this one is
open.

**Two test fixtures deliberately keep the old spelling** and must not be
swept: `NavGroupTest` and `NavigationTest` use "Organization" as the *near
miss* a sidebar group must refuse — a source that typed it used to make a
fifth heading, and the refusal is what stops that.

## One clock-fed source says what period it is

**What changed.** `Uhifadhi\Contracts\Kpi\CurrentPeriodInterface` — `now()`,
`month()`, `quarter()`, `year()`, `days(int)` — answered by
`AtlasBundle\Calendar\Periods`, which is fed by `psr/clock`.

**Why.** "This month's figures" was decided separately in eleven places
across two bundles, each writing
`FigurePeriod::month(new \DateTimeImmutable())`. Every one asked the wall
clock, so every one turned over on the 1st, two surfaces either side of
midnight could caption two different months in one reading, and no test
could stand on a month boundary without moving the server's date.

**What to change in a module.** Type-hint the contract and stop building
periods from an instant you took yourself:

```diff
-$period = FigurePeriod::month(new \DateTimeImmutable());
+$period = $this->periods->month();
```

**Declare it the way your bundle actually needs it.** A package whose
screens cannot draw without a period asks for the contract outright; a
package that is *also* a model an installation persists — entities, a user
provider — must still boot in a kernel that took the model and not the
screens, so it asks with `->nullOnInvalid()` and says what is missing at the
screen:

```php
$services->set('your.navigation', YourNavigation::class)
    ->args([service(CurrentPeriodInterface::class)->nullOnInvalid()]);
```

Naming another bundle's service id (`atlas.periods`) instead makes that
kernel fail at container compile, in somebody else's suite, a long way from
the change that caused it. A bundle declares what it needs; it never assumes
what a kernel registered.

## `/` is the organization dashboard

**What changed.** The core ships a dashboard at `/` — a widget surface
composed from contributors, exactly as an area's overview is, one scope
wider. It is an ordinary attribute route on `AreaBundle`'s controllers, so an
installation that already imports them has it.

**THE ROOT ROUTE CHANGES, and here is where its two halves went.** `/` used
to be the shell's welcome screen, which reported what was installed and told
a new operator what to do next. Both readings kept a home:

| what it was | where it is now |
| --- | --- |
| what is installed, at what version, where it runs, its health | **`/settings/installation`** |
| what the platform gives you, and what is left to set up | **`/settings`** (Settings → Overview) |

The shell still ships `welcome.php` as a route resource; an installation that
prefers the old front door simply keeps importing it and does not import
AreaBundle's controllers at `/`. One of the two answers `/`, never both.

**The brandmark.** `shell.home_route` defaults to `organization_dashboard`.
An installation with its own front door sets its own route name, as before.

**What a module contributes.** A new seam beside the area's, opted into
deliberately:

```php
// src/Org/PatrolOrgWidgets.php
final class PatrolOrgWidgets implements OrgOverviewContributorInterface
{
    public function moduleSlug(): string { return 'patrols'; }
    public function group(): WidgetGroup { /* your headed section */ }
    public function widgets(): array { /* your cells */ }
    public function partialPattern(): string { return '@Patrol/org/_w_%s.html.twig'; }
    public function figures(Scope $scope, \DateTimeImmutable $now): array { /* your NowTiles */ }
    public function context(Scope $scope, \DateTimeImmutable $now): array { /* what they read */ }
}
```

```php
$services->set('patrol.org_widgets', PatrolOrgWidgets::class)
    ->tag('uhifadhi.overview.org_widget_provider');
```

**Why it is a second interface rather than a wider first one.** The area
contract is answered against an area ENTITY by every installed module, and
widening its signature would break all of them for a screen most have no
org-level reading for. A module with nothing to say across areas says
nothing, and loses no cells on the area page.

**The figures strip is the MODULES', four to a row.** A contributor's
`figures()` tiles fill it in their declared priority; the organization's own
"Areas" tile is FILLER — it takes a slot no module wanted and never displaces
one, because a strip that led with the host lost a module's figure off the
end the moment four modules published. Two consequences for a module author:

- your figure is never pushed out by the host's, only by another module's
  lower `priority`;
- **a fifth figure does not shrink the row — it waits in the library.** The
  strip stays four, and the tiles beyond it remain in the catalogue for
  somebody to compose onto the dashboard as optional cells. A row that grew
  to six would stop being the four-to-a-row every figure row in the product
  keeps.

An empty slot says "nothing measured · no module publishes this". It cannot
name the module that would have filled it: the host knows no module.

**EVERY FIGURE IS THE PER-AREA READING ONE SCOPE WIDER — never a second
aggregate.** The `Scope` is handed in for exactly that: answer
`forScope($scope)` and let the organization's answer BE the areas' answers.
The core holds itself to this (`PresenceService::forScope()`,
`AreaOverview::attentionForScope()`) and asserts it —
`OrgScopeIsTheSumOfAreasTest` proves the wide reading is the narrow ones
position for position, not two numbers that happen to match. A module that
grew a second aggregate would have two answers to one question and no way to
say which was right.

**A preset may name a cell you have not installed.** The five compositions
the dashboard ships name `watches`, `patrols`, `incidents`, `goals` and
`files`; the catalogue composes each design down to the cells this
installation actually has, so the same five get richer as it grows. Note the
distinction if you ship presets of your own: the DECLARATION is the design,
the CATALOGUE is this installation's composition of it, and a surface still
refuses a preset naming a cell it ships nothing for.

## A live position carries its own area's ping interval

**What changed.** `LivePosition::$pingIntervalMinutes` (new, optional, last)
and `LivePresence::isStale()` reads it in preference to the set's.
`LivePositionsInterface` gains `forScope(Scope, \DateTimeImmutable)`.

**Why.** A reading across areas holds positions expected at different rates.
One interval for all of them calls a thirty-minute area's rangers stale
beside a five-minute area's on the same silence — and then the
organization's stale count is not the areas' stale counts added up, which is
the property the whole widened-reading rule depends on.

**What to change in a module.** Nothing: only the core implements that
interface, consumers call `liveIn()` as before, and a per-area reading still
states its interval once on the set.

## Live positions move over Mercure

**What changed.** The core requires `symfony/mercure` and
`symfony/mercure-bundle`; the area bundle injects `mercure.hub.default` and
`Symfony\Component\Mercure\Authorization` straight, so **every kernel that
registers `AreaBundle` registers `MercureBundle`** and configures one hub named
`default`. After every handset write, `AreaBundle\Service\PresencePublisher`
publishes one private update to `area/{areaUuid}/presence`; the area overview
and the organization dashboard set the `mercureAuthorization` cookie for the
areas the viewer holds `areas.read` on and hand their plates an
`AtlasBundle\Model\LiveStream`; the atlas plate holds one credentialed
`EventSource` open and moves the marks.

**What an installation configures.** The documented minimum, in
`config/packages/mercure.yaml`:

```yaml
mercure:
    hubs:
        default:
            url: '%env(MERCURE_URL)%'
            public_url: '%env(MERCURE_PUBLIC_URL)%'
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
```

and three environment variables: `MERCURE_URL` (the hub's publish endpoint as
the application reaches it), `MERCURE_PUBLIC_URL` (the hub as the browser
reaches it, same origin as the pages) and `MERCURE_JWT_SECRET` (the key the
hub verifies subscriber and publisher tokens with). The area bundle also
reads the `logger` service; an installation has monolog's.

**A deployment without a hub keeps working.** With `MERCURE_URL` and
`MERCURE_PUBLIC_URL` empty the publisher publishes nothing, no page sets a
cookie, no plate opens a stream, and every plate reads as the page drew it.
The bundle must still be registered and the hub configured, because the
services are injected without a guard.

**Constructors that changed.** `AreaBundle\Service\CheckInService` takes a
`PresencePublisher` last; `AreaBundle\Controller\AreaController` and
`OrgDashboardController` take a `PresenceStreamService` last;
`AreaMapService::overview()` and `::organization()` take an optional
`LiveStream` last. A test kernel of a module that boots `AreaBundle` registers
`MercureBundle`, configures the hub with an empty address, and provides a
`logger`.

**What a module that draws live marks does.** Pass the same `LiveStream` to
its plate (`$map->liveStream($subscription->stream)`) and set
`$subscription->cookie` on its response, both from
`PresenceStreamService::forArea()`; the marks then move on its page too.

The hub must sit at the application's own origin. An installation whose `.env` still carries the Mercure recipe's placeholder (`https://example.com/.well-known/mercure`) gets no stream and no cookie, and every page keeps answering; set `MERCURE_URL` and `MERCURE_PUBLIC_URL` to the hub the deployment serves, or to nothing.
## Ping every is set on the area, and read through `PingInterval`

**What changed.** The area's edit screen (`POST /areas/{uuid}/edit`, the
settings section's one write) takes `pingEvery` and `pingEveryUnit`
(`minutes`, `hours` or `days`) and saves them as
`AreaOfInterest::$pingIntervalMinutes`. Blank is "not set" and reads as 30
minutes; below one minute is refused with a 422 and the area is unchanged. The
settings section's record prints it. `AreaIdentity::update()` takes it as a
sixth, optional argument.

`Uhifadhi\Bundle\AreaBundle\Service\PingInterval` (service
`area.ping_interval`) answers an area's interval, the default and the floor
included. `DutyRosterService`, `PresenceService` and
`AreaConfigurationSections` take it in their constructors, before their tagged
iterables.

**What to change in a module.** A module that counts from the interval — a
late threshold of "twice the interval", say — reads it from the area:

```php
$minutes = $pingInterval->for($area); // Uhifadhi\Bundle\AreaBundle\Service\PingInterval
```

and keeps no copy of its own. An installation that builds any of those
services by hand passes `service('area.ping_interval')`.

## `/favicon.ico` is answered, where the application asks for it

**What changed.** The shell ships a fourth route resource, and it serves the
same file the document head has always linked:

```yaml
# config/routes/shell.yaml (your application)
shell_favicon:
    resource: '@ShellBundle/config/routes/favicon.php'
```

**Why.** Every page declares `<link rel="icon">` and every browser asks for
`/favicon.ico` anyway — before the first page, on a redirect, on an error
page, and whenever it has nothing cached. Nothing answered, so the request
became an exception: on a staging installation the ONLY error group telemetry
had ever captured was `NotFoundHttpException … /favicon.ico`. A log whose
single entry is noise is a log nobody reads, and the next real error goes
into it unseen.

**What it serves.** The bundle's own `public/favicon.svg`, with
`image/svg+xml` and a week's public cache — the same bytes the head links, so
the tab icon cannot drift from the brandmark and there is one file to replace
when an installation wants its own. It is not a redirect to the digested
asset: a redirect is a second round trip for exactly the browsers that have
nothing cached.

**An installation that serves its own icon** from its web server or a CDN in
front of it leaves the import out, and the address is its own — which is why
this is a resource of its own rather than a line in the welcome page's.

## The settings section, and the route an application imports for it

**What changed.** The core ships a Settings section — `/settings`, with
**Installation**, **Modules** and **Organization** as its tabs — and it is
reachable only where the application asks for it, exactly like the welcome
page and the configure page:

```yaml
# config/routes/shell.yaml (your application)
shell_settings:
    resource: '@ShellBundle/config/routes/settings.php'
```

Without that line there is no section and no row in the sidebar for one; the
navigation source generates the address and yields nothing when it cannot.
The constant is `ShellBundle::SETTINGS_ROUTES`, for the reason the other two
are constants: the string is written in the recipe, in the skeleton and in
every installation.

**The sidebar's last group.** `NavGroup::SETTINGS` holds one row whose
children are the section's tabs — the shape an area and the files section
already wear. The shell derives what is open from the row marked `current`,
so nothing about this needs an installation to configure anything.

**What lives there.** The first screen says **what this installation gives
you** — the parts of the core (read from each one's own manifest, never
typed), what each installed module adds and how many areas run it, the rules
the rest follows, and the set-up checklist. Behind it: what is installed and
at what version, which areas run which modules, the installation's health
checks, and who the installation belongs to. **Two figures and two columns state their own absence on an
ordinary installation**, and that is the honest reading rather than a gap:
whether a package is BEHIND needs a release feed to compare against, and when
the installation last DEPLOYED needs whatever deployed it to have said so.
Neither is a thing a running application can read about itself.

**What a module can contribute.** Five new contracts in
`Uhifadhi\Contracts\Settings`, each tagged by hand as usual:

| Tag | Interface | What it puts on the section |
| --- | --- | --- |
| `shell.settings_figure` | `SettingsFigureSourceInterface` | a card in the figure row |
| `shell.settings_check` | `SettingsCheckSourceInterface` | a row in the health list |
| `shell.settings_decision` | `SettingsDecisionSourceInterface` | an item in the queue |
| `shell.settings_change` | `SettingsChangeSourceInterface` | a row in what changed |
| `shell.settings_step` | `SettingsStepSourceInterface` | a row in the set-up checklist |

**A step is evergreen, and the contract is shaped so it cannot be otherwise.**
`SettingsStep` carries `standing` — *where this installation is* ("4
registered", "18 of 22 posted", "1 of 4 areas running") — and no "has it
begun" flag, because the first screen of Settings is where the welcome page's
content went and a page that is true only once is a page everybody stops
opening. `done` means nothing is outstanding TODAY: an installation that adds
a fifth area is not done with zones again until that area has some, and the
row says so the same day.

Two more facts have exactly ONE answer, so they are aliases rather than
collected lists — two things claiming to know which modules run in which
areas would be a disagreement with nothing to settle it:

```php
$services->alias(ModuleMatrixSourceInterface::SERVICE, MyMatrix::class);
$services->alias(OrganizationIdentitySourceInterface::SERVICE, MyIdentity::class);
```

Both are optional. An installation where nobody answers gets a screen that
says so — the identity falls back to the wordmark the shell was configured
with, and every other field reads "not set".

**A source that throws does not take the page.** This is the one screen
somebody opens to find out whether anything is wrong, so a check source that
fails becomes a row saying it could not be run, with the reason, and every
other check still answers. Write a check that is cheap: it runs on a page
render, and anything expensive is measured on a schedule and reported here
from what was stored.

**One class cannot carry two of these tags.** PHP refuses a class that
implements two interfaces each declaring a `TAG` constant. Where one fact has
two readings — a health row and a queue item — publish it as two small
sources over one shared reading, which is what the core does for the areas
that run no module.

## `.ao-att` is the frame's now

**What changed.** The needs-attention row moved out of `area.css` into
`shell.css` and joined `Contract\LayoutContract::COMPONENTS`. Three surfaces
draw one — an area's overview, the organization dashboard and the settings
section — from three different owners' items, and a copy in a second sheet
would have been two rows that drift, whichever sheet happened to load last.

**What to change in a module.** Nothing. The markup and the three states
(`.now`, `.soon`, `.watch`) are what they were; a cell already writing them
keeps working, and now works on a page that does not load the area's sheet.
`OverviewVocabulary::HOST_CLASSES` no longer lists it, because it is no
longer the area overview's to lend.

## A mark says whose set it is

**What changed.** `VocabularyConformanceTestCase` gains
`testNoIconIsNamedWithoutSayingWhoseItIs`: no template, controller or
provider in a bundle may name an icon without its set —
`ux_icon('calendar-clock')`, `<twig:ux:icon name="plus">`, `icon: 'clock'`.

**Why.** A bare name resolves in the HOST's default set, so a module writing
one is betting that every installation happens to ship that glyph, and the
bet fails silently until the name is rendered somewhere that does not. A nav
row is rendered by the SHELL, in whatever application mounted the module —
one bare `calendar-clock` in a roster nav row took that module's whole suite
down in a fixture application with no icon directory at all.

**What to change.** Name the set: `<your-alias>:<name>` for a mark your
bundle ships in `assets/icons/<alias>/`, `shell:<name>` for one the shell
ships. The rule sits below the three that were already there — that a prefix
is one the bundle may use, and that a name under its own prefix resolves to a
file it ships.

## The scope control has a default, and the core ships it

**What changed.** `AreaBundle` ships `Shell\AreasTheViewerMayOpen`, tagged
`shell.scope_source`: the organization plus every area the viewer holds
`area.view` on, asked WITH THE AREA AS SUBJECT — the same authority
`/api/areas/mine` asks. An installation now gets the scope control on
organization-level pages with nothing wired.

**Why.** The shell draws the control only when something tags a
`ScopeSourceInterface`, and no installation did: the roster's organization
pages rendered with the row, the strip and the figures right and no control
at all. A seam whose default is "nothing" ships a feature that passes its own
suite and is missing on every real page.

**One area is not a choice.** Where the viewer may open exactly one area, only
that area is offered, and the shell's existing rule (a control of one row is
not a control) leaves the action row empty.

**A host may still replace it** by redefining the `area.scopes` service — a
host that tags a second source of its own gets both lists, which is not what
anybody wants.

## The map stylesheet is in every head — delete your link

**What changed.** `bundles/atlas/map.css` is published through the shell's
head contract (`StylesheetSourceInterface`, tag `shell.stylesheet`), beside
`chart.css` and `calendar.css`, so every page in the product carries it.

**Why.** The old rule was "a page that draws a plate links `map.css`", and it
held only while a page could know. A plate is now drawn by WIDGETS: on a
composed surface any cell may draw one, and the organization Overview
composed a map cell onto a page that linked no map sheet — `.map-plate`,
`.map-legend` and `.lay .sw` had no rules, the plate came apart, the page
returned 200 and nothing failed. The head cannot be decided by what a page
happens to compose.

**What to change in a module.** Delete the link, everywhere it appears:

```diff
 {% block stylesheets %}
     {{ parent() }}
-    <link rel="stylesheet" href="{{ asset(constant('Uhifadhi\Bundle\AtlasBundle\AtlasBundle::STYLESHEET')) }}">
     <link rel="stylesheet" href="{{ asset(constant('Uhifadhi\Roster\UhifadhiRosterBundle::STYLESHEET')) }}">
 {% endblock %}
```

**It is now a conformance failure.** `VocabularyConformanceTestCase` gains
`testNoTemplateLinksASheetTheHeadAlreadyCarries`: a template linking
`map.css`, `chart.css` or `calendar.css` fails with "the shell carries it",
because a second link is a second copy of those rules at a different point in
the load order. **roster-module, patrol-module and incident-module** each
carry such links today and will fail this rule until they are deleted — one
line per template, no other change.

Leaflet's own sheet is unaffected: the UX Map Leaflet bridge's controller
imports it on the pages that build a map, which is a script's business rather
than the head's.

## Extend the shell's org base, draw no strip

**What changed.** An organization-level screen extends
`@Shell/org_page.html.twig`, and the shell draws the page-level tab strip —
from the same `orgPages()` declaration the sidebar row is mounted from,
filtered to the routes this application actually mounted, with the screen the
viewer is on lit. The page-level strip is the shell's, exactly as the sidebar
row is: two copies of it drift, and a tab and a sidebar row then disagree
about which screens a module has.

**What a module drops.** Its own org base: the `.atabs` loop, the
`_scope_control` include, the trail, and the `orgTabs` / `scopeOptions`
variables its controllers were passing for them. They are gone, not moved —
the shell reads the declaration itself.

```diff
-{% extends '@Shell/page.html.twig' %}
-{% block shell_breadcrumbs %}uhifadhi / roster / overview{% endblock %}
-{% block shell_page_actions %}
-    {{ include('@Shell/_scope_control.html.twig', {scopes: scopeOptions, current: scope}) }}
-{% endblock %}
-{% block shell_page %}
-    <div class="atabs">{% for tab in orgTabs %}…{% endfor %}</div>
-    {% block roster_org_page %}{% endblock %}
-{% endblock %}
+{% extends '@Shell/org_page.html.twig' %}
+{% block shell_page %}{% block roster_org_page %}{% endblock %}{% endblock %}
```

**What it fills.** `shell_page` (its body), `shell_page_subtitle`, and
`shell_org_actions` where it has an action of its own — which lands beside
the scope control, with Configure still last. `shell_org_trail_tail`
overrides the trail's last segment; the trail itself names the module and the
screen and **never an area**, because an organization-level screen is the
area screen one scope wider.

**Reading the frame.** `shell_org()` returns the module's name, the current
`OrgPage`, the strip, the scopes and the current `Scope` — or null on any
page in no module's org set, where the base renders as a plain page rather
than failing.

**Two rules it brings with it.** A strip that would be one tab is not drawn
(the rule the area strip already keeps), and the tabs carry the address's own
`?area=` so the slice survives a tab change.

## The sidebar has four groups, and a module joins one by constant

**What changed.** The sidebar's groups are published in the contracts —
`Uhifadhi\Contracts\Shell\NavGroup` — and there are four of them, in this
order:

| Constant | Label | What it holds |
| --- | --- | --- |
| `NavGroup::OBSERVATORY` | Observatory | what the organization WATCHES: Areas, Performance, a module's organization-level pages |
| `NavGroup::ORGANIZATION` | Organization | what it IS AND HOLDS: Departments, Team, Files |
| `NavGroup::SYSTEM` | System | what the system RAISES TO YOU: Alerts, Telemetry |
| `NavGroup::SETTINGS` | Settings | configuration, and it comes last |

The shell refuses a label that is not one of the four, naming them in the
message. A near-miss — `Organization`, `Org`, `system` — used to grow a fifth
heading that nobody designed, in whoever's installation had that module.

**What a module does.** Name the group by constant:

```diff
-public const string SECTION = 'System';
+public const string SECTION = NavGroup::SYSTEM;
```

The string VALUES are unchanged, so a module still on a literal keeps working
exactly as before — until it misspells one, which is the point.

**Order is the shell's now.** The four headings are drawn in the contract's
order whatever position a contribution declared; `NavSection::$position` still
orders the ROWS inside a group, which is the question a contributing module
can answer. A module that leaned on a low position to sit its whole group
first no longer does.

**Storage: Files moves to Organization.** Files is a standing fact about the
organization, not something the system raises to you, so
`FilesNavigation::SECTION` becomes `NavGroup::ORGANIZATION` — a change in
`uhifadhi/storage-module`, in its own release. Until then Files renders under
System as it does today; nothing breaks either way.

**Settings.** The group exists now for the Settings section that follows: one
row with its tabs as children, and it may grow. A module has no reason to
file under it yet.

## A module can answer at organization level

**What changed.** A module may now contribute an ORGANIZATION-LEVEL page set
— its own screens once across every area — and the shell mounts it: a row in
Observatory after Performance, the screens as its tabs, and the scope control
in the page's action row. The module writes no sidebar item, no tab strip and
no scope control, and the host writes no module code.

**What a module does.** Implement `Uhifadhi\Contracts\Shell\OrgPagesInterface`
beside `ModuleProviderInterface` and tag the provider `shell.org_pages`:

```php
public function orgPages(): array
{
    return [
        new OrgPage('overview', 'Overview', 'roster_org_overview'),
        new OrgPage('today', 'Today', 'roster_org_today'),
    ];
}
```

Route NAMES, never paths — the application mounts them, and a screen whose
route this installation has not mounted is left out rather than drawn as a
link to a 404. A module that has no organization-level reading simply does
not implement the interface; nothing is missing.

**Every figure is the area query one scope wider.** The module's own service
takes a `Uhifadhi\Contracts\Shell\Scope` — the organization, or one area —
and the area page passes one area where the org page passes the organization.
A module that grew a second aggregate for this would have two numbers for one
question and no way to say which was right.

**What a host does.** Fill the scope control by tagging a
`Uhifadhi\Contracts\Shell\ScopeSourceInterface` with `shell.scope_source`.
The shell holds no areas and no voters, so the list is yours and is already
narrowed to what the account may open: somebody scoped to one area gets that
area and no control at all, and the page is the same page. The current slice
is resolved off `?area=<uuid>` (`Shell\Service\Scopes::PARAMETER`).

**`.ov-ctl` is unscoped now.** It was `.pgact .ov-ctl` in the shell's sheet,
which made it a control only the page action row could draw. A stylesheet
that restated it for its own surface should drop that copy.

## A chart series states a category, not a colour

**What changed.** `AtlasBundle\Model\ChartSeries` takes `cat` — its position
in the palette, 1 to 18 — and `ChartBuilder` writes `var(--cat-n)` into the
dataset instead of one of six hex colours it used to own. The new
`uhifadhi--atlas-bundle--chart-plate` controller resolves the token against the
element the chart is mounted on, at mount and again when the theme flips.

**Why.** Chart.js paints onto a canvas, and a canvas does not resolve a custom
property the way an element does: a token handed to the 2D context draws
nothing, which is why the builder shipped colours at all. They were a seventh
palette beside the one the product has — right on the night canvas, wrong on
paper, and never the same mark as the zone of the same category on the plate
beside them.

**What to change in a module.** Nothing, unless you set a series' colour:

```diff
-new ChartSeries('Patrols', $points, swatch: '#49E6B4')
+new ChartSeries('Patrols', $points, cat: 1)
```

A series that states nothing takes the next category in order, which is what
most want. `$swatch` still wins where it is set and is **deprecated, removed in
the next release** — the two-release rule, since a shipped module reading a
property that vanished is a 500 on somebody else's page.

An installation that lists Stimulus controllers by hand adds `chart-plate`
beside `map-plate`; one whose importmap is written by Flex gets it with the
recipe.

## A performance topic publishes FOUR headline figures, not five

**What changed.** `Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface::kpis()`
now returns **exactly four** `TopicKpi`s, in one row. A figure row is four to
a row everywhere in the product (ruled 2026-09-21), and a record does not get
two rows of four either: the fifth card wrapped an orphan onto a second line
on a small laptop.

The host's three topics were ported from the design, label for label and in
order:

| topic | the four | what went, and where |
| --- | --- | --- |
| Staffing | Positions · Filled · Vacant · Over threshold | **People** — a position is one post held by one person, so it was the same number and the same movement as Filled |
| Goals | Declared · Met · Off track · No figure yet | **At risk** and **Missed** folded into one card; the fragment keeps them apart ("2 at risk · 1 missed") and the Briefing still asks about them separately |
| Attention & output | Items raised · Unowned · Resolved · Records | **Measuring** and **Folded** became the first card's caption — they say how much of the organization the other figures are about, which is what a caption is for |

**What a module topic must change.** Return four. The design named the drop
for the two shipped module topics, and each is that module's own commit:

  - **incident-module** drops *Claims open*
  - **patrol-module** drops *Out right now*

A topic with less to say fills the fourth slot with a figure that states its
own absence (a null value with a caption), rather than returning three — a
short row and a quiet month look identical otherwise. A topic with more to say
FOLDS two verdicts a reader acts on the same way into one card and keeps both
in the fragment.

**One new role.** `KpiRole::ItemsResolved` — items the module closed in this
period. A module that raises items should publish it: without it a rising
count of raised items cannot be read as either a rising workload or a standing
one being worked through, and those call for opposite decisions. Until a
module publishes it the card states its own absence rather than reading zero.

## A module with two widget surfaces has one library page

**What changed.** `@Shell/widget/_library.html.twig` now takes either shape.
The single-surface call is exactly what it was — `catalog`, `builtins`,
`customPresets`, `active`, `widgets`, `partial`, `widgetContext`, `urls`,
`csrfToken`, passed flat — and renders exactly what it rendered before, with no
section wrapped around it. A module with more than one surface passes
`surfaces` instead: a list of those same bags, each optionally carrying `label`
and `intro`, and gets one `h2.zone` + `p.pgsub` section per surface.

The body moved to `@Shell/widget/_library_surface.html.twig`. Nothing includes
that by hand — the section chrome and the arming of several roots on one page
are the entry point's business — but a sheet or a test that pointed at the old
file's contents should point at the new one.

**Why.** The roster's Live plate rail is a widget surface beside the module's
Overview (ruled), and a module's widgets are configured in one place. Two
library pages for one module is two answers to "where do I change my widgets".

**What to change in a module.** Nothing, unless you have a second surface. If
you do:

```diff
-{{ include('@Shell/widget/_library.html.twig') }}
+{{ include('@Shell/widget/_library.html.twig', {surfaces: surfaces}) }}
```

and give every `[data-widget-reset]` button in the page header the surface it
resets: `data-widget-reset="roster-live-rail"`. A surface may also carry an
`anchor`, rendered as the section's `id`, so a door elsewhere in the product
lands on that surface (`…/widgets#rail`) instead of at the top of the page. A bare one is still honoured
where the page has a single library, and ignored where it would be ambiguous —
a button that does not say which surface it resets is not one anything can act
on safely.

## The sidebar decides what is open, and a page no longer can

**What changed.** `ShellBundle\Model\NavItem::$open` is DERIVED. The shell
reads where the viewer is from the row a source marked `current` and opens the
path to it and nothing else; whatever a source passed for `$open` is
overwritten. The argument is still on the constructor for this release so an
installed module that names it does not fail to construct a row, and it goes in
the next one.

Two classes come out of that derivation, and they replace the old pair:

| was | is now |
| --- | --- |
| `current` on every rung of the path | `on` on the row the viewer is on, once per sidebar |
| `.nta.cur`, the place rung's own marking | `.path` — accent ink, no ground — at every rung above the current row |

`.nta.cur` is gone from `shell.css`. A module stylesheet that drew something on
it should read `.path`, which is the same rows at every depth rather than only
at the second.

**Why.** Each source derived its own subtree, so a page's tree read differently
depending on which bundle drew it, and everything a source could open was open:
Areas stayed unfolded while you were in Files. Ruled 2026-09-20 with the
nav-states frames — only the ancestor path of the current page is open, one rung
at a time, one ground per tree.

**What to change in a module.** Drop `open:` from the rows you contribute and
mark the path with `current`, which you were doing anyway. A manual fold is the
viewer's, kept for the tab's session by the shell's own controller against the
`data-nav-key` the shell prints; nothing to do for it.

## A layer names a token, and a zone names a category

**What changed.** Nothing that draws on a map or in a legend takes a colour any
more. `AtlasBundle\Model\GeoJsonLayer::$swatch`, `AreaBundle\Overview\MapLayer::$swatch`
and `AreaBundle\Overview\PulseEvent::$swatch` now REFUSE a hex and take a token
name — `Uhifadhi\Contracts\Atlas\PlatePalette::OK`, or
`PlatePalette::category($position)` for one of a set. The plate resolves the
name in the browser where it draws, and again when the theme flips.

**Why.** A module cannot know what green is here: the palette turns over with
the theme and again on imagery, so a colour published across the seam was right
on one basemap and wrong on the next with nothing on the page to say so.

**What to change in a module.** Replace every literal you hand to a layer, a
legend row or a pulse event:

```diff
-new MapLayer(..., swatch: '#E05B41', ...)
+new MapLayer(..., swatch: PlatePalette::FAIL, ...)

-new MapLayer(..., swatch: $this->palette->hueFor($n), ...)
+new MapLayer(..., swatch: PlatePalette::category($position), ...)
```

A module with a `HousePalette`-style class of its own can delete it.

**Deprecated, and removed in the NEXT release.** `ZoneRow::$hue`,
`ZoneListRow::$hue`, `StationRow::$zoneHue` and `FilterOption::$hue` are still
there and still readable; each now resolves from its category and returns the
token the palette would have given it. Read `$cat` / `$zoneCat` instead — an
`int` 1 to 9, the thing's position in its own declared order. They go in the
release after this one, on the two-release rule: a shipped module reading a
property that vanished is a 500 on somebody else's page.

## PostGIS comes from `utafitilabs/postgis-bundle`

The spatial repository base and the geometry DBAL types the area's entities are
mapped with now come from `utafitilabs/postgis-bundle`. Both packages register
the same DBAL type names, so the old one cannot stay alongside it:

```bash
composer remove fundistadi/postgis-bundle
composer require utafitilabs/postgis-bundle:^0.1
```

In `config/bundles.php`:

```diff
-FundiStadi\PostGISBundle\FundiStadiPostGISBundle::class => ['all' => true],
+UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle::class => ['all' => true],
```

And if the installation configures the bundle, its root key is now
`utafiti_labs_post_gis`, not `fundi_stadi_post_gis`. Nothing about the database
changes: the extension, the columns and the indexes are the same.

## A scale is removed only once it is empty, and the ladder reads highest first

Under Team › Configure › Ranks, with several scales, a rank row's arrow moves the
rank to another scale (its holders and history come along, it lands at that
scale's junior end) and the card's **Remove scale** door wakes only once no rank
in use is left on it. A scale whose ranks were all retired is retired with them
(`team_rank_scale.retired_at`, shipped migration `Version20260925120000`) and is
read nowhere; a scale that never carried a rank is deleted; the last scale cannot
be removed — switch ranks off instead. With **Uses ranks** off the ladder is drawn
read-only.

`Rank::$seniority` reads 1 as the **most senior** rank; every list — the Ranks
register, the People facet and column, the CSV, the configure ladder — reads the
highest rank first, and `RankService::addRank()` appends at the junior end. A
ladder loaded the other way round is turned over by dragging on the configure page.

## Ranked bars are the atlas's

**What changed.** `.sxbars`, `.sxbar`, `.sxdot` and `.sxmxkey` left `shell.css` and
`LayoutContract::COMPONENTS` for the atlas's `chart.css`, which every head carries, and a
ranking is drawn with `atlas_bars(RankedBars)` (`atlas_key(DotKey)` for a key alone).
`Uhifadhi\Bundle\TeamBundle\Model\SectionBar` is removed; its widths are
`RankedBars::rows()`.

**What to change in a module.** Markup that writes the rows still renders, because the names
and the rules are unchanged. Draw them through the atlas instead: build `Bar` rows and pass a
`RankedBars` to the template. A module whose `VocabularyConformanceTest` lists only the
shell's sheets under `linkedStylesheets()` adds the atlas's `chart.css` there, or the class
sweep reports `.sxbar` as shipped by nobody — **storage-module** writes the rows on its Files
overview and storage page and is in that position.

## A topic's matrix is the atlas's heat table

**What changed.** `render_matrix()` draws its table with `atlas_heatmap()` and its legend with
`atlas_heat_legend()`. `Uhifadhi\Bundle\TeamBundle\Performance\MatrixViewBuilder::build()`
returns `Uhifadhi\Bundle\AtlasBundle\Model\Heatmap\HeatTable`, and these Team classes are
removed: `MatrixView`, `MatrixViewCell`, `MatrixViewColumn`, `MatrixViewRow`, `MatrixBand`,
`CellChip`, `CellKind` (read `HeatTable`, `HeatCell`, `HeatColumn`, `HeatRow`, `HeatBand`,
`HeatChip`, `HeatCellKind`). `.heat`, `.hcell`, `.cmark`, `.legend`, `.sg`, `.dept`,
`.thtot` and `.sortmark` moved from `bundles/team/performance.css` to
`bundles/atlas/heat.css`, which every head carries.

**What to change in a module.** A module that publishes a `TopicMatrix` changes nothing. One
that linked `heat.css` by hand deletes the link: the conformance rule refuses it, as it does the
other atlas sheets.

## An area's face is the atlas's thumbnail

**What changed.** `Uhifadhi\Bundle\AreaBundle\Model\AreaThumbnail` is
`Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail`, same constructors and properties, and a face
is drawn with `atlas_thumbnail(thumbnail)` inside a positioned frame. `.ax-sat` and
`.ax-outline` moved from `bundles/area/area.css` to `bundles/atlas/map.css`.

**What to change.** Replace the class name in any `use` statement; replace a hand-written
`<img class="ax-sat">` and `<svg class="ax-outline">` pair with the one call.

## Every chart wears the house ticks, and none has a tooltip

**What changed.** `ChartBuilder` states every chart's tick labels (the mono face at 6.7px in
`--fog`), the value axis's grid (the fog at 22%, 0.6px, no tick marks) and the index axis's line
(the fog at 55%, 0.8px) as tokens the chart plate resolves, and switches Chart.js tooltips off
(`plugins.tooltip.enabled: false`). `AtlasChart::$barRadius` rounds a chart's bars; unstated,
they stay square.

**What to change in a module.** Nothing for a module that states an `AtlasChart`. A figure that
was only readable on hover is now read nowhere: write it on the bar (`ChartFigures`) or in the
caption.

## A station's point is picked by the atlas plate

**What changed.** The stations section's plate picks a point through the atlas's pick mode:
`AreaPlateService::picker()` states `AtlasMap::pickPoint(new PointPick('station-add', …))`, the
plate draws the pin, the caption and the pin's key row, and the "Pick on the map" and "Move on
the map" controls wear `data-atlas-pick`. `.pickcap` moved from `bundles/area/area.css` to
`bundles/atlas/map.css`.

**The `station-point` controller is deprecated** and does nothing; it is removed in the next
release. Its file and its `assets/package.json` entry stay for this one, so an installation whose
`assets/controllers.json` enables it still renders. Delete these lines from that file:

```json
"station-point": {
    "enabled": true,
    "fetch": "eager"
}
```

(under `"@uhifadhi/area-bundle"`), then `cache:clear` and `asset-map:compile`.

**What to change in a module.** A module that picked a point by listening to
`atlas:map:connect` states `AtlasMap::pickPoint(PointPick)` instead and puts
`data-atlas-pick="<form id>"`, `data-atlas-pick-mode` and `data-atlas-pick-name` on the controls
that arm the plate for another form. Documented in the atlas's docs/components.md, "Picking a
point".
