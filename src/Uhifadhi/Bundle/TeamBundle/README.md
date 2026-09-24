# TeamBundle

**Team**: who an installation's people are and how they sign in — the account,
the position that bundles permissions, the departments those positions are filed
under, and the screens a person meets before they are anybody.

One of the bundles of the uhifadhi core, `uhifadhi/uhifadhi`. It can be
installed on its own as `uhifadhi/team-bundle`.

## Contents

- [What it is](#what-it-is)
- [What it provides](#what-it-provides)
- [The firewall is the installation's](#the-firewall-is-the-installations)
- [Installation](#installation)
- [Modules point at your people](#modules-point-at-your-people)
- [Two axes: tier and position](#two-axes-tier-and-position)
- [A department is a lens: the modules it attaches](#a-department-is-a-lens-the-modules-it-attaches)
- [Signing a field client in](#signing-a-field-client-in)
- [Asking again: `GET /api/me`](#asking-again-get-apime)
- [The screens](#the-screens)
- [Configuration](#configuration)
- [License](#license)

## What it is

**Uhifadhi is one skeleton, one core and a set of modules.** The skeleton
(`uhifadhi/skeleton`) is copied once and never updated; the core
(`uhifadhi/uhifadhi`) arrives whole and is updated forever; everything a
deployment can *do* is a module.

This bundle is people, and only people. It is not the authorization runtime and
it is not the firewall — it is the account those two ask about.

## What it provides

| | What an installation gets |
|---|---|
| the provider entity | `Uhifadhi\Bundle\TeamBundle\Entity\User` — the class a `security.providers` entry names, addressed by email |
| the user checker | `team.user_checker`, public: a deactivated account is refused at the door with a reason, before the password is even weighed |
| the credential for a client | the API token a field client presents, the manager that issues and revokes it, and the endpoint that hands one out |
| the screens | sign in, forgotten password, reset, accept an invitation, the roster, one person's record, the permission matrix, departments |
| the widget surfaces | the roster and the matrix, each a catalogue the shell's widget machinery arranges |
| the sidebar row | one Team row, contributed to the shell's navigation where a shell is installed |
| the first administrator | `team:user:create` — run once on a deployment, because every screen is behind the sign-in it creates |
| what the figures were | `team:performance:snapshot` — run on the first of each period, because a closed period cannot be recomputed |

It also answers the platform's user contract. Registering the bundle prepends
`doctrine.orm.resolve_target_entities` for
`Uhifadhi\Contracts\Entity\UserInterface`, so every module that points a record
at a person has an entity to point at and the installation writes nothing.

## The firewall is the installation's

**This bundle ships no firewall and no access rule.** Which of an installation's
paths are public is a decision only that installation can make, so it is one
file in the installing project — `config/packages/security.yaml`, shipped by the
skeleton — and this bundle gives that file the three things it needs to name:
the provider entity above, `team.user_checker`, and the routes the sign-in form
posts to (`team_login`, `team_logout`).

**Throttling the form is that file's too.** Five attempts a minute blunts the
credential-stuffing surface, and it is one line on the firewall the form lives
on:

```yaml
# config/packages/security.yaml (your application)
security:
    firewalls:
        main:
            form_login: { login_path: team_login, check_path: team_login, enable_csrf: true }
            login_throttling: { max_attempts: 5 }
```

The field endpoint is throttled differently, because it has no firewall to do it
— see [Signing a field client in](#signing-a-field-client-in).

Enforcement of a granular permission is not an access rule either: a permission
answers "may this person do X *here*", which a path pattern cannot express. It
is decided against the person the current token names, per action and per area.

## Installation

The core is one package:

```bash
composer require uhifadhi/uhifadhi
```

Flex adds `Uhifadhi\Bundle\TeamBundle\TeamBundle` to `config/bundles.php`,
copies `config/packages/team.yaml` in, and mounts the routes.

### Then the tables

```bash
bin/console doctrine:migrations:migrate
```

Six tables: `team_user`, `team_position`, `team_department` — which carries a
nullable **area**, and that is what makes a department org-level or area-level —
`team_department_module` (the modules a department leads with),
`team_department_scope_change` (the trail of every scope change) and
`team_api_token`. The bundle ships the versions that create all six —
`migrations/`, namespace `Uhifadhi\Bundle\TeamBundle\Migrations`, registered
from the bundle's own `prependExtension()`, so an installation configures
nothing. It is dated after the area tables, because two of these columns
reference `area_of_interest`, and before the shell's, because two of the shell's
reference `team_user`. `doctrine:migrations:diff` stays what an installation runs
for the entities IT writes.

### Then the first administrator

Every screen is behind the sign-in an installation does not have yet, so the one
account that cannot be made through a screen is made from the console. **On a
deployment it is run once, on the server, after the migrations:**

```bash
docker exec <web> php bin/console team:user:create
# or, on a Kamal deployment
kamal app exec "php bin/console team:user:create"
```

```console
$ bin/console team:user:create

 Email address:
 > ada@example.test
 First name:
 > Ada
 Last name:
 > Mwangi
 Tier [super-admin]:
  [0] super-admin
  [1] admin
  [2] staff
 >

 Passphrase (not shown):
 >

 [OK] Created Ada Mwangi <ada@example.test> as Super Admin.
```

It asks for whatever it was not told, so anything given on the command line is
not asked for again. The **passphrase is never echoed** — nothing appears as you
type it, and it is left in no scrollback. An unanswered tier is the default one.

The account is a **Super Admin**, verified and active — somebody who can sign in
and compose everything else.

Non-interactively — in a provisioning script, or over a connection with nobody
watching — give the three names and the passphrase never reaches a shell history
or a process list either:

```bash
printf '%s' "$PASSPHRASE" | bin/console team:user:create ada@example.test Ada Mwangi
bin/console team:user:create ada@example.test Ada Mwangi --tier=staff --password="$PASSPHRASE"
```

**A tail naming all three is asked nothing but the passphrase**, which is what
keeps the piped form working: a tier question put to a pipe would be answered by
the line the passphrase was on. Under `--no-interaction` nothing is asked at all
and the passphrase is read from standard input.
`--tier=super-admin|admin|staff` names another tier, and `--password=…` passes
the passphrase inline instead of on standard input — at the cost of putting it in
the process list, so prefer the pipe.

**The core ships only commands a production build must run** — this one, the
registry's `registry:sync`, and `team:performance:snapshot`. Every other command
the platform has belongs to `uhifadhi/devkit-module`, which installs through
`require-dev` and is absent from a production build. That arrangement cannot hold
this one: a production installation is built *without* development packages, and
the first administrator is needed exactly there. There is no web setup screen —
a page that creates the first administrator is a page a stranger can reach, and
one that something has to remember to close.

## Modules point at your people

A module that keeps a record with a person on it type-hints the contract, never
this bundle's class:

```php
#[ORM\ManyToOne(targetEntity: UserInterface::class)]
private ?UserInterface $recordedBy = null;
```

An installation writes a `resolve_target_entities` line only to **disagree**,
naming its own account class — application configuration beats a bundle's
prepend, which is what makes shipping the default safe rather than presumptuous.

## Two axes: tier and position

A person has a **tier** — Super Admin, Admin or Staff — and, separately, a
**position**. The tier is a coarse standing; the position is where granular
permissions come from, and it is the one an administrator composes on the matrix
screen. A Staff member holds exactly what their position carries.

A department's scope confines a Staff member's authority to one area, or leaves
it org-wide when the department has no area. Nothing else stores that reach: it
is derived from the position's department every time it is asked.

An installation always keeps one active Super Admin. Every write that would
lower the last one is refused before anything is stored.

## A department is a lens: the modules it attaches

A department **attaches** modules from the registry's catalogue, and that is the
whole of what a department does to a page. The attached modules lead its own
dashboard, one card each, and the figures on its performance tab are those
modules' KPIs. Attaching **grants nothing and hides nothing**: no permission
moves, no row becomes unreachable, and a module two departments attach is one
module — the same rows, listed first for both, neither able to hide anything from
the other. It takes no reason and leaves no audit line for exactly that reason;
a **scope** change, which does move authority, is the thing that is audited.

Only the modules this deployment actually has are offered — a catalogue row whose
provider is no longer registered is not one, because attaching it would point at
code nobody has.

**How a module puts a figure there.** Implement
`Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface` and tag the service
`uhifadhi.department_kpi` by hand, the way a reusable bundle tags its module
provider:

```php
$services->set(SightingKpiProvider::class)->tag('uhifadhi.department_kpi');
```

A provider is asked for figures only when a department attaches the module its
`moduleSlug()` names, so a detached module's plates **leave** the page rather
than going to zero. Returning `[]` is a legitimate answer, and a `null` value is
a dashed slot: "we did not measure" and "we measured nothing" are different
facts, and a page that printed `0` for the first would be lying.

**One set of KPIs per call, and the ref says at what scope.** The department goes
out as a `Uhifadhi\Contracts\Kpi\DepartmentRef`, which carries the area an
area-level department is confined to: an `areaUuid` means that area's figures
alone, and a `null` one means the roll-up across every area. A provider answers
one figure per key either way — the surfaces draw a single row of tiles per
module — and says in the figure's caption how a roll-up was combined.

## Signing a field client in

A field client signs in once and then carries a bearer token, because somebody
working out of signal cannot re-authenticate on demand. That token is a
**credential of a person**, so it lives here beside the account — issued,
rotated and withdrawn like a password — and so does the authenticator that
reads one: `Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator`, which
an installation names in its firewall as both `custom_authenticators` and
`entry_point`. The entry point is what makes "no token at all" a **401** rather
than the 403 an access rule would give, and a client shows a person different
things for the two.

`POST /api/auth/token` is the one endpoint reachable without a token, and it is
firewall-free on purpose: a handset whose token has expired still holds it and
still sends it, and that stale header must never be what stops somebody signing
in again.

```jsonc
// the request
{ "rangerId": "sl-0142", "passcode": "…", "deviceId": "…", "deviceName": "…" }
```

`rangerId` is a service number, or an email address for staff who were never
issued one. `deviceId` and `deviceName` are optional; where the body names no
device the `X-Doria-Device` header is accepted instead, so a client need not say
the same thing twice.

```jsonc
// 200
{
  "token": "…64 hex characters…",
  "expiresAt": "2027-03-08T09:41:22Z",
  "ranger": { "id": "sl-0142", "name": "…", "role": "…" },
  "permissions": ["area.view"]
}
```

The token is handed back **once**; only its hash is stored, so a leaked database
yields nothing a handset could present. `permissions` is always sent, including
empty — an empty array is a refusal, and a *missing* field would read as
"permitted".

```jsonc
// 401, and the same document for every refusal
{ "code": "invalid_credentials", "message": "…", "retryable": false, "details": {} }
```

No such person, the wrong passcode and a deactivated account answer identically:
telling them apart would turn the one endpoint reachable without a credential
into a directory of who works here.

### Every `/api` failure is that same document

Not only this endpoint's. A 401 from the firewall, a 404 from routing, a 422
from validation and a 500 from anywhere each answer in their own way, which for
a refusal is an HTML error page — and a client parsing that gets a stack trace
where it expected a `code`. So the document is a property of the **URL space**:
anything failing under `/api` is answered as `{code, message, retryable,
details}`, with the status left exactly as whoever refused set it.

| status | `code` | `retryable` |
|---|---|---|
| 400 | `invalid_request` | false |
| 401 | `unauthorized` | false |
| 403 | `forbidden` | false |
| 404 | `not_found` | false |
| 405 | `method_not_allowed` | false |
| 406 | `not_acceptable` | false |
| 409 | `conflict` | false |
| 415 | `unsupported_media_type` | false |
| 422 | `invalid_payload` | false |
| 429 | `rate_limited` | **true** |
| 5xx | `server_error` | **true** |

An endpoint that can say something more precise throws
`Uhifadhi\Bundle\TeamBundle\Exception\ApiProblemException`, which carries its
own code, its own `retryable` and any `details` a client can act on, and is
answered verbatim. Everything a page renders keeps its own error handling: this
is the machine door only.

### It is throttled, and it throttles itself

Having no firewall means nothing upstream counts attempts for it, so it counts
for itself: **five a minute per identifier and twenty a minute per address**,
spent before the credential is weighed — so a valid credential replayed in a
storm is throttled like any other traffic. Two budgets rather than one, because
per-identifier stops a targeted guess against one account and per-address stops
a spray across many, and either alone leaves the other attack untouched.

```jsonc
// 429 — the one refusal worth repeating, because only time fixes it
{ "code": "rate_limited", "message": "…", "retryable": true, "details": {} }
```

The bundle **prepends** the two limiters, so an installation writes no
rate-limiter configuration; a deployment that wants other numbers names them in
its own `framework.yaml` and its answer wins, with nothing to switch off first.

The web form's twin of this is `login_throttling`, which the firewall does —
see below.

Signing in again on the same handset **rotates** that handset's row rather than
adding another, so a wipe leaves no trail of live credentials. A different
handset gets its own row, which is what lets one be withdrawn alone.

## Asking again: `GET /api/me`

A permission granted in the web app has to reach a handset **without a sign-out,
a re-install or any ceremony** — months pass between sign-ins, and a client that
learned it may record only at its next sign-in would have a refusal it could not
clear. So sign-in's two facts are readable on their own:

```jsonc
// 200, for the bearer account and nobody else
{
  "ranger": { "id": "sl-0142", "name": "…", "role": "…" },
  "permissions": ["area.view"]
}
```

There is no identifier in the address and none is accepted: **the token is the
subject**, so this cannot be turned into a way to read somebody else's
permissions. `ranger.role` is the POSITION where there is one and the tier
otherwise, because a refusal screen names the thing an administrator has to
change. `permissions` follows the same rule as at sign-in — always sent,
including empty, an empty array being a refusal.

It is served through **api-platform**, which the bundle requires: the resource is
`ApiResource/Me.php` and the provider behind it is `team.api.me_provider`. Neither
the bundle nor an installation registers the resource — a bundle's `ApiResource`
directory is a mapped path api-platform reads off `kernel.bundles_metadata`, so a
`mapping.paths` line is not needed and writing one would *disable* the defaults an
installation's own resources rely on.

Two lines of an installation's `api_platform.yaml` matter to a field client, and
neither is written by api-platform's own recipe:

```yaml
api_platform:
    # JSON only: JSON-LD would answer with @context and @id members, which the
    # field contract does not describe.
    formats:
        json: ['application/json']
    # Narrowed WITH formats, always. error_formats is a separate setting whose
    # default is JSON-LD first, so narrowing only the line above leaves refusals
    # being rendered by a serializer that is no longer registered — and a 406
    # comes out as a 500.
    error_formats:
        json: ['application/problem+json', 'application/json']
```

## The screens

Sign in, forgotten password, reset and accept-an-invitation are the three a
stranger reaches with nobody to ask, so they work with no session at all. Behind
them: the roster, one person's record, the permission matrix and the
departments. There is no delete route and there will not be one — an account is
deactivated, never removed, so everything it recorded keeps its author.

### One matrix, one grammar

Every topic on the performance page is drawn by one renderer, the host's own
Staffing and a module's Patrols alike:

```twig
{{ include('@Team/performance/_stylesheets.html.twig') }}
{{ render_matrix(topic.matrix(scope, period), {
    title: topic.title,
    publisher: 'the host',
}) }}
```

A caller may name the card and say who published it, and nothing else — no
class, no colour, no column — because a matrix that differed by who wrote the
page would be a matrix a reader has to learn twice.

**Where a department stands is the page's decision, not the topic's.** A
provider publishes figures and states which way is good; the shade is worked
out here, inside one column and one band, and is not drawn at all where fewer
than three departments in the band have a figure. A column with no polarity is
never tinted, an absence is dashed rather than pale, and the legend under every
matrix says what a shade is *not*.

The board's own vocabulary ships as a second sheet, `bundles/team/performance.css`
(`TeamBundle::PERFORMANCE_STYLESHEET`), linked by the partial above; the sort in
the headers runs inside each band and never across one, and the matrix is whole
without it.

## The one scheduled task

A closed period cannot be recomputed. "Positions filled in July" is not a query
anybody can write in September — posts are filled and emptied, people move,
records are edited — so the host writes each period down while it is still
true, and every movement, sparkline and rank change on the performance page is
read back out of that record.

```cron
0 1 1 * *  php bin/console team:performance:snapshot
```

It writes **the period that has just closed**; the period a page is currently
showing is computed live and is never snapshotted, because it is still moving.
Run it by hand to correct a period (`--period=2026-08`), and after installing a
module so that module's first period is a figure rather than a hole. Running it
twice for one period corrects the history rather than doubling it.

An installation that has never run it has no history, and the surfaces say so
in words. They do not read the absence as nought: a department nobody measured
last July and a department that measured nought are not the same thing.

## Configuration

```yaml
# config/packages/team.yaml (your application)
team:
    after_sign_in_path: '/'          # where an already-signed-in visitor at /login goes
    sign_in_lede: '…'                # the line under the mark on the sign-in card
    installation_name: '…'           # what the two letters sign themselves
    mail_from: ''                    # empty means this installation cannot send
```

`mail_from` is empty by default, and that is the honest default: it is what
makes the invite-by-email path refuse itself **in writing** rather than dropping
a colleague's invitation on the floor. A mailer with no transport reads the
same way.

## License

**AGPL-3.0-or-later** — see the core's [LICENSE](../../../../LICENSE). Use,
modify and self-host freely; if you offer a modified version to users over a
network, they are entitled to the source of what they're running.
