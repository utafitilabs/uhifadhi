# The architecture, and what this bundle guarantees

Where the shell sits in the platform, what it promises, what is in the
repository, and how to work on it.

## Contents

- [The architecture](#the-architecture)
- [What it guarantees](#what-it-guarantees)
- [What is here](#what-is-here)
- [Development](#development)

## The architecture

**Uhifadhi is one skeleton and a set of modules.**
[`uhifadhi/uhifadhi`](https://github.com/utafitilabs/uhifadhi) is the project
skeleton — copied once, never updated; everything else arrives as a module,
updated forever. A module **registers with the registry**
([`RegistryBundle`](../../RegistryBundle/README.md)) and
**renders in the shell** (`ShellBundle`
— this repository); everything a deployment can do — patrols, incidents,
rosters — is a module.

This is the package you can see.

## What it guarantees

**It is a contract, not a convention.** Block names, contract interfaces and theme
tokens are a versioned, test-enforced API — Symfony-extension-point grade. This
bundle exists because the alternative was in production: five bundles each
re-declaring `{% block content %}<div class="page">…` by copy, each with its own
flash markup, so that a change to the page frame reached the copies somebody
remembered. A convention is a contract that nothing checks.

**It remembers nothing.** No entities, no repositories, no doctrine, no
database. This is why this repository's CI is the only one in the platform with
no postgres service: a shell with entities has failed its boundary. It is also
why the whole test suite is "render this and look at it".

**It knows no module by name.** The registry's rule, inherited, and enforced by the
same kind of sweep — extended to `templates/`, because a template is the easiest
place in a codebase to type a module's name and the hardest place to notice it
later. A row in the sidebar and a card in the grid arrive as data.

**Zero of everything is a working installation.** No nav sources, no tabs, no
modules: the shell renders a sidebar with a brand and no rows, and a page. A
layout that only works once the application is finished is not a layout.

Every row in [the socket contract](blocks.md), [the navigation contract](navigation.md) and
[the theme](theming.md) is a test.

## What is here

| Piece | File |
|---|---|
| The Symfony plug, the stylesheet path, the nav tag | `src/ShellBundle.php` |
| Config tree (`shell:`) | `src/DependencyInjection/ShellConfiguration.php` |
| Static service wiring, and the published ids | `config/services.php` |
| The frozen manifest | `src/Contract/LayoutContract.php` |
| The contracts | `src/Contract/` |
| The shapes that cross them | `src/Model/` |
| The frames and partials | `templates/` |
| The welcome page a fresh installation serves at `/` | `templates/welcome.html.twig` |
| The controller that renders it | `src/Controller/WelcomeController.php` |
| The route resource an application imports to reach it | `config/routes/welcome.php` |
| What this installation is made of, read from composer | `src/Service/Installation.php` |
| The token set and the shell's own CSS | `public/shell.css` |
| The tab icon: the masterbrand mark, both palettes in one SVG | `public/favicon.svg` |
| The furniture's behaviour, as UX-packaged Stimulus controllers | `assets/controllers/` |
| What Flex reads to enable them in a host | `assets/package.json` |
| The four glyphs its chrome draws with | `assets/icons/shell/` |
| Test host app | `tests/Integration/TestKernel.php` |
| A host, minimally: two contract implementations and nothing else | `tests/Integration/Fixtures/HostKernel.php` |

## Development

```bash
composer install
composer check   # cs:check -> phpstan (max) -> the whole suite
```

- PHP 8.4+, PHPStan level **max**, php-cs-fixer `@Symfony` + `@Symfony:risky`.
- **Tests first, always.** This repository is that rule taken literally: the
  whole contract was written before a line of the shell existed.
- `tests/Integration/TestKernel.php` is framework + twig + ux-icons + this
  bundle, with `strict_variables` on, and **no database** — that is the boundary,
  not a convenience.
