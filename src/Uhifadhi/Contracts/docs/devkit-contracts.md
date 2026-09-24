# The devkit contracts

Two interfaces — `Devkit\ContentProviderInterface` and `Devkit\CommandProviderInterface` — and
one value object, `Devkit\CommandDescriptor`. They are how a module contributes **dev-only**
machinery: demo content to seed, and maintenance commands to run, that exist on a developer's
machine and in CI but in no production build.

## Contents

- [The require-dev firewall](#the-require-dev-firewall)
- [Why the interfaces live here and not in devkit](#why-the-interfaces-live-here-and-not-in-devkit)
- [`ContentProviderInterface` — demo content, ordered by dependency](#contentproviderinterface--demo-content-ordered-by-dependency)
- [`CommandProviderInterface` — commands, described without symfony/console](#commandproviderinterface--commands-described-without-symfonyconsole)
- [The framework-coupling decision, stated once](#the-framework-coupling-decision-stated-once)

## The require-dev firewall

`uhifadhi/devkit-module` is **the** dev-only module. It installs through `require-dev`, so it — and
every piece of machinery it owns — is absent from a production build. Its job is to be a
**collector**: other modules ship **inert provider classes** declared through the two contracts on
this page, and devkit `tagged_iterator`s them and materialises real Symfony console commands and
demo-content loaders — but only in a dev install, because that is the only place devkit exists.

This is not a small job, because devkit owns **every command the platform has bar one**. The core
ships a single console command — TeamBundle's `team:user:create`, the first administrator, which
has to exist on a production installation because that is where the first account is made and a
production build has no development packages. Everything else: the five bundles contribute
entities, screens and listeners, and nothing a person types. So a console command that belongs to
the platform rather than to an application is, by construction, a `CommandDescriptor` handed to
devkit — and it exists on a developer's machine and in CI and nowhere else.

The word for the providers is **inert**. In production, a provider is an ordinary tagged service
that is never asked to do anything, because the thing that would ask — devkit — is not installed.
It is data waiting for a dev tool that is not there.

## Why the interfaces live here and not in devkit

This is the crux of the arrangement, and it is the reason both interfaces are in
`uhifadhi/contracts` rather than in devkit itself.

The inert provider classes ship inside **always-installed** packages — the core's own bundles,
patrol, incidents. Their `implements` clause has to resolve at runtime **even when devkit is
absent**, because devkit is require-dev and production does not have it. An interface those
always-installed packages point at therefore has to live in a package they always have. That is
this one, and every module already has it: it ships inside the core.

devkit depends on the same two interfaces to collect the providers. So the arrows point at the
promise from both sides and neither side depends on the other:

```
  patrol-module ──implements──▶ Devkit\CommandProviderInterface ◀──reads── devkit-module
  incident-module ─implements─▶ Devkit\ContentProviderInterface ◀─reads─── devkit-module
```

Put the interface in devkit instead and patrol-module could not name it in production, where
devkit is not installed — the class would fatal on autoload. The require-dev firewall is exactly
what forces these contracts into the always-present package.

## `ContentProviderInterface` — demo content, ordered by dependency

A module implements this once per slice of demo content it seeds. devkit's `fixtures:demo` command
collects every provider, orders them, and calls `load()` on each — dev-only.

```php
// src/Devkit/IncidentContentProvider.php (incident-module)
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

final class IncidentContentProvider implements ContentProviderInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function key(): string         { return 'incident'; }
    public function label(): string       { return 'Incidents'; }
    public function description(): string { return 'Sample incidents raised inside the demo areas.'; }
    public function dependsOn(): array    { return ['area', 'patrol']; }

    public function load(): void { /* seed through $this->em */ }
}
```

Four questions and one verb. devkit has to **identify** each contribution (`key`, `label`,
`description`), **order** it against the others (`dependsOn`), and **run** it (`load`).

**Ordering is expressed as dependencies, not a priority number.** An incidents demo needs areas
and patrols to hang its records on; `dependsOn()` returning `['area', 'patrol']` says exactly that,
and devkit topologically sorts the providers on those edges. A hand-tuned integer only approximates
the relationship — and two modules that pick the same number say nothing at all about which comes
first. The dependency edge is the real thing, so the contract asks for the real thing.

**`load()` names no persistence type.** It takes no entity manager, for the same reason nothing
else in this package imports Doctrine: a content provider is an ordinary service and injects
whatever it needs to seed — an entity manager, repositories, a faker — through its constructor. The
seeded records are the effect; `load()` returns nothing.

### Method rationale

| Method | Why it exists |
| --- | --- |
| `key(): string` | Stable machine identity, unique across content providers, and the token other providers name in `dependsOn()`. It is the name of the *content*, not the module — a module that seeds two slices ships two providers. |
| `label(): string` | Human name devkit prints while seeding. |
| `description(): string` | One line saying what this slice seeds, shown next to the label. |
| `dependsOn(): array` | The keys that must be seeded first — the content this content is built on. `[]` for a slice that stands alone. Ordering as a relationship, not a guess. |
| `load(): void` | Does the seeding, once, after everything it depends on. Never called in production, where the collector does not exist. |

## `CommandProviderInterface` — commands, described without symfony/console

A module implements this to offer dev/maintenance commands beyond demo seeding. It is a bag: one
method, `commands()`, returning a list of `CommandDescriptor`. devkit turns each descriptor into a
real console command — in dev only.

```php
// src/Devkit/PatrolCommandProvider.php (patrol-module)
use Uhifadhi\Contracts\Devkit\CommandDescriptor;
use Uhifadhi\Contracts\Devkit\CommandIo;
use Uhifadhi\Contracts\Devkit\CommandProviderInterface;

final class PatrolCommandProvider implements CommandProviderInterface
{
    public function commands(): array
    {
        return [
            new CommandDescriptor(
                'patrol:demo:reset',
                'Wipe and reseed the patrol demo content.',
                fn (array $arguments, CommandIo $io): int => $this->reset($arguments, $io),
            ),
        ];
    }
}
```

A `CommandDescriptor` is a name, a one-line help string, and a **closure** — deliberately **not** a
`Symfony\Component\Console\Command`. The handler is the process contract, not the console one: it
receives the argument tail a person typed (`list<string>`, everything after the command name) and a
`CommandIo` to speak through, and returns a POSIX exit code (`0` = success). devkit's generated
wrapper collects the raw tokens into that array, passes an io wired to the real console, calls the
handler, and uses the returned int as the command's exit status.

### `CommandIo` — the three streams, and not an `OutputInterface`

```php
interface CommandIo
{
    public function write(string $line): void;   // the result, on stdout
    public function error(string $line): void;   // why it refused, on stderr
    public function readLine(): ?string;         // one line of stdin; null at end of input
    public function readSecret(): ?string;       // one line never put on the screen; null likewise
}
```

**A handler needs somewhere to talk, and the alternative to giving it one is worse than console
coupling.** Handed only a tail and an exit code, a command that creates something has no way to say
what it created — so it writes to `\STDOUT` itself, and that output has escaped the process it was
given: it ignores `--quiet`, a caller capturing the command's output cannot see it, and it turns up
uninvited in a test run. A passphrase read straight off `\STDIN` is the same escape in the other
direction. These verbs close both.

**Reading is two verbs rather than one flag,** and it is the only place the contract models
something a stream alone does not: whether what is typed appears on the screen. A passphrase read
like an ordinary line is a passphrase left in a terminal's scrollback and in whatever records it, so
asking for one is its own verb — a handler cannot ask for a secret and be handed an echoed one by
accident. Both answer the same shape: the line without its newline, or `null` when there is nothing
to give. `readLine()` keeps `''` — a line that was there and was empty — apart from `null`, the
closed stream; `readSecret()` cannot, and does not pretend to, because a line read with the echo off
arrives identically whether somebody pressed return on an empty prompt or the stream closed under
it. A handler requiring a secret refuses both anyway.

**The silence is owed only where a terminal exists.** Switching the echo off is a property of a
terminal, not of a stream, so `readSecret()` promises that where there is a terminal the typing does
not appear on it, and where there is none — a pipe, a log, a test — it reads the line plainly,
because there is no echo to suppress and nothing is leaked by reading it. That is what makes it one
verb for both a passphrase typed at a prompt and a passphrase piped in:

```bash
bin/console patrol:feed:connect                                 # typed, and not echoed
printf '%s' "$TOKEN" | bin/console patrol:feed:connect https://feed.example.test
```

devkit's adapter reads it through Symfony's `QuestionHelper` with `Question::setHidden(true)`, whose
own fallback rule it follows: where the response cannot be hidden it says so on the error stream and
reads the line anyway rather than refusing, because a credential that cannot be entered at all is
worse than one entered in view of the person entering it.

**It is deliberately not shaped like an `OutputInterface`.** No verbosity, no formatter, no
sections, no `write`/`writeln` distinction — modelling those would be reimplementing the console in
this package, the same refusal that keeps an `InputDefinition` out of the descriptor. Verbosity is
not lost by leaving it out; it is *honoured* by leaving it out, because devkit's adapter writes
through the real output, which applies `--quiet` and `-v` itself.

**The two output verbs are separate because the streams are.** What a command produced goes to
stdout, where a pipeline can read it; why it refused goes to stderr, where it survives that pipeline
rather than corrupting it.

Every field of the descriptor is required and validated in the constructor, the same discipline a
declared concern uses: an unnamed command cannot be registered, and one with no help line is a
blank row in `list`.

## The framework-coupling decision, stated once

`CommandProviderInterface` is the harder of the two contracts because a command has an obvious
framework form — a Symfony `Command` — and returning one would make devkit's job trivial. It is
refused, for two reasons, and this is the decision to revisit before any reshape:

1. **It would break the zero-dependency promise.** This package's whole claim is that depending on
   it costs nothing: no runtime, no configuration, no transitive machinery
   ([what-is-a-contract.md](what-is-a-contract.md)). Putting `symfony/console` in `require` would
   make every module that ships a command drag a console runtime behind it, and would be the first
   `use` statement in a package whose tests assert the interfaces import nothing.

2. **It would leak past the require-dev firewall.** The inert provider ships in an
   always-installed module, and its `commands()` runs when the container is built — including in
   production, where devkit is absent. A provider that returned `Command` instances would
   **construct Command objects in a production container that has no console command to run them**.
   Descriptors are inert data in production; they become real commands only when devkit, which
   legitimately requires `symfony/console`, wraps them in dev.

The cost of the descriptor choice is that the contract does not model options and arguments — doing
so would mean reimplementing an `InputDefinition` here, which is the coupling it exists to avoid. A
command that wants richer input parses the argument tail itself, or reaches through the service its
closure closes over. If a module ever genuinely needs full console-style argument definitions
across this contract, that is the fork to bring back to the contract owner — it is a breaking
change to these signatures, not an additive one.
