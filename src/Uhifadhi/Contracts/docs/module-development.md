# Building a uhifadhi module

This guide is how a uhifadhi module is built against the public contracts. The platform's own
modules are built this way too — there is no separate path. It is written from the extractions those
modules went through, so where the platform has a rough edge the guide says so rather than describing
the version we wish existed.

The running example is a fictional **Sightings** module — wildlife observations recorded in an
area — because a made-up module can be shown end to end without pretending a real deployment
works some particular way.

New to the word "contract" as this platform uses it? Read
**[What a contract is](what-is-a-contract.md)** first — it is one page, and everything in
chapter 3 assumes it.

**Reading the examples.** Every code block opens with a comment naming the file it belongs in,
relative to the root of whatever it belongs to — your bundle unless the comment says otherwise.
Where a block belongs somewhere else, the comment says which: `(the host application)`,
`(your recipe repository)`. Files that do not exist yet are named the same way, because the path
is the instruction: it says where you create it.

## Contents

- [1. Naming](#1-naming)
- [2. The scaffold](#2-the-scaffold)
- [3. Registering with the registry](#3-registering-with-the-registry)
- [4. Shipping importmap assets from a bundle](#4-shipping-importmap-assets-from-a-bundle)
- [5. Templates and Twig namespaces](#5-templates-and-twig-namespaces)
- [6. Using the atlas: maps, charts and calendars](#6-using-the-atlas-maps-charts-and-calendars)
- [7. Contributing to the overview](#7-contributing-to-the-overview)
- [8. The module frame: tabs and the configure page](#8-the-module-frame-tabs-and-the-configure-page)
- [9. Shipping migrations](#9-shipping-migrations)
- [10. Testing](#10-testing)
- [11. CI](#11-ci)
- [12. Flex recipe and activation](#12-flex-recipe-and-activation)

---

## 1. Naming

A capability is a **module** to users and a **bundle** to Symfony. Each word stays in its own
layer, and the namespace names the domain and argues with nobody.

| Layer | Rule | Sightings |
|---|---|---|
| Composer name (uhifadhi-exclusive) | `<vendor>/<name>-module` | `your-vendor/sightings-module` |
| Composer name (generic Symfony package) | `<vendor>/<name>-bundle` | — |
| PHP namespace | `<Vendor>\<Domain>\` — the composer vendor, then the DOMAIN, no meta-word | `YourVendor\Sightings\Entity\Sighting` |
| Bundle class (the one Symfony plug) | `<Vendor><Domain>Bundle` | `YourVendor\Sightings\YourVendorSightingsBundle` |
| Config alias (`extensionAlias`) | the bare domain word | `sightings:` |
| Service ids | prefixed with the alias | `sightings.observation_repository` |
| DB tables | prefixed with the domain word | `sightings_observation` |
| App UI / catalogue | module | the "Sightings" module tile |

Three consequences worth stating:

- **"module" appears only in the package name and the UI.** Never in the namespace, never in a
  class name. A class called `SightingsModuleService` is a smell.
- **The package name is never parsed.** Symfony Flex registers a bundle because the package
  declares `"type": "symfony-bundle"`, and it registers the bundle *class*. So a `-module`
  package registers exactly like a `-bundle` one.
- **`extensionAlias` must be set explicitly.** Without it, `YourVendorSightingsBundle` derives the
  alias `your_vendor_sightings`, and your users write `your_vendor_sightings:` in YAML forever. Set
  `protected string $extensionAlias = 'sightings';`.

Platform machinery follows the same rule even when it contributes no catalogue tile of its own.

### Name a package for what it does

**Capability modules take domain nouns** (`patrol`, `incident`, `roster`, `sightings`), and
**platform packages take software nouns** for what they actually do.

Metaphors are not names. A word that says what a package is *like* leaves a reader guessing what
it *is*, and on a conservation platform that guess is expensive: `Uhifadhi\Canopy\` could
plausibly draw pages or store foliage surveys, and nothing in the name settles it. Your own module
is almost certainly a domain noun already — the rule mostly bites when naming infrastructure,
which is where the temptation to be poetic is strongest.

The platform's own names make the architecture a sentence:

> **A module registers with the registry and renders in the shell.**

Three nouns recur throughout this guide. The **skeleton** (`uhifadhi/skeleton`) is the starter
project an installation is created from — `composer create-project uhifadhi/skeleton park`, and it
contains no bundle of its own, only `config/bundles.php`, `config/packages/security.yaml`,
`public/` and `.env`. The **registry** and the **shell** are two of the five bundles inside the
**core** (`uhifadhi/uhifadhi`): `RegistryBundle`, which every module registers with, and
`ShellBundle`, which every module renders into.

### The core is one package with five bundles

The core is a monorepo. One composer package, `uhifadhi/uhifadhi`, holds five bundles under
`src/Uhifadhi/Bundle/`, each with its own PSR-4 root and its own
`composer.json`, all tagged in lockstep:

| Bundle | Namespace | Config root | Owns |
|---|---|---|---|
| `RegistryBundle` | `Uhifadhi\Bundle\RegistryBundle\` | `registry:` | the module catalogue, the per-area install ledger, parking, declared permissions, the registry sync |
| `ShellBundle` | `Uhifadhi\Bundle\ShellBundle\` | `shell:` | the document, the page frame, navigation, the theme, the design-system stylesheet, the widget surfaces and presets |
| `TeamBundle` | `Uhifadhi\Bundle\TeamBundle\` | `team:` | people — users, positions, departments; answers `Entity\UserInterface` |
| `AreaBundle` | `Uhifadhi\Bundle\AreaBundle\` | `area:` | areas, zones, boundaries, the overview; answers `Entity\AreaInterface` |
| `AtlasBundle` | `Uhifadhi\Bundle\AtlasBundle\` | `atlas:` | maps, charts, legends and map chrome |

The contracts you build against are the sixth directory in that repository,
`src/Uhifadhi/Contracts/`, published as `uhifadhi/contracts`: one directory, one package, its own
`composer.json` and its own PSR-4 root. Requiring `uhifadhi/uhifadhi` gets you the
contracts with it, which is why your `require` block names the core and not the contracts.

**Modules are not in that repository.** Patrol, incident, roster, storage, telemetry, workflow and
devkit are separate packages, one repository each, each an ordinary Symfony bundle that requires the
core and implements these contracts. Yours is one of those.

**About `your-vendor`.** Everything else in this guide is the real uhifadhi world — the host is
uhifadhi, the tags are the tags, the classes you bind to are the classes. The one
placeholder is the example module's *own* identity: replace `your-vendor` with whatever vendor
you publish under, in the package name, the PHP namespace and the bundle class alike. It is left
blank on purpose, because official modules ship as `uhifadhi/*` and a guide that told you to
publish there would be teaching you to squat a namespace that is not yours.

The first-party modules use the composer vendor `uhifadhi/` (`uhifadhi/patrol-module`,
`uhifadhi/devkit-module`, …); the repositories they are published from live under
`github.com/uhifadhilabs`. A composer vendor and a GitHub organization are separate
namespaces and need not match — yours need match neither.

**The PHP namespace does follow the composer vendor, though**, and first-party code spells it out:
vendor `uhifadhi` ↔ namespace `Uhifadhi\<Domain>\` ↔ class `Uhifadhi<Domain>Bundle`. So
`uhifadhi/patrol-module` is `Uhifadhi\Patrol\` and `Uhifadhi\Patrol\UhifadhiPatrolBundle`, and
`uhifadhi/roster-module` is `Uhifadhi\Roster\` and `Uhifadhi\Roster\UhifadhiRosterBundle`. The core
is the one shape that differs, because a monorepo has a tier a single-bundle package does not: its
bundles sit under `Uhifadhi\Bundle\`, and their class names carry no vendor prefix — `RegistryBundle`,
not `UhifadhiRegistryBundle`. Yours is a single-bundle package, so use the first form. The GitHub
organization is in neither chain — `UhifadhiLabs\…` names nothing.

**And the application is `App\`.** A project created from the
[uhifadhi skeleton](https://github.com/utafitilabs/skeleton) is a stock Symfony application with
the stock root, which is exactly the point: `Uhifadhi\` is reserved for platform packages, so a
class under `Uhifadhi\` is always somebody's bundle and never the host you installed it into. Bind to
`App\Entity\…` in your own examples, and see [Stubs vs contracts](#stubs-vs-contracts) for the one
place a module is allowed to write a host FQCN itself.

---

## 2. The scaffold

A module is an ordinary reusable Symfony bundle. Nothing here is uhifadhi-specific except the
contracts dependency.

```
sightings-module/
├── .github/workflows/ci.yml
├── .php-cs-fixer.dist.php
├── .gitignore
├── LICENSE
├── README.md               # lean: what it is, install, wiring, a map into docs/
├── composer.json
├── phpstan.dist.neon
├── phpunit.dist.xml
├── assets/                 # importmap JavaScript (chapter to come)
├── config/services.php     # static wiring
├── docs/                   # everything the README is too short for
├── public/                 # stylesheets, vendor scripts, images
├── src/
│   ├── YourVendorSightingsBundle.php
│   ├── DependencyInjection/SightingsConfiguration.php
│   ├── Entity/ Repository/ Service/ Controller/
│   └── Module/SightingsModuleProvider.php
├── templates/
└── tests/{Unit,Integration,Functional}/
```

### The folder convention

Folders under `src/` are named after **technical kinds** — `Entity`, `Repository`, `Service`,
`Controller`, `Enum`, `Command`, `Model`, `DependencyInjection`. Flat, one level, the same names
Symfony itself uses. Two of them are worth being precise about, because the line between them is
where things get misfiled.

**`src/Entity/` is what the database remembers.** These are your Doctrine-mapped classes: they have
identity, they have a persistence lifecycle, migrations track their shape, repositories query them
and the ORM hydrates them. The folder is about the contract with persistence, not about being a
"business object" — which is why an *interface* that participates in that contract belongs here
too. The area contract (`AreaInterface`) is the example: it holds no data of its own, but a host
resolves it to a real entity through `resolve_target_entities`, and Doctrine maps an association
straight at it. It is part of the persistence contract, so it lives with the persistence contract.

**`src/Model/` is what your code thinks in, but never persists.** Plain value objects and
read-shapes: a row a service assembles for a template, a small immutable thing you pass around
instead of an array. No ORM mapping, no repository, no migration. They are born inside a service,
live for one request, and die.

The practical test, when you are not sure: **would deleting the database lose it?** If yes, it is an
Entity. If no — because the next request recomputes it from whatever *is* stored — it is a Model.

### `src/Domain/` is banned

Not because the name is ugly, but because it is a different **philosophy**, not different content.
A `Domain/` folder groups classes *vertically*, by business concept, and holds exactly the same
classes the horizontal folders hold — just sorted the other way. Running both is double bookkeeping,
and running only the vertical one puts you in an argument, in every module, about where the lines
between concepts fall.

The ruling this platform settles on is the reason this section exists: **the module boundary already
does the domain-grouping job.** Your module *is* the domain folder — a whole package of it, with its own
composer.json, its own tests, its own release cadence and a hard edge that a directory name can only
suggest. Grouping by domain again inside it re-answers a question the package already answered.

> Modules are the domain folders; inside them, folders are technical kinds.

### The README is lean; depth lives in `docs/`

Two documents, two audiences. **The README answers "how do I get this running?"** — it is read
once, by someone who has just typed `composer require` and wants the shortest honest path to a
working screen. **`docs/` answers "why is it like this, and what else can it do?"** — read later,
by someone extending the module, debugging its behaviour, or arguing with a decision.

A README that carries both makes the first reader scroll past a boundary essay to find the
config key they needed, so the split is a rule rather than a preference. Every module in the
fleet converges on the same README skeleton, in this order:

1. **Title and one-liner** — the package name, and one sentence saying what the module is for.
2. **What it is** — a few lines. Enough to know whether you want it.
3. **Installation** — `composer require`, and what the recipe does or does not do for you.
4. **Getting started** — the wiring steps that are *genuinely required* before the module works.
   Not the optional ones, not the interesting ones. If an installation can skip it, it is not
   here.
5. **Learn more** — a map into `docs/`, one line per document saying what is inside it.
6. **License**.

Everything else is `docs/`: architecture rationale, boundary rulings, behaviour tables,
configuration reference beyond the required keys, contract-change policy, design decisions,
naming arguments, upgrade transcripts, testing guides, and every section that begins with "why".
Name the files by topic, kebab-case — `docs/boundaries.md`, `docs/configuration.md`,
`docs/theming.md` — and prefer a few substantial documents over a drift of stubs.

Nothing gets **deleted** in the name of leanness. Depth that no longer fits the README moves into
a document and keeps its prose; a section that was worth writing is worth keeping, it just stops
standing between a new installation and its first working page. When you move a section, fix what
pointed at it — sibling documents, and the docblocks in `src/` and `tests/` that cite a README
heading by name.

Both the README and `docs/` ship inside the package tarball, so a reader who has only
`vendor/your-vendor/sightings-module/` on disk still has the whole set. Relative links between
them resolve on GitHub and in a checkout alike, which is the other reason the depth lives beside
the README rather than in a wiki.

### Pointing at a person

Sooner or later a Sightings record needs a name on it: who recorded the sighting, who verified it,
whose dashboard layout this is. **Do not type-hint an account class.** The accounts of an
installation belong to `TeamBundle`, and a module that named that bundle's `User` would be a module
bound to one concrete class — one that could never be pointed at an installation's own account
class instead.

Take the contract:

```php
// src/Entity/Sighting.php (your bundle)
namespace YourVendor\Sightings\Entity;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Contracts\Entity\UserInterface;

#[ORM\Entity]
class Sighting
{
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $recordedBy = null;
}
```

The interface holds no mapping of its own — the attributes are on your side, where the association
is — and it imports nothing, so taking it costs your module nothing to install.

#### Whoever knows the answer states the resolution

Then something has to say what the interface **means**, and this is the fleet's rule for who:

> **Whoever knows the answer states the resolution.** The package that provides the entity is the
> package that prepends the `resolve_target_entities` line. An installation writes one only when it
> wants to **disagree**, and its line wins, because prepended configuration loses to the
> application's by design.

There are two live instances of it, and they are the two contracts every module meets:

| Contract | Who answers it | What the installation writes |
|---|---|---|
| `Uhifadhi\Contracts\Entity\UserInterface` | `TeamBundle` — it knows its `User` | nothing |
| `Uhifadhi\Contracts\Entity\AreaInterface` | `AreaBundle` — it knows its `AreaOfInterest` | nothing |

```yaml
# what those two bundles prepend for you — no installation writes this
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\Contracts\Entity\UserInterface: Uhifadhi\Bundle\TeamBundle\Entity\User
            Uhifadhi\Contracts\Entity\AreaInterface: Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest
```

Both bundles are in the core, so both answers arrive with it.

**The corollary is the test of whether the rule is being followed: a bare installation reaches
`doctrine:migrations:migrate` with zero doctrine edits.** A hand-step is for a decision only the
installation can make, and neither of these is one. The cost of getting it wrong is real, because a
missing resolution fails a long way from its cause: the container compiles, the kernel boots, and
only the metadata walk stops.

So if YOUR module publishes a contract of this kind, ask which package will know the answer. If it
is a module you also ship, that module prepends it (`prependExtension()`, and mind the
`prepend: true` flag — `extension()` appends by default even from inside `prependExtension()`, which
would overrule the installation instead of yielding to it). If the answer is genuinely the
installation's alone, then **say so in your README and your recipe**, ship the block as a comment,
and **expect the schema, not the boot, to be what stops** without it — the container compiles and
the kernel boots on an unresolved interface, but anything that walks the metadata
(`doctrine:schema:create`, `doctrine:migrations:diff`) stops with
`Class '…Interface' does not exist`.

**Disagreeing is one block, merged not appended**: `config/packages/doctrine.yaml` already opens
with `doctrine:`, and a second `doctrine:` key in one file is not valid YAML — the line goes under
the existing `orm:`, and `resolve_target_entities` is one map that every contract of this kind
shares, so an installation that overrides both writes them together.

**A relation is not always the right answer.** Where the record is a UI scrap rather than a
document — a saved layout, a dismissed hint — hold the person's `getUuidString()` in a plain
column instead, so removing an account is never blocked by one. The rule is the same one that
governs areas: a relation when the row is *about* the person, a stored uuid when the row merely
*belongs* to them.

**And it is not the security user.** `Symfony\Component\Security\Core\User\UserInterface` answers
"who is signed in" and still comes from the token storage; this one answers "who is this record
about". The short names collide, so alias one where a class needs both.

The contract asks seven questions — `getId()`, `getUuidString()`, `getEmail()`, `getFirstName()`,
`getLastName()`, `getFullName()`, `getRangerCode()` — and deliberately no more. It is not the
account class with the word `interface` after it: passwords, tokens, roles and the position a
person holds are the account owner's business. If your module needs something the seven do not
answer, that is an argument to make for widening the contract, not a reason to reach past it.

### composer.json

```json
# composer.json
{
    "name": "your-vendor/sightings-module",
    "type": "symfony-bundle",
    "require": {
        "php": ">=8.4",
        "symfony/config": "^7.3 || ^8.0",
        "symfony/dependency-injection": "^7.3 || ^8.0",
        "symfony/framework-bundle": "^7.3 || ^8.0",
        "symfony/http-kernel": "^7.3 || ^8.0",
        "uhifadhi/uhifadhi": "^1.0"
    },
    "autoload": { "psr-4": { "YourVendor\\Sightings\\": "src/" } },
    "autoload-dev": { "psr-4": { "YourVendor\\Sightings\\Tests\\": "tests/" } },
    "scripts": {
        "cs:check": "php-cs-fixer fix --dry-run --diff",
        "phpstan": "phpstan analyse --no-progress --memory-limit=1G",
        "test": "phpunit",
        "check": ["@cs:check", "@phpstan", "@test"]
    }
}
```

`composer check` is the whole standard: style, static analysis at PHPStan **max**, tests. If your
CI runs one command, run that one.

Put anything you only need for tests in `require-dev`, and be honest about `suggest`: a bundle
that needs `symfony/asset-mapper` to serve its scripts but works without it should say so there
rather than hard-requiring it.

### The bundle class

Extend `AbstractBundle` — it collapses the old Extension + Configuration + Bundle triangle into
one file.

```php
// src/YourVendorSightingsBundle.php
final class YourVendorSightingsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'sightings';

    public function configure(DefinitionConfigurator $definition): void
    {
        SightingsConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $c, ContainerBuilder $b): void
    {
        // zero-config: map your own entities, register your asset paths
    }

    public function loadExtension(array $config, ContainerConfigurator $c, ContainerBuilder $b): void
    {
        $c->import('../config/services.php');
        // …config-driven definitions
    }
}
```

**Zero-config persistence.** Map your own entity directory in `prependExtension()` so no host ever
writes a `doctrine.orm.mappings` block for your tables:

```php
// src/YourVendorSightingsBundle.php
if ($builder->hasExtension('doctrine')) {
    $container->extension('doctrine', ['orm' => ['mappings' => [
        'YourVendorSightings' => [
            'type' => 'attribute',
            'dir' => __DIR__.'/Entity',
            'prefix' => 'YourVendor\\Sightings\\Entity',
            'is_bundle' => false,
        ],
    ]]]);
}
```

### Explicit DI, always

The [bundle best practices](https://symfony.com/doc/current/bundles/best_practices.html)
are not advice here, they are the rule:

> Services should not use autowiring or autoconfiguration. Instead, all services should be defined
> explicitly.

> If the bundle defines services, they must be prefixed with the bundle alias.

So `config/services.php` — **PHP, not YAML**, because a reusable bundle must not force
`symfony/yaml` onto its hosts, and FQCN references stay refactor-safe and PHPStan-checked:

```php
// config/services.php
namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('sightings.observation_repository', ObservationRepository::class)
        ->args([service('doctrine')]);
};
```

Keep **static** wiring in that file and **config-driven** wiring in `loadExtension()`, where the
processed configuration is in hand.

Two consequences of not being autoconfigured that bite everyone once:

- **Tags do not apply themselves.** The host calls `registerForAutoconfiguration()` for the
  platform's contribution interfaces, which only fires for autoconfigured services. Your services
  are not. Every tag —
  `uhifadhi.module` included — is written by hand in `loadExtension()`.
- **Controllers need no base class**, but their service id must be the FQCN and the service must
  be `->public()`, because that is how the router resolves a controller named by `#[Route]`.

### The config tree

Put it in its own class with a **static** `define()` so it is testable with a plain `Processor`
and shared verbatim by `configure()`:

```php
// src/DependencyInjection/SightingsConfiguration.php
final class SightingsConfiguration
{
    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The sightings root node must be an array node.');
        }

        $root->children()
            ->scalarNode('module_category')->defaultValue('biodiversity')->cannotBeEmpty()->end()
            ->booleanNode('dev_tools')->defaultFalse()->end()
        ->end();
    }
}
```

Rules that have earned themselves:

- **Leave the tree closed.** An invented key must fail loudly. Never `ignoreExtraKeys()`.
- **Default to a description, not a vendor.** If a value names a third party, ask whether the
  default could be true of every deployment instead.
- **Do not `validate()` a node that normally holds an env placeholder.** `%env(...)%` has no value
  at compile time. Validate the values a human types (a url template), and handle a missing secret
  honestly at runtime instead.
- **Reading your own config during `prepend()`** is possible but not handed to you: build the tree
  and run `new Processor()->process($tree->buildTree(), $builder->getExtensionConfig($alias))`.

---

## 3. Registering with the registry

A module declares itself to the host by implementing `ModuleProviderInterface` and tagging the
service `uhifadhi.module`. That is the entire registration protocol — no host code changes, no
central list to edit.

```php
// src/Module/SightingsModuleProvider.php
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

final class SightingsModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait; // defaults for everything optional

    public function __construct(private readonly string $category) {}

    public function slug(): string        { return 'sightings'; }
    public function name(): string        { return 'Sightings'; }
    public function category(): string    { return $this->category; }
    public function icon(): string        { return 'binoculars'; }  // a Lucide name
    public function entryRoute(): ?string { return 'sightings_area'; }
}
```

And in `loadExtension()`:

```php
// src/YourVendorSightingsBundle.php
$services->set('sightings.module_provider', SightingsModuleProvider::class)
    ->args([$category])
    ->tag('uhifadhi.module');
```

### One bundle, one module

By convention a bundle provides exactly **one** module, named after itself. Whatever lives *inside*
the module — Sightings' surveys, its species list, its exports — is the module's own concern and
never appears in this contract. If you find yourself wanting two providers, you probably want two
bundles.

### The catalogue and per-area install

RegistryBundle keeps a catalogue of modules and, separately, a per-area record of which are switched
on. **One command fills both: `bin/console registry:sync`**, typed after `doctrine:migrations:migrate`
and before `cache:warmup`, on every install and every upgrade. It prints what it did — the modules
added, kept and retired — and exits non-zero, naming the migration step, when it is typed before the
registry's tables exist. **A web request reconciles nothing** — it opens the registry no connection
on its own account, so an installation that has migrated nothing still answers a liveness probe on a
route that reads no database.

The sync reads every tagged provider and upserts a catalogue row by `slug()`; then it backfills each
area with any module it does not yet have. It is idempotent and **create-only** for the per-area
rows: running it again never touches an area's existing on/off state or ordering, and it never
deletes the rows of a module that has been uninstalled — an admin's decision outlives your package.
It is also safe on a fresh install where the registry tables do not exist yet, which is what lets any
console command be run before the first migration.

Two fields the host coerces rather than trusts: `category()` and `status()` are matched against the
host's own enums, and anything unrecognised falls back to a safe default. A typo in your module
cannot break the sync for everyone else — but it also will not be reported to you, so check the
tile.

### Two tiers: infrastructure and capability

The first question about a new module is which **tier** it is, because the tiers are registered
differently.

| | Registers a provider? | In the catalogue / grid / ledger? | Per-area toggle? | For |
|---|---|---|---|---|
| **Capability** (patrol, incident) | yes — tags `uhifadhi.module` | yes | yes — an admin switches it on | a capability an area may not want |
| **Infrastructure** (storage) | **no** | **no** | **no** — installed means on, everywhere | machinery a screen already relies on |

**The not-uhifadhi test decides the tier.** If a deployment without your module is still
recognisably the product — poorer, but the product — it is a **capability** module: it takes a
catalogue tile, an admin governs it per area, and it belongs in the grid. If its absence makes the
installation *not uhifadhi* — not "fewer features" but *broken screens* — it is **infrastructure**.
Almost everything is a capability module. (The core's five bundles are the platform itself, a tier
above modules and not subject to this question at all: maps are the reason to say so out loud, since
patrol plates, incident plates, the area overview and the zones editor all draw with AtlasBundle's
assets and none of them could ask an admin to switch it on first.)

**Infrastructure is guaranteed by the composer graph, not by a ledger.** An infrastructure module
contributes **no** `uhifadhi.module` provider, so the registry never learns it exists: it appears in
no catalogue, no per-area grid and no `area_module` row. It is present because the project template
— or a module that needs it — *requires* it. There is no per-area state to sync and nothing to
toggle, which is the honest shape for something whose absence breaks screens: "on by default in a
ledger" would imply an off that must never happen.

- **Deployment level.** Infrastructure modules are in the requires graph, so they are present in
  every installation by definition. Nobody edits them out; wanting to remove one is not a
  configuration need, it is evidence the module was misclassified as infrastructure.
- **Area level.** There is none. Infrastructure is not per-area, so there is no Customize toggle,
  no parked state and no route to gate for it.

#### `base()` — for capability modules only

`base()` is a nuance *within the capability tier*: it decides a capability module's **initial
per-area state**.

| | `base()` | Seeded per area as | For |
|---|---|---|---|
| Installable (default) | `false` | parked — an admin switches it on | a capability an area may not want |
| Ships on | `true` | active | a capability worth defaulting on, but still per-area |

Reach for `base()` only when your module *is* a capability (it has a provider, a tile and a
per-area life) and you want it synced active rather than parked. It is **not** how infrastructure is
expressed — infrastructure has no provider to call `base()` on. The word is **base** rather than
"core" because "core" would name importance instead of the sync default it actually controls, and
because the platform package at the centre already carries that word.

### Parking closes your routes

**Where an area has parked your module, every page you ship answers 404 there.** You write no
check for it and you cannot forget it: RegistryBundle owns the per-area ledger, so RegistryBundle
enforces it, in one `kernel.request` listener that runs after the router and before any controller.

It is **404, not 403**, and the difference is the product's, not the framework's. A 403 confirms
the page exists and is being kept from the caller — true about a permission, false about parking.
A parked module is not withheld; the area is not running it, which is what the area's own screens
already say with the module sitting in the shop rather than the sub-nav.

**Say which module a route belongs to.** One class-level default per controller:

```php
#[Route(defaults: ['_uhifadhi_module' => 'sightings'])]
final class SightingsController
{
    #[Route('/areas/{uuid}/modules/sightings', name: 'sightings_dashboard', /* … */)]
    public function dashboard(/* … */): Response { /* … */ }
}
```

The string is published as `RegistryBundle::MODULE_ROUTE_DEFAULT`. Your bundle requires the core, so
the constant is always there to import — and a route attribute is one of the few places where
importing a class constant is a real load-time dependency, so it is worth knowing that here it is a
dependency you already have. Spelling the string out is also correct; if you do, put it in one
constant of your own and assert the two agree in a test.

If your route carries the area's uuid in a parameter not called `uuid`, name it:

```php
#[Route(defaults: ['_uhifadhi_module' => 'sightings', '_uhifadhi_module_area' => 'place'])]
```

**Without the marker** you are not exempt — you are guessed at. A route on the fleet's
`/areas/{uuid}/modules/{slug}/…` shape is recognised when the segment names a module in the
catalogue, which covers a module whose URL segment happens to equal its slug and covers nothing
else. Accident is not a contract: write the line.

**What this does to your test fixtures.** An area written straight into the database has no row in
the per-area ledger, so it is running nothing, so every page you render in a functional test
answers 404 — correctly, and uselessly. Do what an installation does, once per fixture area:

```php
$areaModules->install($area, YourModuleProvider::SLUG);
// Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService
```

That is the whole fixture step, because the half an operator would have run by hand has already
happened: your test kernel boots against a cold cache, the cache warm-up runs, and the registry
sync puts your provider in the catalogue as that command ends. What is left is the
per-area decision, which no warm-up will ever make for you — it is exactly the admin's choice the
sync refuses to overrule.

Give it to the "other area" fixtures too — the ones a cross-area test uses to prove your entity
cannot be read from next door. Those tests assert 404, and the 404 has to keep meaning *that row
is not in this area* rather than *this area has no such module*.

### Concerns: what there is to have a permission about

A module **declares** concerns; it never grants anything. A concern is a thing to act on, with the
verbs your module actually enforces on it and the scopes it offers:

```php
// src/Access/SightingsConcerns.php
final readonly class SightingsConcerns implements ConcernSourceInterface
{
    public const string SIGHTINGS = 'sightings';

    public function declaredBy(): string
    {
        return 'Sightings';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::SIGHTINGS,
            label: 'Sightings',
            description: 'What was seen and where: filing a sighting from the field, and confirming somebody else’s.',
            verbs: [Verb::Read, Verb::Record, Verb::Manage],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
            moduleSlug: SightingsModuleProvider::SLUG,
        );
    }
}
```

Tag it by hand — a reusable bundle is not autoconfigured:

```php
$services->set('sightings.access.concerns', SightingsConcerns::class)
    ->tag(ConcernSourceInterface::TAG);
```

The installation folds declarations into its positions matrix so an administrator can assign the
pairs, and they disappear when the module is uninstalled. A declaration carries no role and no
default holders — **installing a module must never hand an existing person a new power**.
Enforcement stays clean in both directions: you gate on `<concern>.<verb>` at your own routes, the
installation alone decides who holds it.

#### The description is a sentence, and it is required

An administrator opening the matrix is being asked to hand a power over, and `Sightings · Manage`
does not tell them what they are handing over. An optional sentence is one most modules would skip,
and a matrix where half the rows explain themselves is a matrix people stop reading. So a concern
that has not thought about the sentence does not compile, and a blank one is refused at
construction.

Write it about the holder, in the product's voice — "Confirm or reject somebody else's sighting",
not "Grants verify access". The reader is deciding whether to give it to a colleague.

#### Spell the pair once, from the declaration

A bare string repeated between a declaration and the routes that check it is a typo waiting to
happen, so compose the pair from the concern key and the verb:

```php
public const string MANAGE = SightingsConcerns::SIGHTINGS.'.'.Verb::Manage->value;

#[IsGranted(self::MANAGE, subject: 'area')]
```

Namespace the concern key by your module so two modules cannot collide on one, and run
`AccessConformanceTestCase` over the declaration in your own suite — it holds the rules the core
holds itself to.

### Rendering: generic page or your own

`entryRoute()` returning `null` means the host renders your module through its generic module page.
Returning a route name means you own your pages, and the host links to it with the area's uuid:
`path(entryRoute(), {uuid: area.uuid})`. Your route therefore has to accept a `uuid` parameter and
resolve the area itself.

### How your screens become sidebar rows

**Registering with the registry does not put a row in the sidebar, and it is not supposed to.** The
registry is a catalogue and a per-area ledger; the sidebar is a drawing. Nothing in the platform
maps one to the other automatically, and a module that assumed otherwise ships screens nobody can
find — which is the same as not shipping them.

There are **two** ways a module becomes reachable, and which one you want is decided by a single
question: **is this capability an area's, or the installation's?**

| | A per-area capability | An installation-wide screen |
| --- | --- | --- |
| Examples | sightings, patrols, incidents | the team roster, the file library |
| You implement | `ModuleProviderInterface` | ShellBundle's `NavigationSourceInterface` |
| Tagged | `uhifadhi.module` | `shell.nav_section` |
| You appear in | the catalogue, and each area's module grid | the sidebar, as one row |
| Linked by | `entryRoute()` + the area's uuid | the url you generate |
| Who draws the nav row | **the host** | **you** |

**For a per-area module you write no navigation code at all.** The host implements a
`NavigationSourceInterface` of its own and folds four things it alone has — its areas, the viewer,
the permission voters, and the registry's per-area ledger — into "these rows, in this order". Your
module reaching an area's sidebar is a consequence of being switched on for that area, and that is
the host's reading to make. Registering with the registry is the whole of your side.

**An installation-wide screen is the other case**, and the shell's own contract names it: "the rare
platform-wide row that belongs to nobody's area". Do **not** reach for `ModuleProviderInterface`
here. That contract is per-area by construction — the registry's ledger is an area-by-module table —
so an org-wide capability registered through it becomes something an admin has to switch on in each
area separately, and a roster that exists four times is not a roster. Implement the shell's
interface instead:

```php
// src/Shell/SightingsNavigation.php  (org-wide screens only)
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Contracts\Shell\NavGroup;

final readonly class SightingsNavigation implements NavigationSourceInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
    ) {}

    public function sections(): iterable
    {
        // NO TOKEN, NO QUESTION. A shell renders where a firewall does not
        // reach, and asking the checker with no token THROWS rather than
        // answering false.
        if (null === $this->tokens->getToken()) {
            return;
        }

        // GATING IS YOURS. The shell holds no authorization service.
        if (!$this->authorization->isGranted('sightings.view')) {
            return;
        }

        // ROUTE-TOLERANT: the addresses are mounted by the APPLICATION, in a
        // file it owns and may delete. No route, no row.
        try {
            $url = $this->urls->generate('sightings_index');
        } catch (RouteNotFoundException) {
            return;
        }

        // THE GROUP IS ONE OF FOUR, AND IT IS A CONSTANT. Observatory is what
        // the organization watches, Organization what it is and holds, System
        // what the system raises to you, Settings configuration, last. A label
        // that is not one of them is refused.
        yield new NavSection(NavGroup::OBSERVATORY, [
            new NavItem(
                label: 'Sightings',
                url: $url,
                icon: 'sightings:binoculars',
                current: $this->viewerIsHere($url),
            ),
        ], position: 10);
    }
}
```

And in your `config/services.php`:

```php
// ShellBundle ships in the core, which your bundle requires, so the interface
// is always there and no interface_exists() guard is needed.
$services->set('sightings.navigation', SightingsNavigation::class)
    ->args([
        service('router'),
        service('security.token_storage'),
        service('security.authorization_checker'),
        service('request_stack'),
    ])
    ->tag('shell.nav_section');
```

**The tag goes on by hand.** A reusable bundle's services are not autoconfigured, and an untagged
nav source is one the shell never asks — which looks precisely like a module that contributes
nothing.

Five rules the shell enforces or expects, and each exists because of a specific way a sidebar goes
wrong:

- **Gating is yours, and a withheld row is ABSENT, never hidden.** The shell holds no
  authorization service and calls `is_granted` on nothing. There is no "hidden" flag, because a
  hidden row leaks its existence to whoever reads the HTML. Gate on the same permission the screens
  behind the row are gated on, or the sidebar offers a door that closes in somebody's face.
- **Build the answer in the method, never in the constructor.** Sources are iterated on every
  render and nothing between them and the sidebar caches. That is what makes revoking a permission
  or switching a module off take its row away the same day rather than the next deploy.
- **Never throw.** Your exception is not your page failing — it is the sidebar failing, on every
  page of the installation, including ones that have nothing to do with your module. The two live
  ways to throw are the two guarded above: no security token, and a route the application
  unmounted.
- **Exactly one row is current among siblings, or the shell refuses.** Zero is allowed and always
  will be. A lit row inside a lit branch is one path drawn, not a contradiction.
- **A row with no destination renders inert, not absent.** `url: null` gives a visible, dimmed,
  unclickable row carrying its reason in `hint` — for a surface whose route has not merged yet. It
  is the opposite rule from gating, and both are deliberate: "planned" is worth saying, "not yours"
  is not.

**Where this interface lives, and when it moves.** `NavigationSourceInterface` is published by
ShellBundle, not by the contracts package, because it is a promise between the shell and the
application mounting it. A module implementing it is the case that argues for **hoisting it into
the contracts** — by this repository's own rule (see [what-is-a-contract.md](what-is-a-contract.md))
a promise modules and the platform exchange belongs there. It has not been hoisted. Naming a
ShellBundle class costs your module nothing today, because the shell is in the core you already
require; what a hoist would buy is the guarantee that the signature is frozen for you, which is what
the contracts package is for and what a bundle's own interface never promises.

---

## 4. Shipping importmap assets from a bundle

This is the hard chapter. Everything in it comes from moving three shared scripts out of the host
application and into a bundle without a single importer changing.

### The two directories, and what each is for

| Directory | Registered | Logical path | Use for |
|---|---|---|---|
| `public/` | automatically | `bundles/yourvendorsightings/…` | stylesheets, images, **classic `<script>` files** |
| `assets/` | you prepend it | `@your-vendor/sightings-module/…` | anything imported as an ES module |

`public/` needs no configuration at all: AssetMapper registers every bundle's `public/` directory
under `bundles/<lowercased bundle class name minus "Bundle">` and content-versions what is in it.
No `assets:install`, no symlink. Relative `url()`s inside a CSS file there are rewritten, so a
vendor stylesheet's own images come along for free.

`assets/` is yours to declare, in `prependExtension()`, the way every `symfony/ux` bundle does
([creating a UX bundle](https://symfony.com/doc/current/frontend/create_ux_bundle.html),
`vendor/symfony/ux-map/src/UXMapBundle.php`):

```php
// src/YourVendorSightingsBundle.php
if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
    $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [
        \dirname(__DIR__).'/assets' => '@your-vendor/sightings-module',
    ]]]);
}
```

**Prepend it**, on the builder: `$container->extension()` appends even inside `prependExtension()`,
and an appended path overrules the installation's own `framework` config instead of deferring to it.

Guard it on **both** conditions. `hasExtension('framework')` because you may be in a kernel that
has none; `interface_exists(AssetMapperInterface::class)` because AssetMapper is optional and a
host may have installed you for your PHP alone.

### The gotcha: a bundle cannot add importmap entries

There is no extension point. `importmap.php` is read as one file, and nothing a bundle does can
contribute an entry to it. So the contract splits in two:

- the **directory** is yours — you guarantee `@your-vendor/sightings-module/plate.js` exists and keeps
  working;
- the **import names** are three lines in the host's `importmap.php`:

```php
// importmap.php (the host application)
'sightings/plate' => ['path' => '@your-vendor/sightings-module/plate.js'],
```

Document those lines in your README, and ship them in a Flex recipe (chapter 11) so an install
writes them. The recipe hides the join; it does not remove it.

### Name your exports as bare specifiers, not paths

This is the single most valuable habit in the chapter. The platform's map scripts are imported as
`uhifadhi/basemaps`, never as `./assets/…`, and that is the reason a script can be relocated —
between a bundle's `assets/` directory, a host's, or another package entirely — with **every
importer in every repository unchanged**. Only the right-hand side of the importmap entry moves.
Import by path instead and the same relocation touches every map controller in the product.

Write a test for it. A one-line sweep over your controllers asserting that none of them contains
your own namespace string is enough to keep the property true.

### The names the platform already publishes

Four bare specifiers arrive in an installation's `importmap.php` without you writing anything: the
core declares them in its own `assets/package.json`, under `symfony.importmap`, and **Flex writes
them on `composer update`**.

```php
// importmap.php (the host application) — written by Flex, not by you
'uhifadhi/widgets'    => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/assets/widgets.js'],
'uhifadhi/basemaps'   => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/basemaps.js'],
'uhifadhi/boundary'   => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/boundary.js'],
'uhifadhi/map-chrome' => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/chrome.js'],
```

**Your widget library page imports `uhifadhi/widgets` and nothing else.**

```js
import 'uhifadhi/widgets';
```

The module arms itself against the library root your page rendered; there is no init call to write
and no Stimulus controller to register. An installation older than the entry adds the line by hand.
A page that imports the name in an installation that has not got the line answers with
`Uncaught TypeError: Failed to resolve module specifier "uhifadhi/widgets"` and a library whose
cards draw correctly and do nothing when clicked — check `importmap.php` first when you see that.

### Stylesheets and classic scripts stay in `public/`

A stylesheet is not an importmap module, and neither is a library that publishes a global instead
of exporting anything. Keep both in `public/`, which AssetMapper serves and versions under
`bundles/<bundlename>/` with no configuration, and link them from a layout. Publish the path as a
**class constant** rather than expecting hosts to type it:

```php
// src/YourVendorSightingsBundle.php
public const string STYLESHEET = 'bundles/yourvendorsightings/sightings.css';
```

```twig
{# templates/sightings/_base.html.twig #}
<link rel="stylesheet" href="{{ asset(constant('YourVendor\\Sightings\\YourVendorSightingsBundle::STYLESHEET')) }}">
```

A path written in a host layout *and* two other bundles' base templates is a path that eventually
differs by one character in one of them.

Do not ship a JavaScript library this way. A library a controller of yours needs — Leaflet,
Chart.js — belongs in the **installation's importmap**, once, and the packages that need it declare
it. Two copies of a library are two module namespaces, and objects built against one are refused by
the other.

### Publishing configuration to the browser

Where a script needs a value the server knows — which tile provider, which feature flags — put it
on the `<body>` as one JSON data attribute rendered by a Twig function your bundle registers, and
have the script read it with a sane default when the attribute is absent:

```php
// src/Twig/SightingsExtension.php
new TwigFunction('sightings_attributes', $this->attributes(...), ['is_safe' => ['html']]);
```

Two things this earns you: the value is available before the first render rather than after a
round trip, and one document has exactly one place to read it from, so two components cannot
disagree. Two things to get right: `json_encode()` does **not** escape `"` by default, so escape
the payload yourself with `htmlspecialchars(..., ENT_QUOTES)` before marking it `is_safe` — and
emit **only** the keys the current configuration actually needs, so a secret is never printed on a
page that was not going to use it.

### Asset-idiom tests

Some defects live in JavaScript that no PHP test can execute — a cache that only remembers success,
a script that reaches for a vendor endpoint directly. If you have no JS runner, read the shipped
asset as **text** and assert on it. It is cruder than a unit test and it catches the real thing.

Keep those tests **with the asset**. When a script moves to another repository its test moves with
it, because a defect of that file must fail where someone would edit it — not two repositories away.

---

## 5. Templates and Twig namespaces

A bundle's `templates/` directory is registered automatically under a namespace derived from the
bundle class: `@YourVendorSightings/…`. Nothing to configure.

Give your bundle its **own base template** that extends the host's layout, rather than having every
page extend `layout.html.twig` directly:

```twig
{# templates/base.html.twig #}
{% extends 'layout.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset(constant('YourVendor\\Sightings\\YourVendorSightingsBundle::STYLESHEET')) }}">
{% endblock %}
```

That way your stylesheet loads only where your module renders, and the host's stylesheet never
mentions you.

A rule worth inheriting: **the host's stylesheet is loaded on your pages too.** A bare one-word class in either sheet (`.who`, `.day`, `.feed`) will eventually
collide across the repository boundary and the cascade decides who wins by load order. Qualify your
selectors with an element or a prefix, and never write a second copy of a vocabulary the host
already defines — two copies loaded in either order render differently, which is exactly what the
"same layer renders identically everywhere" rule forbids.

If your module contributes markup to a host-rendered surface, expose the stylesheet path through
`ContributesStylesheetInterface::stylesheet()` so the host can link the same sheet from a page you
do not render.

### The shared design system lives in shell, not in your module

The rule above — *never write a second copy of a vocabulary the host defines* — has a concrete home
and a concrete enforcer, both of which exist because four modules shipped the same bug
independently.

**The shared vocabulary + design tokens live in ONE stylesheet, shipped by `ShellBundle`
and loaded globally on every page.** That sheet owns the cross-module design system: the filter/chip
chrome (`.mchip` and its states + hue dots, the `.i-dd*` dropdown chrome), the meta-row vocabulary
(`.rln`), chart text classes (`.anno`/`.annoS`, axis/grid), the card/tab primitives, and the design
tokens (`--ink`/`--mut`/`--fog`/`--line`/`--ok`/`--warn`/`--fail`, …). **Your module uses these
classes and tokens; it does not define them.** Your own `public/*.css` carries only the classes that
are genuinely yours (prefixed, per the collision rule above).

Why this bites: **a project created from the skeleton ships no global stylesheet of its own** — it
serves ShellBundle's. So a module that *restates* `.mchip`, or references a `var(--fail)` that
nothing ships, **falls back to browser defaults on the real host**: black text, default `#efefef`
buttons, dashed-black borders. It looks fine in the module's own standalone tests (which may load
the design's sheet) and broken in the park. Do not restate the vocabulary to "fix" it locally —
that just moves the drift; use the shell's, and if something you need is missing from the shell's
sheet, add it *there*.

### The vocabulary test (a used-but-not-shipped class fails CI)

Every module runs a **`StylesheetVocabularyTest`** in `composer check`. It scans your templates for
every CSS class and every `var(--token)` reference and asserts each is **defined somewhere in the CSS
chain your pages actually load** — the shell design-system sheet, your module's own sheet, and any
linked dependency's sheet. A class or token that is *used but shipped by nobody* **fails the build**,
before it ever reaches the park. This is the regression test for the fallback bug above: face it
once, and it cannot recur silently. Include the shared test in your suite (it is part of the
[per-module conformance checklist](module-development.md)); do not hand-roll a copy.

### UI idioms that must match across modules

A module's chrome is not a free canvas — a person moving between Patrols, Incidents and Files should
meet the *same* controls in the *same* clothes. Three that are settled and must be identical
everywhere:

- **The widget-library entry point.** Every module dashboard reaches its widget library through one
  standard link — **`<a class="tgl w-act" …>{{ ux_icon('shell:layout-grid') }} Widget library</a>`**.
  Not "Customize widgets", not a plus icon, not a module-private action class. The label is
  "Widget library", the icon is `shell:layout-grid`, the class is `tgl w-act`.
- **The way back off a record.** Every screen that opens one thing out of a list returns to that list
  through the shell's pill — **`<a class="backbtn" href="…">{{ ux_icon('shell:chevron-left') }} All
  modules</a>`** — at the top of the page body, never as a page action and never as a `.tgl`. The
  label names the list you are going back to ("All patrols", "All incidents"); the icon is
  `shell:chevron-left`; the class is `backbtn`, and it carries its own 16px of air below it.
- **Filters are grouped dropdowns.** Filter bars use the grouped-**dropdown** pattern (a closed chip
  that opens a floating panel of options with live counts — the incidents filter is the reference),
  not a sprawling row of always-expanded chips.
- **An instant is a `<time>` element, never formatted text.** Every printed instant on every screen
  is read in the **viewer's** timezone, and the frame is what does it: print the UTC fallback inside
  `<time datetime="{{ t|date('c') }}" data-localtime-format="stamp">…</time>` and the shell rewrites
  the text on every page, including nodes a swap or a clone inserts later. The shapes are the
  product's own compact stamps — `stamp` ("12 sep · 13:49"), `daystamp`, `clock`, `clocks`, `day`,
  `daylong` — so a design's monospace cell is no longer a reason to format server-side, which is what
  every module used to do and why every reader outside the server's zone read the time wrong. Two
  things are deliberately left alone: a day key or calendar date (a date-only `datetime`), and a
  **relative** reading — "6 min ago" is a duration, right in every zone at once, so it is written as a
  plain `<span title="{{ t|date('c') }}">6 min ago</span>` and never as a `<time>`. Never format an
  instant for display any other way. See
  [theming.md — a time reads in the reader's zone](../../Bundle/ShellBundle/docs/theming.md#a-time-reads-in-the-readers-zone).

### Icons: one prefix per package

**Every drawing is a locked icon file reached by name — never an inline `<svg>` in an app template,
never an emoji.** (The static design files keep inline SVGs; the app does not.) An inline SVG in a
module template is drift.

An icon set maps a prefix to a **single directory**, and a prefix mapped that way is answered *only*
from that directory — the lookup never falls back to the application's `icon_dir`. Registering a
prefix therefore takes that word away from everybody else in the installation, so each package
answers for its own alias and no other.

- **The core draws with `shell:`** and registers nothing else. Those glyphs are shipped by
  `ShellBundle` and are yours to reuse: a module renders inside the shell, so `shell:plus` on a
  module page is the same mark as `shell:plus` on a core page.
- **Your module registers its own alias** and draws with `<alias>:…`:

```php
// src/YourVendorSightingsBundle.php
public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    if ($builder->hasExtension('ux_icons')) {
        $container->extension('ux_icons', [
            'icon_sets' => [
                'sightings' => ['path' => __DIR__.'/assets/icons/sightings'],
            ],
        ]);
    }
}
```

  and then `{{ ux_icon('sightings:binoculars') }}`.

- **A glyph from a public icon library is copied into your own directory**, under whatever name you
  draw it by. Import it, commit the file, draw it under your alias. Never reference a public
  library's prefix — `lucide:` included — from a bundle: that prefix belongs to the installation,
  which may answer it with its own artwork or not answer it at all, and on a deployment with
  fetching disabled an unanswered name is an empty box.
- **An application** is the one place a public prefix is legitimately answered, from its own
  `assets/icons/…`.

<https://symfony.com/bundles/ux-icons/current/index.html#full-configuration>

---

## 6. Using the atlas: maps, charts and calendars

The **atlas** is the component library every module's visuals are drawn with. It is core
infrastructure — installed means on, no catalogue tile, no per-area switch — and it exists so that
a map in your module and a map in somebody else's are the same instrument pointed at different
data.

The rule is short: **a module writes no visual JavaScript.** You state what is on the visual in
PHP and call one Twig function. You do not create a Leaflet map, you do not draw chrome, you do not
style a plate, and you do not ship a Stimulus controller for any of it. If a visual cannot say what
you need, the gap is in the atlas and belongs there — not in a controller of your own that quietly
looks different from every other one in the product.

Today the atlas ships **maps**. **Charts** and **calendars** are the same shape and are coming;
their APIs are not written here because they are not written yet. When they land they will arrive
as a builder, a model and a `render_*()` function, and this section will name them.

### A map, end to end

The map is built in a service, not in a template and not in a controller.

```php
// src/Service/SightingsMap.php
namespace YourVendor\Sightings\Service;

use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\GeoJsonLayer;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;

final readonly class SightingsMap
{
    public function __construct(private MapBuilderInterface $maps)
    {
    }

    /**
     * @param array<string, mixed> $collection a GeoJSON FeatureCollection
     */
    public function forArea(array $collection, int $count): AtlasMap
    {
        return $this->maps->createMap()->addLayer(new GeoJsonLayer(
            id: 'sightings.recent',
            label: 'This week',
            features: $collection,
            swatch: '#E5C15A',
            shape: LayerShape::Point,
            count: $count,
            group: 'Sightings',
        ));
    }
}
```

Wired explicitly, like everything else your bundle defines:

```php
// config/services.php
$services->set('sightings.map', SightingsMap::class)
    ->args([service(MapBuilderInterface::class)]);
```

Handed to the template by the screen:

```php
// src/Controller/SightingsController.php
return new Response($this->twig->render('@Sightings/sightings/plate.html.twig', [
    'map' => $this->map->forArea($collection, $count),
]));
```

And drawn:

```twig
{# templates/sightings/plate.html.twig #}
{{ render_map(map, {'role': 'img', 'aria-label': 'Sightings this week'}) }}
```

That is the whole of it. What arrives on the page is the imagery the deployment configured, the
control stack every map in the product wears, a legend with a switch per layer, and a fullscreen
that works — because the plate owns its own layout and your card cannot break it.

### The pieces

| You state | Class | What the atlas does with it |
|---|---|---|
| a body of GeoJSON | `Model\GeoJsonLayer` | draws it in your colour and shape, with a legend row that switches it |
| what a feature looks like | `Model\LayerStyle` + `Model\StyleRule` | evaluates the rules against each feature's own properties and draws it |
| what a feature says | a layer's `tooltip` property name, `Model\FeaturePopup` | binds the hover label and the click popup, writing and escaping the markup |
| the ground it is about | `Model\Boundary` | the platform's one outline treatment, and the scrim outside it |
| a colour's meaning | `Model\LegendItem` | one more legend row, a key rather than a switch |
| which grounds to offer | `Model\BaseLayer` | the base-layer menu, and what the plate opens on |
| markers, polygons, lines | UX Map's own classes, via `$map->ux()` | passed straight through to UX Map |

A layer names exactly one source — `features` the server already has, or a `url` the plate fetches
once it is mounted. Naming both, or neither, is refused where you wrote it rather than showing an
empty map to somebody at 07:00.

### What a mark MEANS: styling, declared

The shape settles what a line, a fill and a point look like across the product. What is genuinely
yours is what a mark *means* — hollow for a closed case, a dashed ring for the serious end. You
state it as data, never as a callback:

```php
use Uhifadhi\Bundle\AtlasBundle\Model\FeaturePopup;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerStyle;
use Uhifadhi\Bundle\AtlasBundle\Model\StyleRule;

new GeoJsonLayer(
    id: 'sightings.recent',
    label: 'This week',
    features: $collection,
    shape: LayerShape::Point,
    style: new LayerStyle(radius: 5.5, weight: 1.6, fillOpacity: 0.85),
    rules: [
        StyleRule::when('open', false)->fillOpacity(0.0),                       // hollow when finished
        StyleRule::when('severity', ['high', 'critical'])->dashArray('3 3'),    // dashed at the serious end
    ],
    tooltip: 'summary',                                    // read on hover
    popup: FeaturePopup::of('title', 'href'),              // opened on click
    featureId: 'reference',                                // what a spotlight names it by
);
```

Every statement is a value object serialised into the payload the plate reads — the same shape UX
Map and UX Chart.js use. **No callback crosses the wire**, which is exactly what lets one
controller draw every module's layers. The full vocabulary — stroke, fill, dash, radius, z-index —
is in the atlas's own `docs/components.md`.

### Spotlighting a feature from a list beside the map

A log row that lifts its own track needs no JavaScript from you. The layer declares which property
identifies a feature; the row wears the attribute:

```twig
<a class="row" href="…" data-atlas-highlight="sightings.recent:{{ sighting.reference }}">…</a>
```

The plate wires it by delegation from `document`, raises that feature and pushes its siblings back
while the cursor or the focus is on the row. Publishing your own document event and answering it
in a controller of your own is the old way, and it is exactly the drift this replaced.

### How tall your map is

**A plate is as tall as it says it is, never as tall as the row it sits in.** It carries a real
height off one custom property (`--map-plate-height`, default `min(58vh, 560px)`) and refuses to
stretch. What that property sizes is the MAP — your filter row and the imagery under it; the legend
is drawn below it and adds its own height, so 400px is 400px of map and never 400px minus a legend.
Say your screen's own height by setting the property on the card:

```css
.your-module .case-file-where { --map-plate-height: min(46vh, 440px); }
```

or by handing it to `render_map()`, which lifts any custom property onto the plate:

```twig
{{ render_map(map, {'--map-plate-height': 'min(46vh,440px)', 'role': 'img', 'aria-label': 'Where'}) }}
```

Sizing a plate with `min-height` plus `flex: 1` on your own card is how a map ends up a thousand
pixels tall beside a long column of facts. Don't.

### The stylesheet, and the one thing you do link

A map's styles are the atlas's, in one sheet. Link it wherever a plate renders:

```twig
{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset(constant('Uhifadhi\\Bundle\\AtlasBundle\\AtlasBundle::STYLESHEET')) }}">
{% endblock %}
```

Leaflet needs nothing from you: the map is created by Symfony UX Map's Leaflet bridge, which
imports the one `leaflet` in the installation's importmap and its stylesheet with it. A module that
ships or links a second copy has given the page a second Leaflet namespace, and objects built
against one are refused by the other.

### Filters and the legend

A filter row is markup you own, handed to `render_map()` as its third argument. It renders one row
**above** the map and inside the plate, so it comes along into fullscreen:

```twig
{% set filters %}
    <a class="chip on" href="?since=week">This week</a>
    <a class="chip" href="?since=month">This month</a>
{% endset %}

{{ render_map(map, {'role': 'img', 'aria-label': 'Sightings'}, filters) }}
```

The legend is not markup you own. It is rendered from the layers and legend rows the map states,
sits BELOW the map — floating in the imagery's bottom-right corner only in fullscreen, where there
is imagery to spare — and each row that names a layer is a real switch. Grouping is data: rows that
share a `group` are drawn together under that heading, which is what keeps a plate with four
contributors readable.

### The events, and when you need them

The plate dispatches `atlas:map:connect` (`{map, L, layers}`) and `atlas:map:layer:added`
(`{id, layer}`) on its root element, and UX Map's own `ux:map:*` events fire there too. They exist
for an **installation** that has to extend a plate without forking anything.

A module should not need them. Reaching for them is the signal that the atlas is missing something
— say so, rather than building a second map controller that drifts.

### What this replaces

If you are porting a module that has its own map controller, the whole of it goes: the Leaflet
bootstrap, the base layers, the boundary drawing, the chrome mount, the fullscreen handling, the
legend wiring, the re-fit on resize. What is left is a service that says what is on the map, which
is the only part that was ever yours.

---

## 7. Contributing to the overview

An area's overview is composed from every installed module. Each contribution is a small interface
plus an explicit tag; you can implement any subset, and a module that implements none is perfectly
valid.

| Interface | Tag | Contributes |
|---|---|---|
| `OverviewContributorInterface` | `uhifadhi.overview.widget_provider` | widgets and their render context |
| `Overview\ContributesStylesheetInterface` | (no tag of its own) | the stylesheet your cells are written against |
| `Performance\PerformanceTopicProviderInterface` | `uhifadhi.performance_topic` | a whole topic on the performance page: five figures, its charts and its matrix |
| `Performance\PerformanceGeoProviderInterface` | `uhifadhi.performance_geo` | your figures over the ground — one per area, or per zone of one area |
| `Performance\DepartmentDirectoryInterface` | (ask for it by name) | who the departments are, what they attach, and since when you could have been asked |
| `NowTileProviderInterface` | `uhifadhi.overview.now_tile` | "right now" tiles in the strip |
| `AttentionProviderInterface` | `uhifadhi.overview.attention` | items in the attention list |
| `MapLayerProviderInterface` | `uhifadhi.map.layer` | layers on the area map |
| `PulseProviderInterface` | `uhifadhi.overview.pulse` | events in the activity feed |
| `OverviewCopyProviderInterface` | `uhifadhi.overview.copy` | copy fragments for a named slot |
| `Kpi\DepartmentKpiProviderInterface` | `uhifadhi.department_kpi` | a department's KPI figures |
| `Kpi\ZoneFigureProviderInterface` | `uhifadhi.zone_kpi` | a zone's figures, for every zone of an area at once |
| `Kpi\StationFigureProviderInterface` | `uhifadhi.station_kpi` | a station's headline figure, for every station of an area at once |
| `People\PersonFacetProviderInterface` | `uhifadhi.person_facets` | a person's position and department, for a list somewhere else |
| `People\PersonPostingProviderInterface` | `uhifadhi.person_postings` | where a person works, for their own page |
| `People\PersonRecordCellProviderInterface` | `team.record.cells` | a card on a person's record, after the grants ledger |
| `Area\StationDirectoryInterface` | `uhifadhi.station_directory` | every station and who stands at each, across every area |
| `Storage\FileSourceInterface` | `uhifadhi.file_source` | that this module stores files, and its word for one |

Every one of them starts with `moduleSlug()`, and it must return the same slug your
`ModuleProviderInterface` does: that is how a contribution disappears when an area switches your
module off.

### What your cell is handed, and what dresses it

A contributed cell is rendered with `with_context: false` and **one** map. Half of it is shared —
`area`, `now`, `tiles`, `attention`, `layers`, `legend` — and your own figures are under your own
slug, in `by.<slug>`, exactly what your `context()` returned:

```twig
{# @Patrol/overview/_w_pl_now.html.twig — patrols' own cell #}
<div class="c" data-w="pl_now">
    <span class="tab">Out right now<span class="src">&middot; {{ by.patrols.out }} open</span></span>
</div>
```

Read nothing else. A cell that reaches for a variable the page happens to have is a cell that
breaks when the page is composed differently, and two modules publishing `total` into one flat
context would silently overwrite each other.

**Your cell wears your stylesheet, and you publish it through the seam that already exists.** The
overview links the shell's sheet, the atlas's, the widget grid's and the area's — not yours, which
is why a module built against its own sheet rendered its cell with every class undefined.
`Overview\ContributesStylesheetInterface` is the seam (the same one described under *the host's
stylesheet is loaded on your pages too*, above); implement it beside the contributor and the
surface links your sheet once, after its own, for every area that runs your module:

```php
final readonly class PatrolOverviewContributor implements OverviewContributorInterface, ContributesStylesheetInterface
{
    public function stylesheet(): string
    {
        return 'bundles/uhifadhipatrol/patrols.css';
    }
}
```

It is a separate interface because the area's own contributor has no stylesheet to name, and a
contract that makes it answer a question it has no answer to has started guessing. Your sheet is
linked last, which means you may tune what you own — and only what you own: restating a shell or
area selector wins by load order and drifts every other surface, which the sheet tests catch.

### A card on a person's record

A person's record is the team's page — the position, what it grants, where they are stationed,
the account's history. A module that holds a fact about a person (the handsets they carry, when one
last reported in) draws it as one more card through `People\PersonRecordCellProviderInterface`,
tagged `team.record.cells`. The page renders every card it is handed as the **last cards of the
main column, after the grants ledger**, in the order the container yields the providers.

```php
final readonly class HandsetRecordCell implements PersonRecordCellProviderInterface
{
    public function __construct(private HandsetRepository $handsets, private Security $security, private Environment $twig)
    {
    }

    public function cellFor(string $personUuid): ?string
    {
        // THE CONTRIBUTION GATES ITSELF: nothing to say, or nothing the viewer
        // may read, is null — and null draws nothing.
        if (!$this->security->isGranted('handsets.read')) {
            return null;
        }
        $carried = $this->handsets->findByPerson($personUuid);

        return [] === $carried ? null : $this->twig->render('@Telemetry/person/_cell.html.twig', ['handsets' => $carried]);
    }
}
```

```php
// config/services.php — tagged by hand, because a reusable bundle is not autoconfigured
$services->set('telemetry.record_cell', HandsetRecordCell::class)
    ->args([service(HandsetRepository::class), service('security.helper'), service('twig')])
    ->tag(PersonRecordCellProviderInterface::TAG);
```

The card is your **whole** markup — one house card, `.c` with its `.tab`, written against the
shell's sheet and your own — and the page adds nothing around it and reads nothing out of it. It
does not ask whether the viewer may see your fact: a card that said "you may not see this" would be
a card about the viewer, not about the person, so the gate is yours and the answer is null.

### Publishing a topic on the performance page

The organization's performance page is not a table of your columns against
everybody's departments — that board lied about half its cells, because a
department that never attached your module is not a row of empty ones. Your
module publishes **a topic**: five headline figures, two or three charts, and a
matrix of only the departments that read you.

```php
final readonly class PatrolPerformanceTopic implements PerformanceTopicProviderInterface
{
    public function moduleSlug(): string { return 'patrols'; }   // the same slug as everywhere
    public function key(): string        { return 'patrols'; }   // its own address in the URL
    public function title(): string      { return 'Patrols'; }

    public function kpis(PerformanceScope $scope, FigurePeriod $period): array { /* exactly five */ }
    public function charts(PerformanceScope $scope, FigurePeriod $period): array { /* two or three */ }
    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix { /* your rows */ }
}
```

Adding a module adds a topic and touches nothing else: no host column changes,
no department row changes, no shared list to edit. The host's own topics —
Staffing, Goals, Attention — are producers of exactly this shape and are
rendered first; module topics follow **in the order the organization arranged
its modules**, never alphabetically.

**Your rows come from the directory, not from a query.** Enumerating departments
means reading the team bundle's entities and the registry's area × module
ledger, across two packages you do not depend on. Ask
`Performance\DepartmentDirectoryInterface::forScope()` instead: one read
answers who they are, what each is placed among (`band`), what each attaches,
and — the part that is easy to miss — **since when each of those modules has
been running somewhere that department can see it**.

```php
foreach ($this->directory->forScope($scope)->attaching('patrols') as $entry) {
    $cell = $entry->canAnswerFor('patrols')
        ? new MatrixCell(value: $this->coverageFor($entry), delta: …, history: …)
        : MatrixCell::notMine();
}
```

**`attaching()` is your matrix's rows; `canAnswerFor()` decides each cell.** A
department that attached your module said this is work it leads for, so it is a
row even where no area it reads is running the module yet — its cells are
`MatrixCell::notMine()` until one is. A department that attaches nothing of the
kind is not a row at all: the module is not its work, and a dash across a whole
row is the old board's mistake in miniature. Periods before an entry's
`runningSince` are holes in its history for the same reason. `answeringFor()`
is the narrower set — the rows you will actually query.

Four rules the host will hold you to, and they are the same four the rest of
this platform states:

- **Exactly five KPIs.** A row of three where the design has five is a
  different design, and a reader cannot tell a short row from a quiet month. A
  figure you cannot compute yet is a `TopicKpi` with a null value and a caption
  saying so.
- **Null is never nought.** A value nobody published, a period nobody wrote
  down (a hole in `history`) and a column that is not a department's to answer
  (`MatrixCell::notMine()`) are three different absences, drawn three different
  ways.
- **Every column states its polarity.** More patrols is better, more incidents
  is worse, and more positions is neither — `ColumnPolarity::None` is never
  tinted and its movement is never coloured.
- **Scope and period are asked, not assumed.** Answer for the scope you are
  handed, or you will draw the organization's figures on one area's page.

**The shades are not yours, and neither is the table.** You publish figures and
say which way is good; where a department stands among the others is worked out
once by the host, inside one column and one band, and is not drawn at all where
fewer than three in the band have a figure. Your matrix is rendered by the same
`render_matrix()` the host's own topics go through — there is no hook for a
table of your own, on purpose: a second table would be almost this one, and
"almost" is what a reader has to stop and work out.

**A column may count STATES instead of measuring.** Four goals in four states
have no average, and a figure a reader cannot get back to the goals from is
worse than no column — so such a cell carries `CellMark`s and the renderer
draws chips where it would draw a number. The word is yours and the tone is the
platform's, the same bargain a calendar pill strikes.

**A column total is published, never summed.** If a column has a meaningful
total, put it on the column; the host will not add your cells up, because a sum
of averages is a number nobody measured:

```php
new MatrixColumn('patrols.covered', 'Covered', unit: '%', polarity: ColumnPolarity::Up,
    total: 61.0, totalDelta: 2.4)
```

A column that says nothing simply has no total line under its name.

**A row and a band say what they are, and only you can say it.** Under a
department's name goes one line in your own words — "org-wide · 4 areas ·
Patrols" — and under a band's name goes what that boundary was measured over:

```php
new TopicMatrix($columns, $rows, 'one cell a department in a topic', [
    'Org-wide' => 'each reads every area · 32 of 40 seats filled',
]);
```

The host draws the boundary, because it made the placing inside it; what the
boundary MEANS is a sentence about your figures. A row or a band that says
nothing simply carries its name.

**If one of your five is a count of records, say so.** The organization's page
adds up "records written, by department" across every topic that writes any,
and it cannot tell which of your five that is — your label is your own word,
and may be "cases", "sightings" or "patrols logged". Mark it:

```php
new TopicKpi(key: 'incidents.total', label: 'Cases filed', value: 561.0, role: KpiRole::Records)
```

A role is the exception, not the rule: every other figure is yours, drawn as
you named it, with the host making no claim about what it means.

**If you have figures about the GROUND, publish them beside your topic.** Most
topics have nothing to say about where — staffing does not, goals do not — so
this is a separate optional interface rather than a method on the topic
contract that every module would have to answer with nothing:

```php
final readonly class PatrolGeo implements PerformanceGeoProviderInterface
{
    public function moduleSlug(): string { return 'patrols'; }

    public function geo(PerformanceScope $scope, FigurePeriod $period): array
    {
        return [new GeoSeries(
            key: 'patrols.coverage',
            title: 'Patrol coverage, by area',
            figures: [new GeoFigure($areaUuid, 'Kilimani Crater', 58.0)],
            over: GeoSeries::OVER_AREAS,
            unit: '%',
            polarity: ColumnPolarity::Up,
        )];
    }
}
```

Name the ground by its identifier and never by its geometry — the area bundle
owns the shapes and draws them on the atlas plate, with the same chrome and
the same legend as every other map in the product. Say what the series is over
(areas, or the zones of one area), and give it a polarity: a plate hues a
placing, and hue without polarity is a plate claiming that more is better when
the figure is incidents.

**How long a history is, and what a delta means.** Six periods for the
sparkline in a `TopicKpi` and in a `MatrixCell` — that is what the cell draws —
and twelve for a chart's series, which is what the design plots. Oldest first,
holes kept as nulls in both.

A **delta is absolute and in the figure's own unit**: the difference between
this period and the compared one, nothing else. The host formats it — a count's
movement reads as a percentage, a share's as points — so a module that
pre-formatted its own would be the one row on the page that disagreed with
every other. Give the number; the page writes the sentence.

Say what your chart IS — a run over time, a comparison, parts of a whole, a
movement either side of nought — and the atlas draws it. A module handing over
chart options would be a module deciding what the platform's charts look like,
and the second module would decide differently.

### The classes the overview lends you

Some of what a contributed cell wears is the surface's, not yours — the contributor tag every card
carries, the live dot, the attention row, the flow bar, the honest not-installed slot. They are the
surface's because the surface is what decides that every module's cell reads the same way: a module
shipping its own flow bar is two flows that drift, and one shipping none renders its segments as
blue underlined links. Write these and the area's stylesheet dresses them:

The needs-attention row is no longer one of them. `.ao-att` is drawn on three surfaces now — this
overview, the organization dashboard and the settings section — so it moved into the shell's own
sheet and is listed with the frame's components in `Contract\LayoutContract::COMPONENTS`. Nothing
about the markup changed, and a cell already writing it keeps working wherever it is drawn.

<!-- overview-vocabulary -->
| Class | What it is |
|---|---|
| `.ao-by` | the contributor tag every card on this surface wears (`.host`, `.next`, or your slug) |
| `.ao-live` | the live dot — only on a cell that actually polls |
| `.ao-flow` | records by where they have got to; `a.s1`–`a.s5` are the states, `.n` `.s` `.b` the parts, `a.late` the overdue one |
| `.ao-slot` | the honest not-installed-here affordance |
| `.ao-slotrow` | one row inside it — `.nm` the name, `.wd` what it would hold, `.st` its state |
| `.ao-col` | a heading over one module's stack — `i` the dot, `b` the name, `.n` what it amounts to |
| `.ao-colstack` | the stack under that heading |
<!-- /overview-vocabulary -->

Your module's own hue is yours: declare `.ao-col i.<your slug>` (and `.ao-by.<your slug> i`) in
your own stylesheet, which this surface links. The core names no module, so the dot is neutral
until you colour it.

The list is published as `Overview\OverviewVocabulary::HOST_CLASSES`, so a module's vocabulary test
can assert that every class its cells write is either its own or one of these. The shell's
components — `.c`, `.tab`, `.use`, `.kpi`, `.tbl`, `.rln`, `.chip`, `.more`, `.mono`, `.fog`, `.r`
and the rest — are a layer below and are frozen in `LayoutContract::COMPONENTS`; they are yours to
write on any surface in the product.

**The zone seam is asked once for the whole set.** A zone has no numbers of its own — the area
module owns the ground, the name and the ring, and every count over that ground is whichever
module recorded it — so `ZoneFigureProviderInterface` is the only way a figure reaches a zone
page. It differs from the department seam in one respect worth knowing before you implement it:
the request carries **every zone the caller is about to draw** (`ZoneFigureRequest`), and the
answer is **keyed by zone uuid** (`ZoneFigures`). The all-zones view draws a row per zone and the
map legend draws another, so a provider asked zone by zone would cost a round trip per zone per
module per page; handed the set, you run one query grouped by zone.

The figures themselves are the same `DepartmentKpi` value the department seam returns, so one KPI
card renderer serves both scopes and the two surfaces cannot drift apart — `null` is unknown and
never zero, a share moves in points and a count in percent. Leave `areaName` null: the ref already
says which area, and that field means "one area's share of a roll-up", which a zone figure never
is. One key per zone per call, because a surface draws one card per key.

One key is named on the interface rather than agreed by convention:
`ZoneFigureProviderInterface::COVERED` (`'covered'`), a share, published by whoever measures how
much of a zone's ground was worked. Three surfaces read that one key — the zones tab's Covered
card, a zone record's identity band and the plate's legend — so a provider that spelled it
differently would leave all three blank with nothing on the page to point at.

Answering with nothing is legitimate (`ZoneFigures::none()`), and a zone left out of the answer is
a zone your module has nothing to say about. Neither is a zero: until a module publishes, the zone
surfaces say so in the product's own words rather than drawing naughts.

**The station seam is the same shape one rung down.** `StationFigureProviderInterface` takes a
`StationFigureRequest` of `StationRef`s and answers `StationFigures` keyed by station uuid, with
the same `DepartmentKpi` values and the same period rule. It differs in one respect: a station's
dock draws **one row per module**, so the key is `StationFigureProviderInterface::HEADLINE` and a
module with more to say says it in the `caption` — "out of here · 412 km", "within 12 km · 1 open"
— rather than in a second key. What "about this post" means is yours: patrols are the ones that
started there, incidents are the ones within some distance of it, and the core prints your
qualifier without interpreting it. **Do not put a URL in a figure**: the core already knows your
module's entry route from the registry and resolves the dock's `Open →` from your slug.

**Two more seams point at people**, and both exist because the ground and the people live in
different bundles and neither may depend on the other. `PersonFacetProviderInterface` is answered
by whoever owns people — a position title and a department name for a set of user uuids, so a
postings board elsewhere can filter by role and department. `PersonPostingProviderInterface` is
answered by whoever owns stations — where each of a set of people works today, so a person's own
page can say so. Both take the whole set in one call, for the reason every seam here does.

Three rules that keep these contribution points honest:

- **Tag explicitly, at both ends.** The tag names are published as `TAG` constants on the
  interfaces; use them. Your services are not autoconfigured, so nothing applies these for you.
- **Per-module behaviour lives in the module.** Adding a module means adding tagged provider
  classes — never editing the host's generic controller or services to special-case you. If you
  find yourself wanting to, the contract is missing something; say so rather than reaching across.
- **Ship a legend with a layer.** Every map layer a module contributes carries its own legend, and
  the same layer must render identically wherever it is drawn. A layer with a private palette is a
  layer that will read differently on two screens.

### KPI figures: one set per call

**Figures follow scope, not people.** A provider is called only for a department that attaches its
module, and it answers with every record the module holds inside the ref's scope: an area-level
department's area, or every area for an organization-wide one. Who recorded a record, whether that
person holds a position today, and when anyone joined decide nothing. Two departments that attach
one module in one area read the same figures, and no surface adds them together. A breakdown by
position or by person may sit inside a widget; it never gates a figure.

`kpisFor(DepartmentRef $department, \DateTimeImmutable $now)` is asked about **one department at one
scope**, and it answers with **one figure per key** — never the same key once per area. The
performance surfaces draw a single row of representative tiles per module, so repeated labels with
nothing to tell them apart are unreadable.

The scope is on the ref, and it is the whole of the instruction:

| `DepartmentRef::$areaUuid` | The department | What you return |
|---|---|---|
| a uuid string | is confined to that area | that area's figures, and no other area's |
| `null` | is organization-wide | the **roll-up** across every area your module is switched on in |

How a roll-up combines is the KPI's own business — a count sums, a share is averaged or weighted by
whatever denominator the figure means — so **you decide, and you say which in the figure's
`caption`**: a reader cannot tell a sum from an average by looking at the number.

```php
public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
{
    $areas = null === $department->areaUuid
        ? $this->areas->findAllInstalled()
        : [$this->areas->findOneByUuid($department->areaUuid)];

    return [new DepartmentKpi(
        key: 'patrols',
        label: 'Patrols logged',
        moduleSlug: 'patrols',
        moduleName: 'Patrols',
        value: $this->patrols->countForDepartment($department->id, $areas, $now),
        caption: 1 === \count($areas) ? 'Patrols module' : 'Patrols module · summed across every area',
    )];
}
```

If you also want to show the split behind a roll-up, hand those sets back **beside** the total: a
`DepartmentKpi` that names an `areaName` is one area's share, and a nameless one is the total every
headline plate and goal is scored from. A surface handed several sets for one call labels each with
the area it carries rather than stacking them — but that is a page being defensive, not a licence to
skip the rule.

---

## 8. The module frame: tabs and the configure page

**Your module does not draw its own navigation.** It declares two lists and the shell draws both:
the data places it has, and what is on its configure page. Everything that used to be a
`_tabs.html.twig`, a Settings button and a "Back to dashboard" link in each module is one frame now,
and a module written after this one gets the same frame without agreeing to anything.

### The two rules

1. **A tab is a place where DATA lives.** Patrols has an overview and a list of patrols, so patrols
   has two tabs. Nothing that CONFIGURES the module is a tab — not settings, not the kinds a ranger
   picks from, not the widget library. A strip that mixes places to look at with screens that change
   how the module behaves has stopped meaning anything.
2. **One `Configure` button, one configure page.** Your module gets exactly one configuration entry:
   a `Configure` action in the page head, which the SHELL renders, and one page behind it whose
   sections you declare. No Settings button anywhere else, no kinds link anywhere else, no widget
   library button anywhere else, and no "Back to dashboard" — the Configure action is lit on the
   configure page and takes you back, and the crumb is there too.

### Declaring your data places

```php
namespace UhifadhiLabs\PatrolBundle\Shell;

use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

final class PatrolModuleTabs implements ModuleTabsInterface
{
    public function slug(): string
    {
        return 'patrols';
    }

    public function tabs(): array
    {
        return [
            new ModuleTab('Overview', 'patrol_dashboard'),
            // A LIST AND ITS DETAIL SCREEN ARE ONE PLACE: opening a record does not
            // leave the place the record lives in, so both routes light this tab.
            new ModuleTab('Patrols', 'patrol_list', lightsFor: ['patrol_list', 'patrol_detail']),
        ];
    }
}
```

Tag it by hand — your services are not autoconfigured:

```php
$services->set('patrol.module_tabs', PatrolModuleTabs::class)
    ->tag(ModuleTabsInterface::TAG);
```

What the shell then renders, with nothing further from you:

- the `.atabs` strip under your page head, on every one of your pages, with exactly one tab lit;
- your module's children in the sidebar's location tree — the fourth rung, under your module's row,
  which becomes the open ancestor while one of your places is lit.

`lightsFor` takes route names; an entry ending in `*` lights every route with that prefix, so a
module with a family of screens names the family rather than every member. A tab's `parameters` are
merged over the area of the request the viewer is in, so you never carry a router or an area uuid.

**A tab the viewer may not have is WITHHELD, never greyed out.** A disabled tab tells a ranger that
a screen exists and they are not trusted with it, which is a worse product than not mentioning it.
The value object has no url-less form, so there is nothing to grey out even by accident.

### Declaring your configure page

A section is exactly one of two shapes. `page()` is the ordinary one: the shell renders it, inside
the configure page, from a template you name — you write no head, no strip, no page frame, no way
back. `screen()` is for a section that already has an address of its own, and the strip links out
to it.

```php
namespace UhifadhiLabs\PatrolBundle\Shell;

use Uhifadhi\Contracts\Shell\ConfigurationSection;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;

final class PatrolConfigurationSections implements ConfigurationSectionsInterface
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly PatrolSettingsService $settings,
    ) {
    }

    public function slug(): string
    {
        return 'patrols';
    }

    /** The whole heading; the shell adds " · configure". */
    public function heading(): string
    {
        return $this->area()?->getName().' — Patrols';
    }

    public function summary(): ?string
    {
        return 'Everything this module is set up with in this area, in one place: how its dashboard '
            .'is composed, the words a ranger picks from, and the numbers the module runs on.';
    }

    public function sections(): array
    {
        $area = $this->area();

        return [
            ConfigurationSection::page(
                ConfigurationSection::WIDGETS,
                'Widget library',
                '@UhifadhiLabsPatrol/configure/_widgets.html.twig',
            ),
            // THE WORD IS YOURS. The shell prints "Observation kinds" because you
            // said so; it has no vocabulary of its own to impose.
            ConfigurationSection::page(
                'kinds',
                'Observation kinds',
                '@UhifadhiLabsPatrol/configure/_kinds.html.twig',
                ['kinds' => $this->settings->kindsFor($area)],
            ),
            ConfigurationSection::page(
                ConfigurationSection::SETTINGS,
                'Settings',
                '@UhifadhiLabsPatrol/configure/_settings.html.twig',
                ['settings' => $this->settings->forArea($area)],
            ),
        ];
    }
}
```

```php
$services->set('patrol.configuration_sections', PatrolConfigurationSections::class)
    ->args([service('request_stack'), service('patrol.settings')])
    ->tag(ConfigurationSectionsInterface::TAG);
```

What the shell then renders:

| It draws | You supply |
|---|---|
| `/areas/{uuid}/modules/patrols/configure` and `…/configure/{section}` | nothing — the addresses are the shell's |
| the crumb, the heading `<name> · configure`, the summary | `heading()`, `summary()` |
| the `.atabs` section strip, with the current section lit | the labels, through `sections()` |
| the `Configure` action, lit, linking back to your first tab | nothing |
| the section body | the template you named, given exactly the variables you handed it |

**The order is ruled, not declared.** The strip runs Widget library first and Settings last whatever
order you write, and whatever you file between them keeps the order you gave it. That is why the two
anchoring ids are constants: file your kinds in the middle and you get the platform's order for free.

**The bare configure address is your FIRST section** — Widget library, by that order — and every
other section hangs one segment below it. So `…/configure` is your widget library, `…/configure/kinds`
is your kinds, `…/configure/settings` is your settings, and the `Configure` action opens the bare one.
People come to a configure page for how the dashboard is composed, so that is what it opens on.

If your first section is a `screen()` rather than a page — a library screen you shipped before the
frame existed — the shell cannot draw it at the bare address, so **the bare address answers `302` to
that screen's own URL**. Same rule, both shapes: opening the configure page has one answer, and it is
the first section.

### What a module stops shipping

- a `_tabs.html.twig` of its own, or any per-module tab partial;
- a Settings page with its own route, head and frame;
- a `Settings`, `Observation kinds`, `Incident kinds` or `Widget library` button anywhere;
- a "Back to dashboard" link — the first data tab, the lit Configure action and the crumb are the
  three ways back, and there is no fourth;
- any definition of `.srow`, `.sact`, `.sadd`, `.sout`, `.frow`, `.save-row`, `.pgr`, `.lfilt`,
  `.lsearch`, `.recgrid`, `.upl-*` or `.cal-*`. Those are the platform's vocabulary and the shell's
  stylesheet defines them; a module that redefines one is drift, and the conformance test says so.

A module with nothing to configure declares no sections and gets no Configure action, which is the
right answer rather than an empty page.

---

## 9. Shipping migrations

**Your module owns its tables, so it ships the statements that create them.** An installation
runs `doctrine:migrations:migrate` and writes no version for you — the same way it writes none
for the core. `doctrine:migrations:diff` stays what an installation runs for the entities **it**
writes.

### The directory and the namespace

```
your-module/
    migrations/
        Version20260714091500.php
```

```php
// migrations/Version20260714091500.php
namespace YourVendor\Sightings\Migrations;
```

Both manifests map it, because a version has to resolve in an installation and after any split:

```json
{
    "autoload": {
        "psr-4": {
            "YourVendor\\Sightings\\": "",
            "YourVendor\\Sightings\\Migrations\\": "migrations/"
        }
    }
}
```

### The registration

One block in `prependExtension()`, guarded, and an installation configures nothing:

```php
// YourVendorSightingsBundle.php
public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    if (!$builder->hasExtension('doctrine_migrations')) {
        return;
    }

    $container->extension('doctrine_migrations', [
        'migrations_paths' => [
            'YourVendor\\Sightings\\Migrations' => __DIR__.'/migrations',
        ],
    ], prepend: true);
}
```

The guard is not decoration: an application may have your bundle and not the migrations bundle,
and there your module simply has no history to run.

### Where a generated version lands

`diff` and `generate` write into the namespace `--namespace` names. With no flag they fall back
to the **first** configured namespace — silently when there is nothing to ask, and through a
question whose default is that same first entry when there is:

```php
$dirs = $configuration->getMigrationDirectories();
if ($namespace === null && count($dirs) === 1) {
    $namespace = key($dirs);
} elseif ($namespace === null && count($dirs) > 1) {
    $question = new ChoiceQuestion('Please choose a namespace (defaults to the first one)', array_keys($dirs), 0);
```

(`vendor/doctrine/migrations/src/Tools/Console/Command/DoctrineCommand.php`, `getNamespace()`.)

Every path above is registered by PREPENDING it, and prepended configuration is merged ahead of
the application's own, so on an installation the first entry would be a package's — under
`vendor/`, where the next `composer update` deletes the file and leaves the row in
`doctrine_migration_versions` pointing at nothing. The core moves the directory no installed
bundle ships back to the front, so the flagless `doctrine:migrations:diff` an installation runs
for its OWN entities writes into its own `migrations/`. Your module needs nothing for this — only
to keep registering its path the way above.

**In your own repository it is the other way round.** Every namespace your test application
configures belongs to a package, so name the one you mean; without the flag a version lands in
whichever package happens to be first:

```bash
bin/console doctrine:migrations:diff --namespace='YourVendor\Sightings\Migrations'
```

### What decides the order

**Your versions run after the core's, and after every module you require.** The date in the class
name orders versions inside one package, and between your module and another module neither of
which requires the other — where there is no dependency to go on.

A version's identity in doctrine/migrations is its **full class name**, and the comparator that
ships with the library is a `strcmp` over that name
(`vendor/doctrine/migrations/src/Version/AlphabeticalComparator.php`), which with a namespace per
package is alphabetical by namespace. The core replaces it with one that reads the Composer
dependency graph — the `require` and `replace` blocks of each installed package's own
`composer.json` — through `doctrine_migrations.services`.

So there is nothing to arrange. A version of yours that declares a foreign key into a core table
is placed behind every core version by your manifest, and a `require` on
`uhifadhi/registry-bundle` places it behind the whole core, because the core is the one package
that answers to that name. If your module requires another module, the same holds between the two
of them, in the direction the manifest says.

Date a new version with the current timestamp anyway — `doctrine:migrations:diff` does it for you,
and it is what orders your own versions among themselves. It just no longer has to be later than
anybody else's.

If two packages that both ship migrations require each other, `doctrine:migrations:migrate` stops
and names them rather than picking an order. A cycle anywhere else in the graph is not looked at.

### The three rules

**Expand, backfill, contract — in that order, in one version.** Add the column nullable, write
the value into the rows already there, then require it. A version that adds a `NOT NULL` column
to a table an earlier version created, with neither a `DEFAULT` nor an `UPDATE` beside it, fails
on the first installation that has data in it.

**A column that cannot be backfilled for everyone ships nullable, and validation enforces it.**
There is no universally correct value for a fact an installation has not recorded yet, so the
database stays permissive and the rule lives where the rule actually is. A later release tightens
the column once every installation has been through the period where the value gets written.

**A destructive statement rides a later release than the code that stopped using it.** Dropping a
column loses data no `down()` brings back, so the release that stops reading it and the release
that drops it are two releases, and the file that drops says which is which:

```php
/**
 * @destructive 1.4 — sightings_observation.legacy_grid stopped being read in 1.3
 */
```

### The four tests to copy

The core keeps these under `tests/Core/`, and they are worth copying whole:

| Test | What it locks |
|---|---|
| `MigrationPathsAreRegisteredTest` | booting the application yields exactly the namespaces you ship, each pointing at a directory that exists |
| `MigrationsCoverSchemaTest` | on an empty database, `migrate` then `diff` reports `No changes detected` — the drift lock between your entities and your versions |
| `MigrationsUpgradeKeepsDataTest` | rows seeded through your own services survive every further `migrate`, and the whole history unwinds to nothing and comes back |
| `MigrationLintTest` | the three rules above, read off the SQL each version plans — with fixture versions that break one rule each, so a lint that passes everything you ship has been shown failing something |

The third one is worth being honest about: a version that creates a table has a `down()` that
drops it, and dropping a table drops its rows. What `down()` guarantees is that the schema
round-trips, which is what makes it worth shipping — it is the rehearsal before an upgrade, not a
data-safe undo. Data survives `up()`.

---

## 10. Testing

Tests first — the platform's modules are built that way and the contracts assume it.

The pyramid, in the order a failure should reach you:

- **Unit.** The config tree (with a plain `Processor`), value objects, any pure mapping. No
  container, no database, fast.
- **Integration.** A `TestKernel` boots a real container with your bundle in it. Everything else in
  the repository rides on this.
- **Functional.** A real request against a real database, for screens.

### The TestKernel pattern

```php
// tests/TestKernel.php
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new YourVendorSightingsBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, /* … */]);
        $container->extension('doctrine', ['dbal' => ['url' => '%env(SIGHTINGS_TEST_DATABASE_URL)%']]);

        // Stand in for the host's catalogue: tagged services are private, so a
        // collector is what makes your contribution observable at all.
        $container->services()
            ->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])
            ->public();
    }

    public function getCacheDir(): string { return sys_get_temp_dir().'/sightings-tests/cache'; }
}
```

Point `KERNEL_CLASS` at it from `phpunit.dist.xml`.

Two practical notes. **A private service cannot be fetched from a test** — expose a public alias or
a collector fixture for exactly the things you need to observe, and nothing more. And **pop the
error handler in `tearDown()`**: the framework's debug handler is registered during a kernel test
and never popped, which PHPUnit reports as risky.

```php
// tests/Integration/SightingsKernelTestCase.php
while (true) {
    $previous = set_exception_handler(static fn () => null);
    restore_exception_handler();
    if (null === $previous) { break; }
    restore_exception_handler();
}
```

### Standing in for the host

Your bundle binds to classes the **application** owns and you cannot depend on — the concrete class
behind `Entity\AreaInterface`, an installation's own account class. Do **not** require an
application. Put minimal stand-ins under `tests/Fixtures/App/…` and map them in `autoload-dev`:

```json
# composer.json
"autoload-dev": {
    "psr-4": {
        "YourVendor\\Sightings\\Tests\\": "tests/",
        "App\\Entity\\": "tests/Fixtures/App/Entity/"
    }
}
```

This is also why `class_exists()` is the wrong guard for a *bundle*: in your own test run those
fixture classes are autoloadable whether or not any host is present. Check `kernel.bundles` when
you mean "is this bundle in the kernel", and `class_exists()` only when you mean "did the host
application define this class".

### Stubs vs contracts

A stand-in of that kind is a **stub**, and a stub is a specific, disciplined thing: a class that
**impersonates a real owner's FQCN byte-for-byte**. Not "something like the application's area" —
the application's area, same namespace, same class name, so the join your test exercises is the join
a real installation exercises. Anything less and the test proves your fixture works.

Because a stub is a lie told on purpose, it is marked three ways, and all three are required:

1. **By location.** It lives under `tests/Fixtures/<the impersonated tree>` —
   `tests/Fixtures/App/Entity/AreaOfInterest.php`. The path
   repeats the namespace, so the file's own directory names its victim.
2. **By `autoload-dev`.** The mapping is dev-only, so a stub can never load in an application. If
   the host is present, its real class is on the production autoloader and wins; if it is not, the
   stub is only ever visible to your suite.
3. **By docblock.** One paragraph, at the top: whose class this is, that it is a stub, and what the
   real one is. The next person to read it is debugging something else.

**They are debt, and the interest is real.** A stub is only as true as the day it was copied — the
owner refactors, your suite stays green, and the first thing that notices is an installation. So
stubs are retired **release by release**, replaced by an interface the owner publishes and the
consumer binds to instead.

`AreaInterface` is the model of the finished retirement. Anything that needs an area and does not own
one — the registry's ledger, a department, a patrol — points at
`Uhifadhi\Contracts\Entity\AreaInterface`, maps its own association to that, and leaves
`doctrine.orm.resolve_target_entities` to whoever knows the answer, which for an ordinary
installation is `AreaBundle`. The only stub left is
`tests/Fixtures/App/Entity/AreaOfInterest.php`, and it exists to play the *installation's* end of
that resolution — a suite proving your module works against somebody else's area class, which is
the honest job of a stub.

`UserInterface` is the same shape, and it is worth reading as the pattern rather than as one
contract. Without it, every module that keeps records about people carries a copy of somebody else's
account class, each one as true as the day it was copied. Seven questions published as an interface
— and nothing else — mean none of them has to.

So a stub you write today is in one of two states, and it is worth writing down which. Either it is
playing the installation's end of a published contract, which is permanent and fine; or it is
impersonating a class whose owner has not published a contract yet, which is debt with a release
number on it.

**One consequence for renames.** A stub does not follow your bundle. Rename your own namespace and
every stub stays exactly where it is, because it belongs to the class it impersonates and that class
did not move.

This is the rule that a find-and-replace breaks silently. Sweep a stub up with the rest and nothing
fails: the suite still passes, because a stub your bundle also rewrote is just a class the bundle is
talking to itself with. It has stopped impersonating anything, and it will keep passing right up
until an installation disagrees. When you rename, the stubs are the files you skip on purpose.

### The test-database convention

One database per bundle, named `<slug>_bundle_test`, addressed by a bundle-specific env var so two
suites never collide:

```xml
<!-- phpunit.dist.xml -->
<env name="SIGHTINGS_TEST_DATABASE_URL"
     value="postgresql://app:app@127.0.0.1:5434/sightings_bundle_test?serverVersion=17&amp;charset=utf8"/>
```

If your bundle owns no entities, say so in a comment where the URL would have been and ship no
database at all — an absence that is explained is not an omission.

### Vocabulary conformance

Two of the things a module draws with are not its own: the CSS classes in the sheets a page links,
and the icons in the sets somebody registered. Both fail **silently**, and neither is caught by a
functional test, because both render a 200.

- A class no sheet defines does not throw. The element falls back to browser defaults, which look
  almost right on your machine — where the design's own sheet happens to be open in another tab —
  and plainly wrong on an installation.
- An icon whose file nothing ships does not throw either, on a deployment with fetching disabled.
  It is an empty box.

Only the build can catch those before a deploy, so the core ships a test that does.

**What the two checks ask.**

*The stylesheet check.* Does every class this bundle's templates write exist somewhere in the CSS
chain the page actually loads — the shell's sheet, your own, and any dependency's sheet your pages
link beside them — or in your own JavaScript, because a hook a controller toggles is shipped as
surely as a rule is? And does your sheet **redefine no selector the chain already ships**? Two
definitions of one control load in whichever order the page happens to link them, and the same
control renders differently on two screens.

*The icon check.* Does every icon this bundle references use a prefix it is allowed to use? And does
every reference under **your own** prefix resolve to a file in the directory your bundle registers,
with nothing to fetch from?

**The rule for a module.** The prefixes you may use are **your own alias** and **`shell:`** — your
pages render inside the shell, so the core's glyphs are yours to reuse rather than copy. **Any other
prefix fails the build, `lucide:` included.** A public library's prefix belongs to the installation,
which may answer it with its own artwork or not answer it at all. Ship any glyph you need under your
own prefix: copy the SVG into the directory you register as
`ux_icons.icon_sets.<your alias>.path` (see [Icons: one prefix per package](#icons-one-prefix-per-package))
and draw it as `<your alias>:<name>`.

**How to adopt it.** The core ships `Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase`
in `src/`, so your suite can autoload it. Add one file:

```php
// tests/Unit/VocabularyConformanceTest.php
use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

final class VocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string { return \dirname(__DIR__, 2); }

    protected static function alias(): string { return 'sightings'; }

    protected static function ownStylesheets(): array { return ['sightings.css']; }
}
```

`bundlePath()` is the directory your `composer.json` sits in — `templates/`, `assets/` and
`public/` are read from it. `ownStylesheets()` names your sheets relative to `public/`; a bundle
that ships none omits it and only the icon half applies. Two more hooks exist when you need them:
`linkedStylesheets()`, which defaults to the shell's sheet alone and is where you add a
dependency's if your pages link one, and `iconDirectory()`, which defaults to
`assets/icons/<your alias>`.

That is the whole adoption. It runs in `composer check` with the rest of your suite.

### Time conformance (an instant printed server-side fails CI)

The same shape of failure, for the same reason: an instant formatted server-side renders a 200 with a
plausible time on it, and a functional test asserting that text passes on the wrong answer. Only the
build can catch it, so the core ships a second base beside the first:

```php
// tests/Unit/Template/TimeConformanceTest.php
use Uhifadhi\Bundle\ShellBundle\Test\TimeConformanceTestCase;

final class TimeConformanceTest extends TimeConformanceTestCase
{
    protected static function bundlePath(): string { return \dirname(__DIR__, 3); }
}
```

It asks three things of every template you ship: every `|date(` sits inside a `<time datetime=…>`
element — or inside a relative label's `title="{{ t|date('c') }}"` — every `<time>` carries a
`datetime`, and every `data-localtime-format` names a shape the shell answers. `exemptTemplates()` is
there for a template that prints `|date(` as prose in a code sample, and each entry should say which.
Without PHPUnit the first check is
`grep -rn "|date(" templates/ | grep -v "<time" | grep -v "title="`, and anything it lists is an
instant in the server's zone. The shapes, and the rule the test enforces, are in
[theming.md — a time reads in the reader's zone](../../Bundle/ShellBundle/docs/theming.md#a-time-reads-in-the-readers-zone).

---

## 11. CI

One job, the same command a developer runs:

```yaml
# .github/workflows/ci.yml
on:
  push: { branches: [main] }
  pull_request:

jobs:
  check:
    strategy:
      matrix: { php: ['8.4', '8.5'] }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', coverage: none, tools: 'composer:v2' }
      - run: composer validate --strict
      - run: composer update --no-interaction --no-progress --prefer-dist
      - run: composer cs:check
      - run: composer phpstan
      - run: composer test
```

`composer update`, not `install`: a library should be tested against the dependency versions its
constraints actually allow, and the lock file is not committed. Test the **whole** supported PHP
range. If your suite needs a database, add a Postgres (or PostGIS) service and point your test env
var at it.

### Green here is green against **a** core, not against **the** core

Your CI resolves `uhifadhi/uhifadhi` from the published package. The core moves; your suite does
not notice until somebody pushes it. That gap is not hypothetical — it is how a module's own
stylesheet came to restate the shell's `.i-dd*` family and stay green for weeks: the vocabulary
test was measuring a snapshot of the shell that no longer existed.

Two halves close it, and you only own the first.

**Locally, `composer check` runs against the LINKED core working copy — never a vendored
snapshot.** `fundi bundle:link` (or the symlink it writes) puts your checkout of the core into
`vendor/uhifadhi/uhifadhi`, so the shell you are tested against is the shell as it is this
minute. If `vendor/uhifadhi/uhifadhi` is a downloaded directory rather than a link, your green is
about a core that no longer exists; relink before you believe a result, and certainly before you
report one.

```console
$ ls -l vendor/uhifadhi/uhifadhi
lrwxr-xr-x  ...  vendor/uhifadhi/uhifadhi -> ../../../uhifadhi      # a link: good
drwxr-xr-x  ...  vendor/uhifadhi/uhifadhi                           # a copy: relink
```

**And the core checks the fleet.** Every push to the core runs each module's own `composer check`
against that exact commit, in a `fleet-conformance` job that fails the core's build. So a change
to the shell that breaks your module is caught in the core's CI, at the moment it is introduced,
and not by you a week later. You do nothing to take part in it beyond keeping `composer check`
meaning what it says.

---

## 12. Flex recipe and activation

Installing a module should not be a checklist. A Flex recipe turns it into `composer require`.

`manifest.json` in a recipe repository:

```json
# <vendor>/<package>/<version>/manifest.json (your recipe repository)
{
    "bundles": { "YourVendor\\Sightings\\YourVendorSightingsBundle": ["all"] },
    "copy-from-recipe": { "config/": "%CONFIG_DIR%/" },
    "env": { "SIGHTINGS_API_KEY": "" }
}
```

with `config/packages/sightings.yaml` alongside it:

```yaml
# <vendor>/<package>/<version>/config/packages/sightings.yaml (your recipe repository)
sightings:
    module_category: biodiversity

when@dev:
    sightings:
        dev_tools: true
```

`dev_tools` is a convention worth copying: gate seeders, fixtures and anything that writes invented
data behind a flag the recipe enables only for `dev` and `test`, so production never grows a command
that fabricates records.

**What a recipe cannot do:** it copies files and registers bundles, but it will not merge new lines
into an existing `importmap.php`. If you ship importmap assets, the three entries from chapter 4 are
the one manual step, and your README has to say so.

Nor can it write a line that depends on names only an installation knows. If your bundle maps an
association to an interface (chapter 3), the recipe cannot fill in the target class — so first ask
the ownership question: **whoever knows the answer states the resolution.** If a module you ship
knows it, that module prepends it and the recipe says so in one sentence, with nothing to uncomment.
Only where the answer is genuinely the installation's does the recipe ship the
`doctrine.orm.resolve_target_entities` block **as a comment**, next to the reason — and either way
the bundle has to boot without it.

Then say honestly how far an installation gets before the interface is answered, and **test the
answer** rather than assuming it. "Boots without it" and "works without it" are different claims,
and a recipe that asserts the second while only the first is true is a recipe that reads as correct
right up to the first command that walks the metadata — see
[the chokepoints](#three-chokepoints-between-composer-require-and-a-schema) below.

### The host's half of a recipe-owned file

Where the host does have to add a block, the file becomes two authors' work, and Flex has an opinion
about that: `composer recipes:install <pkg> --force` **overwrites** recipe-owned files with the
recipe's version, then tells you to sort it out with `git diff` and `git checkout -p`.

So put the host's addition in one contiguous block at the **end** of the file, under a marker saying
which half is which, and never interleave it with the recipe's text. Restoring one hunk is a review;
restoring an interleaved diff is an excavation, and the people doing it will be doing it on a day
they were trying to do something else. Tell your users this in the file itself.

### Reading what the recipe did

A recipe writes into the installing project's working tree, never into `vendor/`. Flex's closing
*"these files are yours"* line means exactly that: the copied files belong to the host now, land in
its commits and are edited freely. So the receipt for an install is `git diff` — run it
before anything else and every line the recipe added is in front of you.

After that the ledger is `composer recipes`, which lists every installed recipe and flags the ones
with a newer version available. Naming one shows the detail:

```console
$ composer recipes your-vendor/sightings-module
```

It reports the recipe version that was applied, the files it installed, and whether a newer recipe
exists. When one does, `composer recipes:update your-vendor/sightings-module` patch-merges that
newer version into the project — it keeps the host's own edits where it can, rather than replacing
the file outright the way `recipes:install --force` does.

Flex also supports a `post-install.txt` beside the `manifest.json`, printed once when the install
finishes. The uhifadhi recipes deliberately ship none — `composer recipes` is the discoverability
story, and it answers months later as readily as it does on install day. The mechanism is there if
your own module wants it.

### Three chokepoints between `composer require` and a schema

These come from a real `composer create-project uhifadhi/skeleton park`, followed exactly as
written. They are here because each one is a shape any module can repeat, not because they are any
one package's particular bugs.

**1. Shipping tables without shipping the tool that creates them.** A bundle contributes two
entities and requires `doctrine/doctrine-bundle` and the ORM, so it looks complete. It is not: the
installed project's `bin/console list doctrine` offers `doctrine:schema:*` and no
`doctrine:migrations:*` at all, because nothing in the chain required
`doctrine/doctrine-migrations-bundle`. The ritual falls back to `schema:create`, which is the command
every deployment guide tells you never to use, and the fallback looks like a working install.

The rule: **if your bundle contributes a table, require the migration bundle.** Owning tables is
owning the need for the tool that creates them, exactly as it is owning the need for the ORM that
maps them.

The other half of that rule: **ship the versions too.** The tables are yours, so the statements
that create them are yours, and an installation runs them rather than generating a copy that then
has to be kept in step with yours by hand. How, and the three rules a version keeps, is
[7. Shipping migrations](#7-shipping-migrations). `doctrine:migrations:diff` stays what an
installation runs for the entities it writes itself.

**2. Claiming the host's resolution step is optional when it is not.** A recipe that says an
installation without `resolve_target_entities` simply goes without the per-area half is wrong: it
goes without a schema. The association is `NOT NULL`, so everything that walks the metadata stops:

```console
$ bin/console doctrine:schema:create
In MappingException.php line 72:
  Class 'Uhifadhi\Contracts\Entity\AreaInterface' does not exist
```

Booting still works, and that part is worth keeping true — a host between `composer require` and its
first entity must not be in a broken state. But "boots" is not "installs", and a recipe that sells
the one as the other has a reader debugging a mapping exception with the wrong page open.

There are three ways out and only one of them is good. Having the bundle that *declares* the
relation also ship a minimal concrete target is the tempting one, and it is wrong twice over: it
makes a package define a model its charter says belongs elsewhere, and it puts a stray table in
every installation that never noticed — payable later as a real migration on the day that
installation maps its own. Documenting the hand-step is the second: honest, and still a checklist
item somebody skips.

The third is the one to copy: **the answer comes from a package that genuinely knows it**.
`AreaBundle` provides a real area and prepends the resolution itself, exactly as `TeamBundle` does
for the user contract. Nothing defines a model it should not, no stray table appears anywhere, and
an installation writes no doctrine line at all. See
[whoever knows the answer states the resolution](#whoever-knows-the-answer-states-the-resolution).

Generalise it as two rules. **If your bundle cannot be schema'd until something else happens, that
something is part of installing your bundle** — put it in the recipe comment, the README and a test.
And **before you write a hand-step, ask whether a package could know the answer instead**; if one
can, the hand-step is a design that has not finished.

**3. An application that squats a vendor namespace, and the mapping prefix that pays for it.**
doctrine-bundle's Flex recipe writes its `mappings` block for the default application skeleton,
whose PSR-4 root is `App\`. An application that gives itself a different root — `Uhifadhi\`, say — therefore has
`src/Entity` mapped under a prefix nothing in it is in, and the failure arrives late and reads like
the developer's mistake:

```console
The class 'Uhifadhi\Entity\AreaOfInterest' was not found in the chain
configured namespaces App\Entity, Uhifadhi\Bundle\RegistryBundle\Entity
```

The obvious fix is to correct the prefix in `config/packages/doctrine.yaml` and comment why it
differs from the recipe it came from. That works and is still the wrong fix, because it treats a
symptom of the actual mistake: **the application has taken the platform's namespace.** `Uhifadhi\`
is the composer vendor's, and the core, the modules and every future first-party package are under
it. An application that also lives there makes "is this class the host's or a
bundle's?" unanswerable by reading it — a question a stub, a boundary test and a doctrine prefix all
have to answer.

So the namespace stays with the platform: a project created from the skeleton is stock-Symfony
`App\`, `Uhifadhi\` means platform code, and the doctrine-bundle recipe's own prefix is correct with
nothing to override. Where a skeleton *does* need to settle something the recipe cannot, it settles
it in the file it ships rather than leaving it to be corrected afterwards — a skeleton is copied
once and never updated, so a line missing there is missing from every future installation.

Two rules follow. **Do not put your application in a vendor's namespace** — the convention costs
nothing and buys a permanent answer to "whose class is this". And **in examples for a host you do
not control, use an obvious placeholder** (`<YourRoot>\Entity\…`) rather than a
concrete root: a placeholder gets substituted, a plausible-looking one gets pasted.

**A fourth, smaller one, for anyone who keeps a licence header on `config/bundles.php`:** the bundle
configurator regenerates that file wholesale on every `composer recipes:install --force` and drops
everything above `return [` — header, `declare(strict_types=1)`, all of it. It is a recipe-owned file
with a hand-edited preamble, which is the same two-authors problem as above; check it after any
recipe reinstall, and restore the preamble in the same commit rather than noticing three commits
later.

### Check the endpoint actually answers

A private recipe endpoint fails in the one way that looks like success. Flex asks for the index,
gets a 404, falls back to an **auto-generated** recipe, and registers your bundle from its
`"type": "symfony-bundle"` — so the install looks fine and everything the recipe ships beyond the
bundle line silently never arrives. This went unnoticed across every module in this project until an
install was audited end to end.

The cause is host-based credentials. Composer attaches its GitHub token per host, and
`raw.githubusercontent.com` is not `github.com` — so an endpoint on the raw domain is fetched
anonymously and a private repository answers 404. Use the API's contents URL, which composer does
authenticate:

```jsonc
// composer.json (the host application)
"extra": {
    "symfony": {
        "endpoint": [
            "https://api.github.com/repos/<org>/recipes/contents/index.json?ref=flex/main",
            "flex://defaults"
        ]
    }
}
```

Verify rather than assume — `composer recipes` names the source of every recipe it applied:

```console
$ composer recipes
 * your-vendor/sightings-module (recipe not installed)   # endpoint reachable, recipe pending
```

A package that is silently missing from that listing, or a `composer require` that says
`From auto-generated recipe`, means the endpoint is not being reached.

### What a real install teaches that a test suite does not

Each of these was found by installing into a project created from `uhifadhi/skeleton` and serving a
page, rather than by any bundle's own green suite.

**Your dependency constraints meet reality at the first host, not at your CI.** A bundle whose
suite runs `composer update` always resolves the newest of everything and never discovers that it
pinned a major nobody else is on. A bundle asking for `symfony/ux-icons ^2.20` against an
application on `^3.4` is simply uninstallable — a thirty-second fix, found only because something
tried to install both. Widen a constraint to every major you actually work on, and prove it by
running your suite against each.

**Anything your markup needs at render time must ship with you or degrade.** A bundle that names
icons from a set the host happens to have installed renders holes on a host that does not, and one
that fetches them on demand needs a network at build time. If your bundle's own chrome draws four
glyphs, ship those four and register them under a prefix of your own in `prependExtension()` — never
by extending a set the host also uses, which would make your bundle decide what the host's icon
names mean.

**A bundle cannot contribute an importmap entry, so its markup and its behaviour separate.** If your
templates carry `data-controller` attributes, the Stimulus controllers behind them are the host's to
add (chapter 4). Write the markup so that a missing controller is inert rather than broken, and say
in your README which names you emit — an installation without them should render correctly and
simply not animate.

**Dependencies bring their own recipes, and those recipes ship files.** Requiring one package can
install several: pulling in the core (`uhifadhi/uhifadhi`) brings `symfony/twig-bundle` and
`symfony/ux-icons` behind ShellBundle, and the stock Twig recipe writes a `templates/base.html.twig`
that competes with whatever frame your bundle expects pages to extend. Read what an install actually
wrote before committing it.

**A knob that gates nothing is worse than a missing feature.** Writing this chapter's config file is
where a reserved-for-later flag gets its promise spelled out for a stranger — and where it becomes
obvious that nothing reads it. Ship the keys your bundle genuinely acts on; delete the rest.

### Unreleased packages need a stability floor

While a package is on `dev-main` and untagged, a host requiring it also needs
`"minimum-stability": "dev"` with `"prefer-stable": true`. Composer will not resolve a **transitive**
dev dependency under a stable floor: requiring an untagged module fails asking you to name a version
for something further down the graph that you never asked for. Root-requiring your dependency's
dependencies is not a workaround, it is a trap for the next person. This goes away the day the
packages carry tags.

Packages outside packagist also need their own `repositories` entry in the host — composer does not
inherit repositories from a dependency, so a private or VCS-hosted transitive dependency must be
named at the root as well.

### After installing

Installing a module is `composer require`, then the same four lines as any upgrade:

```bash
bin/console cache:clear --no-warmup       # the container that knows the new bundle
bin/console doctrine:migrations:migrate   # your tables
bin/console registry:sync                 # your module enters the catalogue
bin/console cache:warmup
```

If your module added tables, the versions that create them ship with it — see
[7. Shipping migrations](#7-shipping-migrations). `doctrine:migrations:diff` is the
installation's, for the entities it writes itself.

**`registry:sync` is what adds your module to the catalogue and gives every existing area its row.**
It reports what it added, kept and retired, changes nothing when typed twice, and refuses — non-zero,
naming `doctrine:migrations:migrate` — when typed before the tables exist. Nothing a request does
reconciles anything, and nothing a request does opens the registry a connection. **Your module ships
no console command of its own**: `bin/console list` on a production installation offers the
framework's built-in commands plus the core's three — `registry:sync`, `team:user:create` and
`team:performance:snapshot`, each something a production build without development packages must
run — because everything else a person types belongs to devkit, which is `require-dev`. A command
your module wants is a `CommandDescriptor` handed to devkit; see
[the devkit contracts](devkit-contracts.md).

The sync is safe on production and safe to repeat. Your module's catalogue row is refreshed from your
provider every time — change your module's display name between releases and the catalogue follows,
because it upserts by slug. Each area's on/off state and ordering is created once and never revisited,
so a deploy cannot overrule an admin who parked your module, and uninstalling your bundle leaves
every area's rows where they are rather than deleting them.

Then switch the module on for an area from the Customize page — unless you declared `base()`, in
which case it is already on.

**Zero modules is a working installation**, and it is the state your module arrives into: a fresh
project has an empty catalogue and no per-area rows, the warm-up runs against tables that may not
exist yet without complaining, and the first module to be installed is the first row either table
has ever held.
