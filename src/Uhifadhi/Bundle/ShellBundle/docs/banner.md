# The attention banner

The contract of the shell's attention banner: one row above the content, drawn
by `@Shell/_banner.html.twig` only when there is something to act on. Its
design is `uhifadhi-web/house-banner.css` in the design workspace (a copy in
`team-module/vendor/`), the `.pp-drift` rules. Attention sits here and nowhere
else on a page: never in a card, a dot or an index.

## Contents

- [What it is](#what-it-is)
- [The partial](#the-partial)
- [The tones](#the-tones)
- [The names](#the-names)
- [The tests](#the-tests)

## What it is

A bordered, tinted row 24px above the content: a 16px icon, then the sentence —
its head 12.5px 600, the cause after it 12.5px 400 in fog — then its doors at
the right, each a `.btn.sm`. A door is a link, a form posted by its button, or a submit button of the form the banner sits in.
Below 900px the doors wrap under the sentence. A banner of several lines keeps
one icon, on the first line's centre, and draws one line a thing to act on,
each with its own doors.

## The partial

`@Shell/_banner.html.twig`, a plain include:

```twig
{% include '@Shell/_banner.html.twig' with {
    title: '4 answers changed with no recorded change',
    text: 'nothing in the history explains it',
    actions: {
        'Open': '#drift',
        'Mark reviewed': {action: path('team_permissions_drift_reviewed'), fields: {_token: token}},
    },
} only %}
```

| Parameter | What it is |
|---|---|
| `title` | the head, in the heavier weight; empty or null draws no banner |
| `text` | optional: the cause after the head, in fog |
| `actions` | optional: label → door. A string is a link's `href`; a mapping `{action, fields, method}` is a form whose `fields` are hidden inputs and whose submit button reads the label (`method` is `post` unless given); `{button: {name, value}}` is a submit button of the form around the banner, posting that name and value with the form's fields — a banner inside a form holds no form of its own |
| `tone` | `warn` (the default), `fail`, `info` or `ok` |
| `icon` | optional: a namespaced ux-icons name in place of the tone's |
| `id` | optional: the wrapper's id, for a door that links to the banner |
| `label` | optional: names the banner as a region (`role="region"`, `aria-label`) |
| `lines` | optional, in place of `title`/`text`/`actions`: `[{title, text, actions}]`, one line each; an empty list draws no banner |

## The tones

| Tone | Line and tint | Icon |
|---|---|---|
| `warn` | `--warn` at 55 % / 8 % over `--p1` | `shell:triangle-alert` in `--warn` |
| `fail` | `--fail` at 55 % / 8 % | `shell:triangle-alert` in `--fail` |
| `info` | `--acc` at 55 % / 8 % | `shell:info` in `--acc` |
| `ok` | a `--ln` hairline on `--p2`, the text in fog | `shell:triangle-alert` in `--ok` |

`ok` is the design's reviewed state (`.pp-done`): the attention settled, the
banner kept as the record. `info` has no design of its own yet; it takes the
warn banner's shape in the accent. An unknown tone is a render error.

## The names

| Design | Shell |
|---|---|
| `.pp-drift` | `.banner` with `.banner-warn`, `.banner-fail`, `.banner-info` or `.banner-ok` |
| `.pp-drift.pp-done` | `.banner.banner-ok` |
| `.pp-drift > svg` | `.banner-i` |
| `.pp-dtext`, `.pp-dtext b`, `.pp-dcause` | `.banner-t`, `.banner-t b`, `.banner-c` |
| `.pp-dacts` | `.banner-acts` |
| `.pp-b` | nothing: `.btn` already takes the body's font and pointer |
| several lines (`.dp-attn`, `.dp-attn-l`) | `.banner-lines`, `.banner-l` |

## The tests

- `tests/Integration/Banner/BannerIncludeTest` renders the partial with a link
  and a form, a submit button of the surrounding form, a title alone, each tone, several lines and nothing, and checks
  every class it writes is in `shell.css`;
- `tests/Unit/Template/VocabularyConformanceTest` asks the sheet for every class
  of the partial, the tones included.
