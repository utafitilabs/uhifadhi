# Configuration

The `registry:` config tree, how the area contract gets resolved — including
disagreeing with the bundle that answers it — and what the registry does and does
not ship for migrations.

## Contents

- [The `registry:` tree](#the-registry-tree)
- [An area is required, and a bundle answers it](#an-area-is-required-and-a-bundle-answers-it)
- [The tables, and whose migration history they are](#the-tables-and-whose-migration-history-they-are)
- [What decides the order every version runs in](#what-decides-the-order-every-version-runs-in)

## The `registry:` tree

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

`facts.schedule` is a list of cron expressions, at least one; each becomes a
`scheduler.task` on the `default` schedule — the tag `#[AsCronTask]` writes —
so it joins the installation's own `default` schedule where there is one
(<https://symfony.com/doc/current/scheduler.html>). The default is every hour of
the working day and once at night: nobody watches a coverage figure tick, and
"as of 13:00" is as true as a reader needs. `facts.timezone` names the zone the
hours are in; left out, they are PHP's default zone, the installation's.

`statement_timeout_ms` is the longest one SQL statement of a web request may
run, in milliseconds. Set, the bundle registers `registry.statement_timeout_middleware`,
an abstract service tagged `doctrine.middleware` — DoctrineBundle's own way —
on every connection (<https://symfony.com/bundles/DoctrineBundle/current/middlewares.html>);
a connection opened under a web server API then runs `SET statement_timeout`
first, and one opened by the console does not. `0` is no limit at run time;
left out, there is no middleware. The core's recipe binds it to the typed env
`%env(int:DATABASE_STATEMENT_TIMEOUT_MS)%`
(<https://symfony.com/doc/current/configuration/env_var_processors.html>).

The core's recipe writes this file with a second root beside `registry:` —
the queue the registry's work runs on, `framework.messenger`: `async` and
`failed` on `%env(MESSENGER_TRANSPORT_DSN)%`, `failure_transport: failed`,
`Uhifadhi\Contracts\Queue\AsyncMessageInterface` routed to `async`, and
`in-memory://` transports under `when@test`. Framework configuration merges
across files, so an installation's own `config/packages/messenger.yaml` and
this block make one (<https://symfony.com/doc/current/configuration.html#configuration-files>).

Every key has a default; the tree is closed, so an unknown key fails loudly
rather than being ignored. There is deliberately **no key listing modules** —
installing a module is the declaration, and a second place to enable one is
a second place for the two to disagree.

## An area is required, and a bundle answers it

The registry owns three tables and the per-area one has a `NOT NULL` foreign key to an
area, so until `AreaInterface` resolves to a class there is no schema to create —
every tool that walks the association stops:

```console
$ bin/console doctrine:schema:create
In MappingException.php line 72:
  Class 'Uhifadhi\Contracts\Entity\AreaInterface' does not exist
```

Booting is fine — an installation between `composer require` and its first entity
must still boot, and `Integration/InstallabilityTest` pins both halves of that.

**Whoever knows the answer states the resolution.** That is the fleet's rule and
it settles this one: the registry cannot name an area class, because it holds the
per-area table for installations whose area model is their own — but the bundle
that *provides* an area can, and does.

The contract itself is `Uhifadhi\Contracts\Entity\AreaInterface`, published
by uhifadhi/contracts alongside the user contract, because more than the
registry points at an area.

`AreaBundle` maps its own entity and prepends the resolution, exactly the way
`TeamBundle` answers the user contract; both ship in the core, so **your
installation writes no `doctrine.yaml` line at all** — a bare installation
reaches `doctrine:migrations:diff` with zero doctrine edits.

**This is deliberately not a hand-step.** A hand-step is for a decision only the
installation can make, and "what is an area" is not one while a bundle in the
same package ships an answer. A missed hand-step here would fail a long way from
its cause — the container compiles, the kernel boots, and the diff stops on the
message above with nothing pointing back at the paragraph that was skipped.

### Bringing your own area

You write a resolution line only to **disagree**. An installation whose areas are
its own entity — its own columns, its own name for the thing — names that class
in its own config and wins, because prepended configuration loses to the
application's by design:

```yaml
# config/packages/doctrine.yaml (your application)
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\Contracts\Entity\AreaInterface: App\Entity\ManagementUnit
```

Merge it into the `doctrine:` block already in that file, under the existing
`orm:`, beside `mappings` — a second `doctrine:` key in one file is not valid
YAML. Your class needs `getId()` and nothing else, and it has to be in the
mapping chain: the stock doctrine-bundle recipe writes an `App\Entity` prefix, so
an entity in `src/Entity/` is already covered. If it is not, the line resolves and
the class it names is still missing from the chain:

```console
The class 'App\Entity\ManagementUnit' was not found in the chain
configured namespaces App\Entity, Uhifadhi\Bundle\RegistryBundle\Entity
```

`Uhifadhi\` on its own is the platform's, not an application's — this bundle is
`Uhifadhi\Bundle\RegistryBundle\` — so do not reach for it as your own root. The left-hand side is
the registry's and never changes.

## The tables, and whose migration history they are

`doctrine/doctrine-migrations-bundle` is a dependency of **this** bundle: the
bundle that adds tables brings the tool that creates them, the same way it has
always brought the ORM. An installed project that lacked it had no
`doctrine:migrations:*` commands at all and no hint that it should.

It ships the versions too. `module` and `area_module` are the registry's tables,
so the statements that create them live in `migrations/` under
`Uhifadhi\Bundle\RegistryBundle\Migrations`, registered from the bundle's own
`prependExtension()`. An installation runs `doctrine:migrations:migrate` and
generates nothing; `doctrine:migrations:diff` is what it runs for the entities it
writes itself.

## What decides the order every version runs in

The ordering of every migration in an installation is registered here, for the
same reason: this is the one core bundle that requires
`doctrine/doctrine-migrations-bundle`, and an order across namespaces is a
property of the installation rather than of any one bundle's tables.

`Version/DependencyOrderComparator.php` replaces
`Doctrine\Migrations\Version\Comparator` through the `doctrine_migrations.services`
key, which is the seam the migrations bundle documents for it. The rule it
applies:

- a package's versions run after the versions of every package it requires;
- the timestamp orders versions inside one package, and between two packages
  neither of which requires the other;
- versions an installation keeps itself run last: the root package requires the
  packages and nothing requires it, and its `replace` block is not read as a
  claim to provide anything — a skeleton replaces `symfony/polyfill-ctype`, and
  reading that as a promise would make every package depend on the installation;
- two packages that both ship migrations and require each other are refused by
  name; a cycle anywhere else in the graph is not looked at.

The graph comes from the `require` and `replace` blocks of each installed
package's own `composer.json`, at the install path
`Composer\InstalledVersions::getAllRawData()` reports. A version is placed
through the migrations configuration: class name to namespace, namespace to the
registered directory, directory to the package whose install path contains it.

The other half of owning that behaviour is
`DependencyInjection/Compiler/InstallationMigrationsPathFirstPass`, which puts
the directory no installed bundle ships in front of the packages' so a flagless
`doctrine:migrations:diff` writes where an installation expects.
