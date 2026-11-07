# The dropdown

The contract of the shell's dropdown: the pill trigger with its floating panel of
grouped rows, drawn by `@Shell/_dropdown.html.twig` and moved by the `dropdown`
controller. Its design is `uhifadhi-web/house-dropdown.css` and
`house-dropdown.js` in the design workspace (a copy in `team-module/vendor/`),
used on `team-module/team/postings.html` and `team-module/team/permissions/`
`check.html` and `analysis.html`. How a page uses it is in
[the theme](theming.md#the-dropdown).

## Contents

- [What it is](#what-it-is)
- [The partial](#the-partial)
- [The controller](#the-controller)
- [The names](#the-names)
- [The tests](#the-tests)

## What it is

A pill trigger with an optional leading 16px icon, the label and a chevron.
While a value other than the default is chosen, the pill takes the "set" state:
an accent tint, an accent line and a heavier weight. The panel floats 8px below
the trigger and its `.page` is lifted above the page foot while it is open. The
panel has an optional search pill and a list of groups, each with a head and a
count, then 40px rows. A row is a label and a muted part. The chosen row is
tinted and carries the glowing 3×18px accent bar; the default row, chosen,
reads plain with a grey bar. The list grid is `minmax(0, 1fr)` with
`overflow-x: hidden`, so a panel never scrolls sideways. One panel is open at a
time. The keyboard works as the design's script does: ArrowDown opens,
Up/Down/Home/End move, Enter chooses (in the search, the first match), and
Escape, an outside click or tabbing away closes.

## The partial

`@Shell/_dropdown.html.twig`, a plain include:

| Parameter | What it is |
|---|---|
| `name` | the dropdown's name: `data-dd="<name>"`, and the panel id `dd-p-<name>` |
| `label` | what the control is called: the listbox's and the search's `aria-label`, and the trigger's text while nothing is chosen unless `placeholder` is given |
| `icon` | the leading mark, a namespaced ux-icons name (`shell:user`); none draws none |
| `groups` | `[{label, options: [{value, label, count}]}]`; the group head shows its option count; a group whose `label` is null draws no head and is named by the control's `label` (the lead *Any …* row, alone above the first head); an option's `count` is its muted part (`.dd-m`), absent when null |
| `value` | the chosen value; the row is `aria-selected="true"` and the trigger reads its label |
| `searchable` | true draws the search pill |
| `default` | optional: the value that does not count as "set" (`data-default`) |
| `end` | optional: true aligns the panel to the trigger's right edge |
| `sm` | optional: true draws the compact head trigger, `.dd-sm` |
| `placeholder` | optional: the trigger's text while nothing is chosen |
| `search` | optional: the search field's placeholder |
| `none` | optional: the line shown when a search finds nothing |

An option's `data-find` is its `find` when given (a person found by name or position), else its label and value, lower-cased; its `data-label`
is its label. The wrapper carries `data-dd-value` (the chosen value, empty when
nothing is chosen) and `.dd-set` from the server, so the page reads right
before the controller connects. A `value` that matches no option chooses
nothing.

`none` is a literal in Twig (it reads as null), so the partial reads that
parameter off `_context`; a page passes it as any other key.

### The compact head trigger

A dropdown in a page head's action row (Scope, Period, Compare) is the row's
size: `sm: true` adds `.dd-sm`, and the trigger is 32px high with a 9px radius,
12px 600 text (700 when set) and 14px icon and chevron, as every house button in
that row. Only the trigger shrinks: the panel, its search and its rows of at
least 40px are the dropdown's own, restated under `.dd-sm` so a row that sizes
its buttons does not size them. The design's `.pp-dd.pp-dd-sm`
(`house-dropdown.css`). A page sets the width it needs on its own qualified
selector.

## The controller

`assets/controllers/dropdown_controller.js`, identifier
`uhifadhi--shell-bundle--dropdown`, eager, declared in this bundle's
`assets/package.json` and in the core's root `assets/package.json`. It holds the
design's script with the behaviour unchanged. The listeners that work across
dropdowns (outside click, Escape, focusout, resize) are installed once, so one
panel is open at a time.

- A choice by the user dispatches `uhifadhi--shell-bundle--dropdown:choose`
  on the wrapper, bubbling, with `detail: {value}`. A host page's own controller
  listens with
  `data-action="uhifadhi--shell-bundle--dropdown:choose->host#chosen"`.
- `window.shellDropdown.set(wrapper, value)` chooses from script and fires no
  event; null clears. `window.shellDropdown.value(wrapper)` returns the value or
  null, and `window.shellDropdown.close()` closes whatever is open.

## The names

The shell's names stand in for the design's, value for value. Mono text is
`var(--font-mono)` and the icons are the shell's set.

| Design | Shell |
|---|---|
| `.pp-dd`, `.pp-dd-end`, `.pp-set`, `.pp-dd-sm` | `.dd`, `.dd-end`, `.dd-set`, `.dd-sm` |
| `.page.pp-dd-lift` | `.page.dd-lift` |
| `.pp-ddt`, `.pp-ddi`, `.pp-ddv`, `.pp-ddc` | `.dd-t`, `.dd-i`, `.dd-v`, `.dd-c` |
| `.pp-ddp`, `.pp-ddq`, `.pp-ddl`, `.pp-ddh` | `.dd-p`, `.dd-q`, `.dd-l`, `.dd-h` |
| `.pp-ddo`, `.pp-ddol`, `.pp-ddm`, `.pp-ddnone` | `.dd-o`, `.dd-ol`, `.dd-m`, `.dd-none` |
| `[data-pp-dd]`, `[data-pp-dd-value]`, `[data-pp-dd-n]` | `[data-dd]`, `[data-dd-value]`, `[data-dd-n]` |
| `pp-dd:choose` | `uhifadhi--shell-bundle--dropdown:choose` |
| `window.ppDD` | `window.shellDropdown` |

## The tests

They follow the index's tests (`tests/Unit/Assets/OnpageControllerTest`,
`tests/Integration/Onpage/`):

- `tests/Unit/Assets/DropdownControllerTest` reads the controller, the sheet's
  values and both manifests;
- `tests/Integration/Dropdown/DropdownIncludeTest` renders the partial with two
  groups of options, a value, counts and search, and checks every class it
  writes is in `shell.css`;
- `tests/Integration/Dropdown/PagesWithoutADropdownTest` serves a page without
  a dropdown and finds nothing of it;
- `tests/Unit/Template/VocabularyConformanceTest` asks the sheet for every class
  of the partial, the icons' included, and `FurnitureBehaviourTest` freezes the
  controller list with `dropdown` at its end.
