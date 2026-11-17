# The installation's manual

An installation keeps its own manual — the guide its people read — in its own
repository, and the shell serves it to everybody signed in: the page at
`/manual/`, its pictures, the PDF to download, and a **Manual** row at the foot
of the sidebar.

## Contents

- [Where the manual lives](#where-the-manual-lives)
- [Configuring it](#configuring-it)
- [The addresses](#the-addresses)
- [The sidebar row](#the-sidebar-row)
- [Who may read it](#who-may-read-it)

## Where the manual lives

The content is the installation's, never the core's. A manual change is an
installation commit and a deploy; no core release is involved. The shell only
reads one folder, the manual's build:

```text
docs/handbook/              # the folder; its name names the files in it
├── handbook.html           # the page: a standalone document with its own styles
├── handbook.pdf            # the same manual as a PDF (optional)
└── img/                    # the pictures the page links as img/<name>
    ├── 01-areas.jpg
    └── 02-stations.png
```

The page and the PDF are the files **named after their folder**. Anything else
in the folder — the build script, its template, the Markdown it is written in —
is ignored, so the build can live beside what it builds. The folder ships with
the installation (keep it out of `.dockerignore`).

## Configuring it

One key, in the installation's own shell configuration:

```yaml
# config/packages/shell.yaml (your application)
shell:
    manual: '%kernel.project_dir%/docs/handbook'
```

No key, or `~`, is no manual: every address below answers 404 and the sidebar
draws no row.

## The addresses

| Route | Address | Answers |
|---|---|---|
| `manual` | `GET /manual/` | the page, as it is — `text/html; charset=UTF-8`, not wrapped in the frame |
| `manual_picture` | `GET /manual/img/{name}` | one picture from `img/` |
| `manual_pdf` | `GET /manual/manual.pdf` | the PDF, as a download named after the folder (`handbook.pdf`) |

The page's address ends in a slash so its own relative links (`img/…`) resolve
under it; `/manual` redirects there. A picture's name is lower case, has no
separator and ends in `.jpg`, `.jpeg`, `.png`, `.svg` or `.webp`
(`^[a-z0-9][a-z0-9._-]*\.(jpg|jpeg|png|svg|webp)$`); any other name, and a
missing file, is a 404, so no address reaches a file outside `img/`.

Every answer is `Cache-Control: private, no-cache` — no shared cache keeps it and
a browser asks again each time, so a deploy is read the same minute — and
`X-Robots-Tag: noindex`.

The routes are `config/routes/manual.php`, which `config/routes/settings.php`
imports, so an installation with the settings section needs no routing line of
its own. One without it imports the file directly:

```yaml
# config/routes/shell.yaml (your application)
shell_manual:
    resource: '@ShellBundle/config/routes/manual.php'
```

## The sidebar row

Where a folder is configured and its page is built, everybody signed in sees a
**Manual** row, with a book, in a **Help** group at the foot of the sidebar,
under Settings (`Uhifadhi\Contracts\Shell\NavGroup::HELP`). It opens the manual
in a new tab: the manual is read beside the screen it explains.

## Who may read it

Anybody signed in, remembered sign-ins included (`IS_AUTHENTICATED_REMEMBERED`).
The manual names no record, so it asks no permission pair; somebody signed out
is sent to sign in. The core's authority table (`tests/Core/authority-table.md`)
records all three addresses.
