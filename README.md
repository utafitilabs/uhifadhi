# uhifadhi/uhifadhi

**The uhifadhi core.** One package, one version, several bundles: each bundle
under `src/Uhifadhi/Bundle/` is its own PSR-4 root with its own `composer.json`,
and one tag on this repository is the platform version.

The core arrives whole and is never picked apart. What a deployment can *do* —
patrols, incidents, rosters — arrives as **modules**, each its own package.

New to uhifadhi? Start with [uhifadhi/skeleton](https://github.com/utafitilabs/skeleton),
which says what the platform is and how an installation is created. This
repository is the core that installation runs on, written for the developer who
updates it or builds a module against it.

## Contents

- [What the core is](#what-the-core-is)
- [The packages](#the-packages)
- [Installation](#installation)
- [Upgrading](#upgrading)
- [The controllers an installation registers](#the-controllers-an-installation-registers)
- [Development](#development)
- [Versioning](#versioning)
- [License](#license)

## What the core is

Each core bundle can be installed into a Symfony application that also has the
registry and the shell; Composer enforces that dependency, and a bundle listed
in an application's bundle list is not a promise that it runs alone.

That is why "the core" is the word an installer document uses, and "bundle" is
a word for developers. An admin installs *the core* and then *modules*.

## The packages

| Package | Installable alone as | What it is |
|---|---|---|
| `src/Uhifadhi/Contracts` | `uhifadhi/contracts` | the interfaces a module declares itself with — MIT, and depended on by modules that want nothing else |
| `RegistryBundle` | `uhifadhi/registry-bundle` | the module catalogue, the per-area install ledger, parking, declared permissions |
| `ShellBundle` | `uhifadhi/shell-bundle` | the document, the page frame, navigation, the theme, widget surfaces |
| `TeamBundle` | `uhifadhi/team-bundle` | people: the account, positions, departments, the sign-in and invitation screens |
| `AtlasBundle` | `uhifadhi/atlas-bundle` | maps, charts and the chrome every one of them wears |
| `AreaBundle` | `uhifadhi/area-bundle` | the ground: areas, their boundaries, the zones inside them and the overview |

The contracts stay a package of their own so a capability module can depend on
interfaces alone, and stay MIT while the runtime around them is AGPL: an
interface anybody may implement should cost nobody anything.

## Installation

```bash
composer require uhifadhi/uhifadhi
```

Flex writes one `config/bundles.php` line per core bundle and copies one
`config/packages/<bundle>.yaml` each. Then the tables:

```bash
bin/console doctrine:migrations:migrate
bin/console cache:warmup                  # the registry reconciles itself
```

**The core ships its own migrations.** Each bundle that owns tables carries a
`migrations/` directory under its own namespace and registers it from its
`prependExtension()`, so an installation configures nothing and generates
nothing. `doctrine:migrations:diff` is still what an installation runs — for the
entities **it** writes. Run it after a core update and it must report
`No changes detected in your mapping information.`; pass `--allow-empty-diff` if
you want that outcome to exit zero for a script.

### Then the first administrator

Every screen is behind the sign-in the installation does not have yet, so the one
account that cannot be made through a screen is made from the console, once, on
the deployment itself:

```bash
docker exec <web> php bin/console team:user:create      # or: kamal app exec "php bin/console team:user:create"
```

It asks for the address, the two names, the tier and the passphrase, and the
passphrase is never echoed. [`TeamBundle`'s
README](src/Uhifadhi/Bundle/TeamBundle/README.md#then-the-first-administrator)
documents the scripted and piped forms.

**That command is the one exception to a standing rule.** The core ships **no
console commands** — devkit, a development-only package, owns every command the
platform has — and `team:user:create` is the single documented exception, because
a production installation is built without development packages and the first
account has to be made where the deployment is.

### The order versions run in

**A package's versions run after the versions of every package it requires.**
The date in the class name orders versions inside one package, and between two
packages neither of which requires the other — where the dependency graph has
nothing to say. Your own versions run last: your application requires the
packages and none of them requires it.

A migration's identity in doctrine/migrations is its **full class name**, and
the comparator that ships with it is a `strcmp` over that name
(`vendor/doctrine/migrations/src/Version/AlphabeticalComparator.php`), so with a
namespace per package the order is alphabetical by namespace — and a package
whose name sorts early creates a table before the table its foreign key points
at. A date is no better once packages are written by different people: a module
released last year carries last year's timestamps and would be planned in front
of the core it was built against. The core registers a comparator that reads
the Composer dependency graph instead, through `doctrine_migrations.services`,
the seam the migrations bundle documents for it.

The graph comes from the `require` and `replace` blocks of each installed
package's own `composer.json`, read at the install path Composer reports, so
nothing is declared twice and nothing has to be kept in step by hand. A version
is placed by its namespace: the namespace names a registered directory, the
directory sits inside one installed package, and a directory inside none of
them is your application's.

Two consequences worth having in mind:

- **The five core bundles are one package**, so the dates in their class names
  are what orders them among themselves. Area, Registry, Team, Shell.
- **A module that requires `uhifadhi/registry-bundle` is placed behind the whole
  core**, because the core is the one package that answers to that name.

If two packages that both ship migrations require each other,
`doctrine:migrations:migrate` stops and names them rather than picking an
order. A cycle anywhere else in the graph is not looked at — `league/flysystem`
and `league/flysystem-local` require each other, and no schema depends on which
of them is imagined to come first.

## Upgrading

```bash
# 1. Back the database up. Nothing below replaces this.
# 2. Read what is about to run.
bin/console doctrine:migrations:migrate --dry-run
# 3. Run it.
bin/console doctrine:migrations:migrate
```

Two hatches when it goes wrong. `doctrine:migrations:version --add
'<Fully\Qualified\Version>'` marks one version executed without running it —
for the case where the change is already in the database and only the ledger
disagrees. `doctrine:migrations:migrate --write-sql=upgrade.sql` writes the
statements to a file instead of executing them, for a database somebody else
applies changes to.

### Your own entities, and where their versions land

The core generates nothing for you, and you generate nothing for the core. For
the entities **your** application writes:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

That version is written into your own `migrations/` directory — the one your
`config/packages/doctrine_migrations.yaml` maps under `DoctrineMigrations` —
and not into a package's, even though every core bundle registers a namespace
of its own too.

It is worth saying because it is not what the commands do left alone. `diff`
and `generate` write into the namespace `--namespace` names, and with no flag
they fall back to the **first** one configured
(`vendor/doctrine/migrations/src/Tools/Console/Command/DoctrineCommand.php`,
`getNamespace()`). A bundle registers its path by prepending it, and prepended
configuration is merged ahead of the application's own, so the first one would
be a bundle's — which is to say `vendor/`, which the next `composer update`
deletes while the row in `doctrine_migration_versions` stays behind. The core
puts the directory no installed bundle ships back in front, so the flagless
command an installer runs writes where an installer expects.

Two cases still want the flag. If your application maps more than one namespace
of its own, name the one you mean:

```bash
bin/console doctrine:migrations:diff --namespace=DoctrineMigrations
```

And if you are developing a package rather than an installation — the core
itself, or a module — every configured namespace belongs to a package, so
`--namespace` is what says which:

```bash
bin/console doctrine:migrations:diff --namespace='Uhifadhi\Bundle\AreaBundle\Migrations'
```

### The three rules every version here keeps

**Expand, backfill, contract — in that order, in one version.** Add the column
nullable, write the value into the rows already there, then require it. A
version that adds a `NOT NULL` column to a table an earlier version created,
with neither a `DEFAULT` nor an `UPDATE` beside it, fails on the first
installation that has data in it.

**A column that cannot be backfilled for everyone ships nullable, and validation
enforces it.** There is no universally correct value for "the staff number this
organization has not issued yet", so the database stays permissive and the rule
lives where the rule actually is. A later release tightens the column once every
installation has been through the period where the value gets written.

**A destructive statement rides a later release than the code that stopped using
it.** Deleting a column is not reversible by a `down()`, so the release that
stops reading it and the release that drops it are two releases, and the file
that does the dropping says which one is which:

```php
/**
 * @destructive 1.4 — widget_preference.legacy_layout stopped being read in 1.3
 */
```

All three are enforced, not just written down: `tests/Core/MigrationLintTest`
reads the SQL every shipped version plans and fails the build on a violation.

## The controllers an installation registers

The core's Stimulus controllers reach an application through its
`assets/controllers.json`, under **one** name — the package's own:

```json
{
    "controllers": {
        "@uhifadhi/uhifadhi": {
            "theme": { "enabled": true, "fetch": "eager" },
            "sidebar": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

That name is the only one that can appear there. StimulusBundle resolves a
`controllers.json` key by stripping the `@`, asking Composer for the install
path of the package left over, and reading `assets/package.json` underneath it
(`Symfony\UX\StimulusBundle\Ux\UxPackageReader::readPackageMetadata()`). The
names in this package's `replace` block have no install path of their own, so
only `uhifadhi/uhifadhi` resolves — and the root [`assets/package.json`](assets/package.json)
is where every core controller is declared, each `main` pointing into the bundle
that owns the file.

Each entry also declares the `name` the markup uses, so a controller is called
`uhifadhi--shell-bundle--theme` whether it was resolved through this manifest or
through the bundle's own — which is why each bundle keeps its `assets/package.json`
too: after a split, that file is what the bundle's own package answers with, and
the templates do not change.

## Development

```bash
composer install
composer check   # cs:check -> phpstan (max) -> require-check -> the suite
```

- PHP 8.4+, PHPStan level **max** over `src` and `tests`, php-cs-fixer
  `@Symfony` + `@Symfony:risky`.
- **Tests first, always.** A behaviour change starts as a failing test naming
  the class or service id it wants.
- The suite is one PHPUnit testsuite per bundle, each in that bundle's `tests/`
  directory, against a real PostGIS database (`uhifadhi_core_test`, port 5434
  locally, the CI service in the workflow).
- `tests/Application/` is an embedded throwaway app — a real kernel with the
  core bundles on it — for the specifications that are about the bundles working
  together. It is export-ignored: nobody installs it.
- **`composer require-check` is the boundary.** Each bundle's `composer.json`
  declares what that bundle may use, and `composer-require-checker` fails the
  build on any symbol it did not declare. That is what makes splitting this
  repository a mechanical step rather than a refactor.

## Versioning

One tag on this repository is the platform version. The `replace` block names
the six packages this repository can be split into — the contracts and the five
bundles — each at `self.version`, so a `require` line naming one of them
resolves against the core and reports the core's version. Nothing else belongs
in that block: a name there is a promise that this package *is* that package.

The root [CHANGELOG-1.0.md](CHANGELOG-1.0.md) and [UPGRADE-1.0.md](UPGRADE-1.0.md)
are the release notes; each bundle also keeps its own `CHANGELOG.md`.

**A tag is not done until the fleet gate is green.** The starter repository
(`utafitilabs/skeleton`) carries `composer fleet-gate`, which creates a project
with its README's own commands and installs this core and every official module
into it, one by one, signing in after each. Before tagging, run
`composer fleet-gate:head` there, which installs from the sibling checkouts on
your machine; after tagging, run `composer fleet-gate`, which installs from the
published repositories. A core tag is what exposes a module that was adapted on
its branch but never released, so the released run always installs every
module. The starter's `docs/fleet-gate.md` has the steps and how to read a red run.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE). Use, modify and self-host
freely; if you offer a modified version to users over a network, they are
entitled to the source of what they're running.
