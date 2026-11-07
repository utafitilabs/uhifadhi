# The Permissions readers — what each one returns

The four Permissions pages (the hub, Positions, Analysis, Check) read through
five readers, all built and tested (`tests/Integration/Permission/*ReaderTest`,
`PermissionCheckServiceTest`). This file fixes what each one returns, as plain
PHP arrays, so the pages can be built from it. The shapes follow the settled design's data
model (`_data.py`'s `standing`, `drift`, `historyByMonth`, `voter()`,
`analysis()`; `model.js`'s `answerPair`, `answerPower`, `analysis`, `peopleIn`,
`powerTable`). Where the core and the design differ, the core wins and the
difference is named.

## Contents

- [What is stored, and where](#what-is-stored-and-where)
- [Conventions every reader keeps](#conventions-every-reader-keeps)
- [PermissionAnalysisReader](#permissionanalysisreader)
- [PermissionFindingsReader](#permissionfindingsreader)
- [PositionsReportReader](#positionsreportreader)
- [PermissionCheckService](#permissioncheckservice)
- [PermissionOverviewReader](#permissionoverviewreader)
- [Tests the readers need](#tests-the-readers-need)
- [Known differences from the design](#known-differences-from-the-design)

## What is stored, and where

A run (`Entity\PermissionRun`, `team_permission_run`) is written by
`Permissions\PermissionRunService` (`team.permissions.runs`). The pages read
**the latest finished run**: `PermissionRunRepository::findOneLatestFinished()`.

| Table | One row is | Read with |
|---|---|---|
| `team_permission_run` | a run: trigger, status, requested/started/finished, `places` (JSON list of `{kind, id, name}`, the whole organization first, then areas by name), `pairs` (JSON list of the pairs asked), `counts` (JSON: `people`, `staff`, `superAdmins`, `admins`, `questions`, `shapes`, `answers`, `findings`, `drift`, `ms`) | `PermissionRunRepository`: `findOneLatestFinished`, `findOnePreviousFinished`, `findOnePending`, `findOneRunning` |
| `team_permission_answer` | **pair**: one position × one place, the active Staff holders who pass every GrantVoter question there (`people` = sorted ids, `peopleCount`). Only non-empty cells are stored. **power**: one actor (a position, spoken for by one Staff holder; or a tier, `tier` = `super_admin` / `admin`) × one target kind (`target` = a `PowerTarget` value), `verdict` = a `CellAnswer` value (`allowed` / `refused` / `no-target`), `refusedBy` = the question that refused, `people` = who the answer speaks for when allowed, else empty | `PermissionAnswerRepository`: `findByRunAndQuestion(run, QuestionKindEnum, key)`, `findOneCell(run, pair, PlaceKindEnum, placeId, positionId)`, `findByRunAndPlace`, `findQuestionKeysByRun` |
| `team_permission_finding` | one entry on one of the seven lists (`FindingListEnum`); a self-change in its own columns `change_kind`, `what`, `before_value`, `after_value`, `changed_at` | `PermissionFindingRepository`: `findByRunAndList`, `countByRunAndList` |
| `team_permission_drift` | one answer that changed since the previous run with no history line touching it | `PermissionDriftRepository`: `findByRun`, `countUnreviewedByRun`; `PermissionDrift::review(at, byName)` |

The tiers are never cells: the run counts them (`superAdmins`, `admins`) and
the page says *Super Admin 1 and Admin 4 can, everywhere* from those counts.

The fixture trait `tests/Integration/Permission/BuildsAnOrganization.php`
builds the invented organization the run tests use: the §7 cast of
`66-permissions-semantics.md`, plus one of each edge case. The readers' tests
should use it too.

## Conventions every reader keeps

- **A place key** is `'organization'` or `'area-<id>'` (the area's integer id,
  as stored). Every place carries `['key' => …, 'kind' => 'organization'|'area', 'id' => ?int, 'name' => string]`
  and comes from `$run->getPlaces()`, in that order.
- **A pair label** is the concern's label, a middle dot, then the verb:
  `Surveys · record` (`model.js`'s `pairLabel`). Read labels from
  `Access\ConcernCatalogue::concern($key)->label()`.
- **Times** are `\DateTimeImmutable`; the template formats them (`6 Nov · 14:02`).
  The one exception is a sentence a reader writes itself (Check's exception
  line, the hub's vacant summary), which reads the date as `3 Oct 2026`.
- **`Permissions\PlaceKey`** makes and parses place keys (`of`, `parse`, `places`).
- **No run yet** is not an error: every reader returns its shape with empty
  lists, zero counts and `asOf => null`. The page then says *not computed yet*.
- Readers are services with explicit DI (`team.permissions.<name>`), private,
  aliased in `tests/Integration/TestKernel.php` for the suites.

## PermissionAnalysisReader

`team.permissions.analysis`. Analysis reads **stored answers for one question**.

### `forPair(string $pair): array`

```php
[
    'pair' => 'surveys.record',
    'label' => 'Surveys · record',
    'filed' => true,                  // false when the latest run did not ask it (a module installed since)
    'asOf' => ?\DateTimeImmutable,    // latest run's startedAt
    'places' => list<array{key: string, kind: string, id: ?int, name: string}>,
    'rows' => list<array{
        positionId: int,
        position: string,             // the name as it reads now
        holders: int,                 // active Staff holders of the position
        counts: array<string, int>,   // place key → people; 0 where no cell is stored
        anywhere: int,                // max(counts)
        people: int,                  // the distinct people behind the row
    }>,                               // most people first (people DESC, then name ASC); every non-retired position holding the pair is a row, and every position a stored answer names
    'totals' => array<string, int>,   // place key → people in the column
    'tiers' => list<array{key: 'super_admin'|'admin', label: string, count: int}>,  // from the run's counts
    'departments' => list<array{id: int, name: string}>,  // every department, by name: the department chip's options
]
```

A person counts in every column their placement covers; one
organization-wide holder appears in every cell of the row (the page says so
once, under the grid).

### `peopleIn(string $pair, string $placeKey, int $positionId): array`

The people behind one cell, read from the stored ids, never by asking again:

```php
list<array{id: int, uuid: string, name: string}>   // by name
```

### `peopleByCell(string $pair): array`

The people behind every cell of one pair, in one read of the stored ids:

```php
array<string, array<int, list<array{id: int, uuid: string, name: string, departments: list<int>}>>>   // place key → position id → people by name; an empty cell is absent; departments: their own, then those they support
```

### `questions(): array`

What the question dropdown offers, with the answers the latest run filed for each:

```php
[
    'asOf' => ?\DateTimeImmutable,
    'concerns' => list<array{key: string, label: string, group: string,          // group: the declaring package
        verbs: list<array{pair: string, verb: string, label: string, answers: int, positions: int}>}>,  // Verb::cases() order; positions: non-retired positions storing the pair
    'powers' => list<array{key: string, label: string, group: string, groupLabel: string, answers: int,
        allowed: int, target: string}>,   // allowed: actors the run allowed over the first target kind; target: that kind's label
]
```

The freshness line Analysis prints is `PermissionFindingsReader::freshness()`.

### `forPower(string $powerKey): array`

`model.js`'s `powerTable`:

```php
[
    'power' => array{key: string, label: string, group: string, groupLabel: string},  // PowerGroup value and label()
    'targets' => list<array{key: string, label: string}>,   // PowerTarget value and label(), in the power's order
    'rows' => list<array{
        kind: 'tier'|'position',
        name: string,                 // 'Super Admin' / 'Admin' / the position's name
        positionId: ?int,
        tier: ?string,
        cells: array<string, 'allowed'|'refused'|'no-target'>,   // target key → verdict
        refusedBy: array<string, ?string>,                        // target key → the question that refused
    }>,                               // the tiers first, then positions in PositionRepository::findAllOrdered() order
    'alarm' => bool,                  // the power is in the gaining-power group and any cell but a Super Admin's is allowed
    'asOf' => ?\DateTimeImmutable,
]
```

### `records(): array`

The records a row on the Analysis page opens, read live: every position's and
every person's uuid by id, and the first active person of each tier by name
(the person a tier's row in a power's table checks).

```php
['positions' => array<int, string>, 'people' => array<int, string>, 'tiers' => array<string, int>]
```

## PermissionFindingsReader

`team.permissions.findings_reader`.

### `standing(): array`

The seven lists, in `FindingListEnum` order, as `_data.py`'s `standing` plus
the seventh, *Self-changes* (informational: `alarm` false, never in *needs attention*):

```php
[
    'asOf' => ?\DateTimeImmutable,
    'lists' => list<array{
        id: 'reach-nothing'|'not-in-force'|'not-honoured'|'gaining'|'vacant'|'exceptions'|'self-changes',
        label: string,                // FindingListEnum::label()
        alarm: bool,                  // FindingListEnum::isAlarm()
        count: int,
        entries: list<array<string, mixed>>,   // below, per list
    }>,
]
```

Entries, by list (each from `PermissionFinding`'s columns):

| list | entry |
|---|---|
| `reach-nothing` | `['personId' => int, 'name' => string, 'position' => ?string, 'why' => string]`. `why` is `detail`: `no position`, `no placement`, `no department`, `no ground`, joined with ` · ` |
| `not-in-force` | `['positionId' => int, 'position' => string, 'pair' => string, 'pairLabel' => string, 'why' => string]` |
| `not-honoured` | `['positionId' => int, 'position' => string, 'pair' => string, 'pairLabel' => string]` |
| `gaining` | `['subject' => string, 'positionId' => ?int, 'power' => string, 'detail' => string]`. `subject` is the position's name or the tier label; `power` is the power key (stored in `pair`); `detail` is `"<power label> — <target label>"` |
| `vacant` | `['positionId' => int, 'position' => string, 'since' => ?\DateTimeImmutable]` (`at`) |
| `exceptions` | `['positionId' => int, 'position' => string, 'pair' => string, 'pairLabel' => string, 'reason' => string, 'by' => string, 'at' => \DateTimeImmutable]` |
| `self-changes` | `['personId' => int, 'name' => string, 'kind' => 'tier-changed'\|'seated'\|'unseated'\|'rank-changed', 'what' => 'tier'\|'position'\|'rank', 'before' => ?string, 'after' => ?string, 'at' => \DateTimeImmutable]`. The history lines whose actor is their subject, recorded between the previous finished run's start (or 30 days before) and this run's start. The finding stores each in its own column: `change_kind`, `what`, `before_value`, `after_value`, `changed_at` (`pair`, `detail` and `at` stay empty) |

`gaining` files only actors who are not the Super Admin tier: under the
7 Nov ruling a Super Admin's yes is expected.

### `freshness(): array`

The line *as of 6 Nov 14:02 · next run tonight 02:00 · Recompute now*:

```php
[
    'asOf' => ?\DateTimeImmutable,          // latest finished run's startedAt
    'previousRun' => ?\DateTimeImmutable,   // the run before it
    'nextRun' => \DateTimeImmutable,        // (new CronExpression('%team.permissions.schedule%'))->getNextRunDate(now, 0, false, '%team.permissions.timezone%' ?? date_default_timezone_get())
    'pending' => bool,                      // a request is waiting (findOnePending)
    'running' => bool,                      // findOneRunning
    'lastFailure' => ?string,               // the newest failed run's failure, if it is newer than asOf
    'questions' => int, 'ms' => int,        // the latest run's counts, for the source line
]
```

### `drift(): array`

`_data.py`'s `drift_out`: folded to one line per subject × question, with
the places that moved under it:

```php
[
    'unreviewed' => int,
    'lines' => list<array{
        subject: string,             // position name, tier label, or person
        positionId: ?int,
        questionKind: 'pair'|'power',
        question: string,            // pair or power key
        label: string,               // pair label, or the power's label
        places: list<array{driftId: int, place: string, was: int, now: int, reviewed: bool}>,  // for a power, `place` is the target's label and was/now are 1 (allowed) / 0
        found: \DateTimeImmutable,         // this run's startedAt
        previousRun: ?\DateTimeImmutable,  // the previous run's startedAt
    }>,
]
```

The design's `cause` sentence is not stored. When there is no line
(department changed outside the services, a database edit, a voter that
changed in a deploy) the page says *nothing in the history explains it*.

### `markReviewed(User $by, ?array $driftIds = null): int`

Marks the latest run's unreviewed drift, all of it or the ids given,
`review(now, name of $by)`, flushes and returns how many changed. Only a
Super Admin reaches the button (the controller's job).

### `worthRunning(): array`

The Check page's Worth running: every active Staff person once, the flagged
first, derived from the latest run's findings (never hand-picked), in this
order; empty before a run:

```php
list<array{
    person: array{id: int, uuid: string, name: string},
    question: array{kind: 'pair'|'power', key: string, label: string},
    place: array{key: string, name: string},   // for a power: the power's first target kind and its label
    why: 'reaches nothing'|'exception not in force'|'stored, not honoured'|'gains power'|'exception in force'|'nothing flagged',
    kind: 'reaches-nothing'|'not-in-force'|'stored-not-honoured'|'gains-power'|'in-force'|'nothing-flagged',
    group: 'attention'|'confirmation'|'none',  // confirmation for an exception in force, none for nothing flagged
    flagged: bool,                             // false only for nothing flagged
}>
```

A person a finding has named keeps that first row; a tier or a deactivated
account gives none.

| list | person | question | place |
|---|---|---|---|
| reach nothing | the person | the first pair their position holds, in catalogue order, else the first a position may hold | their first area, else the whole organization |
| not in force | the position's first active Staff holder | the pair | the holder's first area, else the whole organization |
| not honoured | the same holder | the pair | the whole organization |
| gaining (not a Super Admin) | the position's holder, or the first active person of the tier | the power | its first target kind |
| exceptions in force | the position's holder | the pair | the holder's first area, else the whole organization |
| nothing flagged | every other active Staff person, by name | their first sensitive pair, else their first pair, else the first a position may hold | their first area, else the whole organization |

A finding whose position nobody holds any longer gives no row.

## PositionsReportReader

`team.permissions.positions_report`. Reads the positions table live, never a
voter and never the run.

### `report(): array`

```php
[
    'positions' => list<array{
        id: int, uuid: string, name: string,
        holders: int,                    // active holders
        seatCount: ?int,
        allowedKinds: list<'organization'|'area'>,
        where: 'organization-wide'|'in named areas'|'organization-wide or in named areas',
        vacantSince: ?\DateTimeImmutable,
        locked: bool,
        retired: bool,
        pairs: list<string>,             // in the catalogue's order (ConcernCatalogue::pairs())
        groups: list<array{
            group: string,               // the concern's declarer (ConcernCatalogue::declarerOf), in grouped() order
            concerns: list<array{key: string, label: string, sensitive: bool, lifts: ?string, verbs: list<string>}>,  // verbs in Verb::cases() order
            line: string,                // 'Surveys — read, record · Stations — read'
        }>,
        exceptions: list<array{pair: string, lifts: string, inForce: bool, reason: ?string, by: ?string, at: ?\DateTimeImmutable}>,  // every stored pair that lifts a rule; inForce from GrantJustificationRepository::findOneCurrent
        notHonoured: list<string>,       // stored pairs ConcernCatalogue::isTierOnly says yes to
        marks: array{locked: bool, vacant: bool, exceptionNotInForce: bool, notHonoured: bool},
    }>,
    'tiers' => list<array{key: 'super_admin'|'admin', label: string, people: list<array{id: int, uuid: string, name: string, placed: string}>}>,   // placed: 'the whole organization', the areas joined by ' + ', or 'everywhere, by tier'
    'tierOnlyPairs' => list<string>,     // every pair isTierOnly says yes to
    'groups' => list<string>,            // the group headings, in order
    'totals' => array{all: int, vacant: int, exception: int, locked: int, honoured: int, organization: int, area: int},
]
```

`totals` are the filter pills of `positions.html` (`ONLY`):

- `vacant`: no holders, or `vacantSince` set;
- `exception`: any stored pair that lifts a rule;
- `honoured`: the *Not honoured* pill, any tier-only pair stored;
- `organization` / `area`: the kind is allowed.

A retired position is listed (`retired` true) and counted in no pill.

## PermissionCheckService

`team.permissions.check`. Check is **live**, for one person, and the walk is
the voter's own record: never re-implement a rule here.

`check(User $person, string $question, ?string $where)` takes either: a power
key goes to `checkPower()` (`$where` the target kind, null for the power's
first), anything else to `checkPair()`.

### `checkPair(User $person, string $pair, ?string $placeKey): array`

`$placeKey` is `'organization'`, `'area-<id>'`, or null for *where they are
placed*. Null means their first area, or the whole organization for an
organization-wide placement, or no area when they are placed nowhere.

1. Resolve the subject (`WholeOrganization` / the area entity / null).
2. `$verdict = $checker->isGrantedForUser($person, $pair, $subject, $decision = new AccessDecision())`
   (`security.authorization_checker`).
3. `$walk = $voter->decide($person, Grant::parse($pair), $subject)`
   (`team.access.voter`, `GrantVoter::decide()` → `Permissions\GrantDecision`).
4. If `$walk->isAllowed() !== $verdict`, another voter decided. Append a line
   `q => 'rule'` saying so, with the denying votes' reasons from `$decision->votes`.
   `tests/Integration/Security/GrantDecisionTest` proves this never happens
   with the core's voters alone.

Returns:

```php
[
    'allowed' => bool,                    // $verdict
    'question' => array{kind: 'pair', key: string, label: string},
    'place' => array{key: string, name: string}|null,
    'lines' => list<array{
        q: 'tier'|'tier-only'|'position'|'exception'|'placement'|'place'|'department'|'rule',
        status: 'ok'|'no'|'skip',
        text: string,
        door: array{kind: 'person'|'position'|'exception'|'placement'|'department', id: ?int, uuid: ?string, name: string}|null,
    }>,
    'coverage' => array{asOf: ?\DateTimeImmutable, total: int, byPosition: list<array{position: string, count: int}>}|null,  // the stored cell column for this pair and place, not live
]
```

Lines come from `GrantDecision::getSteps()`, one per step. The `tier-only`
step is printed only when it refuses, as in `model.js`. Text and door by
step, from `model.js`'s `answerPair`:

| step | ok | skip | no | door |
|---|---|---|---|---|
| tier | `Super Admin — above the matrix; nothing else is asked` | `Staff — the tiers alone are not asked` | — | person |
| tier-only | (not printed) | — | `only Admins and Super Admins hold <pair>; no position does` | — |
| position | `<position> — holds <pair>` | — | `holds no position` / `<position> — does not hold <pair>` | position (person when none) |
| exception | `in force — <by>, <at>: “<reason>”` (getJustification) | `not a permission that lifts a rule` | `<pair> lifts <getLifts()>, and no written reason is in force` | exception (the position) |
| placement | `<ground> · <department> · supports …` (PermissionChangeLog::placementOf) | — | `their placement reaches nothing — no department` / `— no ground` | placement (the person) |
| place | `the whole organization — placed across it` / `<area> — placed there` / `— placed across the organization` | — | `the whole organization — their placement covers <ground> only` / `<area> — their placement does not cover it (placed at <ground> only)` | placement on no |
| department | `<getServedBy()> runs <module>` | `the resource belongs to no module` / `no department runs <module>, so none is asked` (getModule() null vs getRunning() empty) | `<module> is run by <getRunning() names>, and they serve <their departments>` | department (servedBy on ok, their own on no) |

### `checkPower(User $person, string $powerKey, string $targetKind): array`

Same return shape, with `question.kind = 'power'`, a `verdict`
(`allowed` / `refused` / `no-target`) and a `target` entry
`array{kind: string, label: string, name: ?string}` in place of `place`;
`coverage` is the stored power cells allowed for that target kind. The tier
line for a tier reads *Super Admin — above the matrix; the pairs are not
asked, the power's own rule is*. The walk:

1. The tier line, as for a pair.
2. For each of the power's questions, in order:
   - **a declared pair** (`ConcernCatalogue::has(Grant::tryParse($q))`):
     the GrantVoter's walk for that pair, through `decide()`, with the
     subject `PowerCatalogue::sourceOf($power)->subject($power, $q, $kind, $target)`.
     Stop at a refusal.
   - **anything else** (`member.tier`, `checkin.write`, `patrol.write`, …):
     one `rule` line from `isGrantedForUser($person, $q, $subject, $decision)`,
     ok or no, its text the denying votes' reasons, or the power's label when
     none is given. This is the owner-rule voter's own line. `checkin.write` and
     `patrol.write` name no pair, so their walk is the tier line and then this line.
3. The target is found as the ledger finds it. `PermissionEvaluator::target()`
   is private today: make it public (`targetFor(User $actor, PowerTarget $kind): array{bool, User|Position|null}`)
   rather than copying it. A kind with no real target in the installation
   answers `no-target`, with one line saying so.

### The lens: `atPlace(User $person, string $question, string $where): array`

What the voter reads of one person at one place (`model.js`'s
`personAtPlace()`), and every pair of the catalogue asked there, one
`GrantVoter::decide()` walk each. `$where` is a place key for a pair; a power
is asked of the person's own place (`ownPlace()`), and `placeIsOwn` says so.

```php
[
    'person' => string, 'place' => array{key: string, name: string}, 'placeIsOwn' => bool,
    'tierHoldsAll' => bool,           // an Admin or Super Admin: no facts asked, no table
    'tierLabel' => string,
    'cover' => array{covers: bool, how: 'organization'|'areas'|'nothing'|'not', areas: list<string>},
    'department' => array{module: ?string, served: list<string>, running: list<string>, hit: ?string}|null,   // null for a power
    'exception' => array{state: 'in-force'|'not-in-force'|'none', pair: ?string, by: ?string, at: ?\DateTimeImmutable, reason: ?string, appliesHere: bool},
    'everything' => list<array{pair: string, concern: string, asked: bool, allowed: bool, step: string, line: string, held: bool, group: string}>,
    'catalogueCount' => int, 'allowedHere' => int,
]
```

`step` and `line` are the deciding line of the walk: the refusal, else the last
step that passed. `group` is one of `EVERYTHING_GROUPS` (`allowed`, `exception`,
`sensitive`, `placement`, `place`, `department`, `tier-only`, `not-held`) and
the list is in that order, catalogue order within. The position's exception is
the asked pair when it lifts a rule, else the first held pair with a written
reason in force, else the first held pair that lifts a rule.

`aboutPerson(User)` (tier, position, placement, department, supports, the
exception), `aboutQuestion(string $question, ?string $target)` (a pair's
concern: description, verbs, scopes, module, lifts, tier-only, positions and
Staff people holding it; or a power's group, label, the pairs it asks first and
its target), `people()` (the active people by tier) and `places()` (the whole
organization, then the areas by name) fill the rest of the page.

## PermissionOverviewReader

`team.permissions.overview`. The hub reads a handful of numbers and nothing
else, as the hub of `index.html`:

```php
[
    'kpis' => [
        'positions' => int,          // non-retired positions
        'vacant' => int,             // the `vacant` finding count
        'locked' => int,
        'people' => int,             // active
        'staff' => int,
        'above' => int,              // active Admins + Super Admins
        'exceptionsInForce' => int,
        'notInForce' => int,
        'reachNothing' => int,
        'notHonoured' => int,
        'needsAttention' => int,     // reachNothing + notHonoured; never self-changes
        'questionsFiled' => int,     // count(run->getPairs())
        'driftUnreviewed' => int,
    ],
    'concernsByPositions' => list<array{key: string, label: string, positions: int, share: int}>,    // positions holding any pair of the concern; share = % of positions; most first, then label
    'positionsByPairs' => list<array{position: string, held: int, problem: int, kinds: list<'not in force'|'not honoured'>}>,  // most pairs first, then name
    'whoCanActByPlace' => list<array{key: string, name: string, people: int}>,   // from placements, live: the whole organization = active people placed across it; an area = those + the people placed there
    'changesByMonth' => list<array{key: string, month: string, changes: int}>,   // the last six months ending this month, from PermissionChangeRepository::countByMonthSince(first day of the month five months back); 'Jun' … 'Nov', 0 where none
    'review' => list<array{id: string, label: string, alarm: bool, count: int, summary: string}>,  // the seven lists, one line each, in index.html's words
    'history' => list<ChangeLine>,   // every history line, newest first, any kind (a RankChanged line reads as any other); the page pages them
    'freshness' => array,            // PermissionFindingsReader::freshness()
]
```

KPI counts that are findings come from `countByRunAndList` on the latest
run. The rest (positions, locked, people, tiers) are live.

## Tests the readers need

Integration, against `BuildsAnOrganization` and a run made with
`PermissionRunService::requestRun()` + `handle()`:

- **§7 the ranger.** Analysis for `surveys.record`: the
  *Patrol, security and inspection* row has 3 in Kilimani and 1 in Sinde Flats,
  0 elsewhere. Check at Mlima Mkuu: refused, the last line is `place` / `no`.
- **§7 the head of operations.** Analysis for `locations.read`: one row,
  1 in every column. The exceptions list names the pair, the reason and
  Naomi Kileo. Revoke the exception through `PositionService::revokeException`
  and run again: the row is gone and there is no drift (a history line was written).
- **§7 the station head.** The check-in rule lives in AreaBundle's
  `CheckInWriteVoter`, which the Team kernel does not load. Test it in
  `tests/Core` (where `CheckInOwnershipTest` and `PermissionEvaluatorTest`
  already run every bundle).
- **The edge cases.** The four who reach nothing. Rhino coordinator
  held-not-in-force on Positions and the not-in-force list. People officer's
  tier-only pair stored on Positions and filed for nobody on Analysis.
  Tourism warden vacant. A deactivated holder counted nowhere.
- **Check agrees with the vote** on every fixture person × pair × place
  (`GrantDecisionTest` already proves `decide()` does).
- **Drift** is folded per subject × question; `markReviewed` marks once.
- **Freshness** gives the next run from the schedule (default `0 2 * * *`).

## Known differences from the design

- **Gaining power is *nobody except a Super Admin*** (ruled 7 Nov). The
  design's `model.js` refuses every account; the core lets a Super Admin keep
  the four gaining powers on themselves. Only another actor's yes is a
  `gaining` finding and an alarm.
- **Groups on Positions** are the concern's declarer (the package that
  declared it: Team, Ground, each module), from `ConcernCatalogue::grouped()`.
  The design's four headings (*The ground*, *The work*, *The team*,
  *The organization*) exist in no class.
- **A department module change is in the history** (`ModuleAttached`,
  `ModuleDetached`), so the design's example drift (Ecology starting to run
  Patrols) is not drift in the core when made through `DepartmentService`.

## The size, measured

`tests/Integration/Permission/PermissionRunTimingTest` seeds 363 invented
people (one Super Admin, four Admins), 40 positions, four areas and eight
departments and runs once: 35,860 questions over 326 shapes and the kernel's
22 pairs, 2,715 answers, about 2.5 s. The test fails above a minute.
