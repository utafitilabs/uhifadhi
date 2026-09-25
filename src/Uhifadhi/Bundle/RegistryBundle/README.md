# RegistryBundle

The **registry**: the runtime every uhifadhi module registers with. It carries
the module catalogue, the per-area record of what is switched on, the
permissions modules declare, the automatic sync that keeps the catalogue in
step with what is installed, the facts ledger the worker files figures over
growing sets on, and the runtime an installation runs on: the queue's table,
the `default` schedule and the statement timeout of a web request. It renders
nothing.

One of the bundles of the uhifadhi core, `uhifadhi/uhifadhi`. It can be
installed on its own as `uhifadhi/registry-bundle`.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Parking a module closes its routes](#parking-a-module-closes-its-routes)
- [The facts ledger](#the-facts-ledger)
- [The runtime](#the-runtime)
- [Configuration](#configuration)
- [Learn more](#learn-more)
- [License](#license)

## What it is

**Uhifadhi is one skeleton, one core and a set of modules.** The skeleton
(`uhifadhi/skeleton`) is copied once and never updated; the core
(`uhifadhi/uhifadhi`) arrives whole and is updated forever; everything a
deployment can *do* — patrols, incidents, rosters — is a module.

A module **registers with the registry** (this bundle) and **renders in the
shell** (`ShellBundle`). Each core bundle can be installed into a Symfony
application that also has the registry and the shell; Composer enforces that
dependency, and a bundle listed in an application's bundle list is not a promise
that it runs alone.

## Installation

The core is one package:

```bash
composer require uhifadhi/uhifadhi
```

Flex adds `Uhifadhi\Bundle\RegistryBundle\RegistryBundle` to
`config/bundles.php` and copies `config/packages/registry.yaml` in.

### An area is required, and a bundle answers it

The per-area table has a `NOT NULL` foreign key to an area, so until
`AreaInterface` resolves to a class there is no schema to create. `AreaBundle`,
which ships in the same package, states the resolution for you.

**Your installation writes no `doctrine.yaml` line at all.** You write a
resolution line only to disagree — see
[docs/configuration.md](docs/configuration.md) for that, and for why the registry
cannot name an area class itself.

### Then the tables

```bash
bin/console doctrine:database:create
bin/console cache:clear --no-warmup
bin/console doctrine:migrations:migrate
bin/console registry:sync
bin/console cache:warmup
```

Three tables, `module`, `area_module` and `figure_fact`, and the registry ships
the versions that create them — `migrations/`, namespace
`Uhifadhi\Bundle\RegistryBundle\Migrations`, registered from the bundle's own
`prependExtension()`, so an installation configures nothing.
`doctrine:migrations:diff` stays what an installation runs for the entities IT
writes. The registry also names the service that decides the ORDER every version
runs in, across every namespace an installation has, because a version's
identity is its class name and a package that sorts early would otherwise create
a table before the one its foreign key points at.

### What reconciles the catalogue, and when

`registry:sync`, typed once after every install and every upgrade — after the
migrations, before the warm-up. It reads every installed module bundle's
provider, upserts a catalogue row per slug, gives every area the rows it lacks,
and prints a ledger: the modules **added**, **kept** and **retired** (a retired
module's rows stay; only the report names it) and the area rows created. Typed
again it changes nothing. Typed before the registry's tables exist it exits
non-zero and names `doctrine:migrations:migrate` as the step that comes first.

**A web request reconciles nothing and opens no connection on the registry's
account.** The catalogue is filled by the command in the deploy that migrated
the tables, so a request arriving on an installation that has not migrated yet —
a proxy's probe of a liveness route that reads no database — is answered without
the registry asking the database anything.

## Parking a module closes its routes

Where an area has parked a module, that module's pages answer **404** there —
enforced once, in the registry, before any controller runs. Nothing is asked of
the module, but a module that says which one it is gets read precisely rather
than inferred from its URL. One line per controller:

```php
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;

#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => 'your-slug'])]
final class YourModuleController { /* every route below is yours */ }
```

404 and not 403: parking withholds nothing, it means the area is not running the
module. See [docs/guarantees.md](docs/guarantees.md) for the recognition rules,
the cost, and what the gate deliberately does not do.

## The facts ledger

**A request never computes over a set that grows with time or headcount.** A
figure like "coverage of each zone this month" is computed by the queue worker
on a schedule, filed in `figure_fact` — one row per subject, figure and period,
replaced by the next run (`INSERT … ON CONFLICT DO UPDATE`) — and read by the
page as a number with the time it is true as of. When the worker lags, the page
shows the last figure and its time; it never computes and it never fails.

- **Modules compute, the registry files.** A module tags a
  `Uhifadhi\Contracts\Facts\FactProviderInterface` with `uhifadhi.facts`; a
  page reads through `FactReaderInterface` (`registry.facts.reader`). A quarter
  or year of an additive figure is the sum of its month rows, at most twelve.
- **The schedule.** A task on the `default` schedule, hourly 06:00–20:00 and
  at 02:00 in the installation's zone, queues `RecomputeOpenFacts`; the worker
  recomputes the month, quarter and year open now, and once more a period
  that has just ended. A closed period is otherwise never recomputed.
- **The operator.** `bin/console uhifadhi:facts:rebuild [--module=] [--subject=]
  [--from=2026-01] [--until=2026-09]`, after the deploy that brings a module's
  facts and after a rule they depend on changes. Idempotent.
- **The worker.** `bin/console messenger:consume async scheduler_default`; the
  core's recipe routes `Uhifadhi\Contracts\Queue\AsyncMessageInterface` to
  `async`. See [The runtime](#the-runtime).

The recipe for a module is in the contracts' module guide, "Facts a module
computes on a schedule".

## The runtime

What an installation runs on besides the web server, and where each piece is:

| Piece | Where |
| --- | --- |
| **The queue.** `async` and `failed` on the Doctrine transport, `failure_transport: failed`, `Uhifadhi\Contracts\Queue\AsyncMessageInterface` routed to `async` | the core's recipe, `config/packages/registry.yaml`, beside the `registry:` keys; `MESSENGER_TRANSPORT_DSN` is `symfony/messenger`'s recipe's `.env` line |
| **The queue's table.** `messenger_messages` | `migrations/Version20260925210000.php` — the DSN's `auto_setup=0` leaves the schema to the migrations |
| **The schedule.** `default`, and its `scheduler_default` transport | built by the Scheduler from the core's tasks (`registry.facts.schedule`), running only the last missed run, its state (`registry.schedule.state`, a cache pool on the Doctrine DBAL adapter) and its lock (`registry.schedule.lock_factory`, the Doctrine DBAL store) in the database, in `registry_schedule_state` and `registry_schedule_lock` (`migrations/Version20260925220000.php`) — they outlive a redeploy and a second worker container shares them (`DependencyInjection/Compiler/StatefulDefaultSchedulePass.php`, `config/services.php`); a provider of the installation's own is joined and keeps its own state |
| **The statement timeout.** `SET statement_timeout` on every connection opened to serve a request | `Doctrine/StatementTimeoutMiddleware.php`, on when `registry.statement_timeout_ms` is set; the recipe sets it to `%env(int:DATABASE_STATEMENT_TIMEOUT_MS)%`, `10000` in `.env` |

The statement timeout is read from the server API when a connection opens:
`cli` — `bin/console`, the migrations, the worker, a rebuild — never has it;
every web server API does. `0` is no limit. PostgreSQL cancels a statement past
it (<https://www.postgresql.org/docs/current/runtime-config-client.html#GUC-STATEMENT-TIMEOUT>),
so the image's `max_execution_time` stays above it.

The worker is one process: `bin/console messenger:consume async scheduler_default`.

## Configuration

```yaml
# config/packages/registry.yaml (your application)
registry:
    default_category: operations   # where an unplaced module is filed
    dev_tools: false               # dev-only tooling; enable via when@dev / when@test
    facts:
        schedule: ['0 6-20 * * *', '0 2 * * *']   # when the open periods are recomputed
        timezone: ~                # the zone those hours are in; ~ is PHP's default
    statement_timeout_ms: ~        # a web request's longest SQL statement; ~ or 0 is no limit
```

Every key has a default and the tree is closed. There is deliberately no key
listing modules — see [docs/configuration.md](docs/configuration.md).

## Learn more

- [docs/architecture.md](docs/architecture.md) — what the registry owns, why zero
  modules is a working installation, and where each piece lives.
- [docs/boundaries.md](docs/boundaries.md) — what the registry is not: why the
  module grid and the customize screen belong to the shell, with the split in a
  table.
- [docs/guarantees.md](docs/guarantees.md) — the behaviour table, every row a
  test, and the attention-list promise behind it.
- [docs/configuration.md](docs/configuration.md) — the `registry:` tree, resolving
  the area contract, bringing your own area, and whose migration history the
  tables are.
- [docs/development.md](docs/development.md) — the standard, tests-first, and
  the two test kernels.

## License

**AGPL-3.0-or-later** — see the core's [LICENSE](../../../../LICENSE). Use,
modify and self-host freely; if you offer a modified version to users over a
network, they are entitled to the source of what they're running.
