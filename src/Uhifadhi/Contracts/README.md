# Contracts

The contracts a uhifadhi module declares itself with.

One package of the uhifadhi core, `uhifadhi/uhifadhi`. It can be installed on
its own as `uhifadhi/contracts`: a capability module implements these interfaces
and needs nothing else of the core at compile time.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Development](#development)
- [Learn more](#learn-more)
- [License](#license)

## What it is

Interfaces and the small value objects that cross them. `ModuleProviderInterface` is how a bundle
announces itself to a host as a module — the catalogue metadata and the route it enters on.
`Entity\UserInterface` is how a module's records point at a person without depending on whoever owns
accounts, and `Entity\AreaInterface` is how they point at an area without depending on whoever owns
areas. The `Devkit\` interfaces are how a module contributes dev-only demo content and maintenance
commands that a `require-dev` collector materialises in a dev install and never in production.
The `Facts\` interfaces are how a module files a figure over a growing set on the core's facts
ledger — computed by the worker on a schedule, read by a page as a stored number with its time —
and `Queue\AsyncMessageInterface` is the one marker an installation routes to its queue.

MIT — public on purpose, and deliberately a different licence from the rest of the core: an
interface anybody may implement should cost nobody anything, while the runtime that implements
them is AGPL. Built-in and installed modules implement these identically, and the registry
collects both through one tag.

## Installation

```bash
composer require uhifadhi/uhifadhi
```

There is nothing to configure and no bundle to register: this package is interfaces, and the
core you install them beside already knows what to do with an implementation of them.

## Getting started

Implement `ModuleProviderInterface` once, in the bundle that is your module:

```php
// src/Module/SightingsModuleProvider.php (your bundle)
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

final class SightingsModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait; // defaults for the optional methods

    public function slug(): string     { return 'sightings'; }
    public function name(): string     { return 'Sightings'; }
    public function description(): ?string { return 'Every animal seen in the field, and where.'; }
    public function category(): string { return 'biodiversity'; }
    public function entryRoute(): ?string { return 'sightings_area'; }
}
```

Then tag it. RegistryBundle autoconfigures every implementation with `uhifadhi.module` and its
registry sync ingests them — but a module shipped as a *reusable bundle* is **not**
autoconfigured, so tag `uhifadhi.module` explicitly in your extension.

Where a record needs a person on it, type-hint the contract rather than an account class:

```php
// src/Entity/Sighting.php (your bundle)
use Uhifadhi\Contracts\Entity\UserInterface;

#[ORM\ManyToOne(targetEntity: UserInterface::class)]
private ?UserInterface $recordedBy = null;
```

TeamBundle prepends the `resolve_target_entities` line that points it at a real class, so an
installation writes no doctrine configuration of its own.

A figure that reads every record of a period — coverage this month, metres this quarter — is never
computed by a page. Implement `Facts\FactProviderInterface`, tag it `uhifadhi.facts`, and read the
figure back through `Facts\FactReaderInterface`; the core's schedule computes the open periods and
`uhifadhi:facts:rebuild` the closed ones. The recipe is
[Facts a module computes on a schedule](docs/module-development.md#facts-a-module-computes-on-a-schedule).

## Development

The core is one repository; `composer check` at its root is the whole verdict.

```bash
composer install
composer check   # cs:check -> phpstan (max) -> require-check -> the suite
```

## Learn more

- **[What a contract is](docs/what-is-a-contract.md)** — what the word means here, and why some
  contracts live in this package while others live with the bundle they describe.
- **[Building a uhifadhi module](docs/module-development.md)** — the full guide from
  `composer.json` to a Flex recipe, written for someone building a custom module against these
  contracts.
- **[The module frame](docs/module-development.md#8-the-module-frame-tabs-and-the-configure-page)** —
  `Shell\ModuleTabsInterface` and `Shell\ConfigurationSectionsInterface`: the two lists a module
  declares so the shell draws its tab strip, its rung of the sidebar tree, its one `Configure`
  action and its one configure page.
- **[`ModuleProviderInterface`](docs/module-provider.md)** — the full metadata surface, and the
  base-versus-installable tier that decides whether your module arrives switched on.
- **[`Entity\UserInterface`](docs/user-interface.md)** — the seven questions it asks, and the rule
  that whoever provides the entity states the resolution.
- **[`Entity\AreaInterface`](docs/area-contract.md)** — the three questions it asks (id, name, uuid),
  how `resolve_target_entities` maps a relation to the real area at runtime, and how to enumerate
  areas through the ORM without depending on AreaBundle.
- **[A dropdown on the People register](docs/module-development.md#a-dropdown-on-the-people-register)** —
  `People\PeopleFacetProviderInterface`, tagged `uhifadhi.people_facets`: the one dropdown a module
  adds to the People register, its options carrying the people they apply to so the count and the
  filter are one fact.
- **[The devkit contracts](docs/devkit-contracts.md)** — `Devkit\ContentProviderInterface` and
  `Devkit\CommandProviderInterface`: the require-dev firewall, why they live here and not in devkit,
  why a command is a descriptor-plus-closure rather than a `symfony/console` `Command`, and why that
  closure is handed a `Devkit\CommandIo` instead of reaching for `\STDOUT` itself — including why
  reading is two verbs, `readLine()` and a `readSecret()` that is never put on the screen.
- **[Why one package?](docs/why-one-package.md)** — why the registration contract and the
  data-shape contracts ship together, and the test that would split them.

## License

MIT. See [LICENSE](LICENSE) — this package's licence, not the core's.
