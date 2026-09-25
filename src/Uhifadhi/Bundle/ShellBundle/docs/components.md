# The component vocabulary

The class names a module writes on its own elements. The [theme](theming.md)
says what jade is; this says what a KPI plate is, and it turned out to matter
just as much.

## Contents

- [Why it is here](#why-it-is-here)
- [What passed the line, and what did not](#what-passed-the-line-and-what-did-not)
- [The list](#the-list)
- [Two ways to colour a sidebar dot](#two-ways-to-colour-a-sidebar-dot)
- [Two rules the tests enforce](#two-rules-the-tests-enforce)
- [The `font:` shorthand carries a line-height](#the-font-shorthand-carries-a-line-height)
- [What is furniture, and not yours to write](#what-is-furniture-and-not-yours-to-write)
- [What you get without asking](#what-you-get-without-asking)
- [Adding to it](#adding-to-it)

## Why it is here

The design workspace has always kept the platform's shared vocabulary in one
vendor sheet, and every screen's own sheet says "not repeated here". The
platform had no vendor sheet. So the first module that needed `.kpi` restated it
in its own stylesheet, under a block marked *on loan — belongs in the shell*,
and the modules that did not restate it drew a strip of plates as one line of
running text on a live page.

Four independent modules had written `class="kpi"` against a rule that lived in
none of them. That is the whole argument: one definition, in the frame, is the
difference between a design system and four of them.

## What passed the line, and what did not

Every rule was asked one question:

> Could a third-party Sightings module use this class without the shell knowing
> Sightings exists?

A plate, a number, a table, a pager and a person's mark all pass — they are what
those things look like on this platform, whoever is drawing them. A rule
encoding one module's screens does not pass, and stays in that module's own
sheet whatever it is named: a shell that shipped `.pm-deptrow` would be a shell
that knows what a department is.

The question is about what a rule **is**, not what it is called. `.dp-kstrip`
carried a departments-era prefix and was a plain auto-fitting strip of plates
that two unrelated modules already used, so it hoisted — under a generic name,
`.kstrip`. A rule named `.grid-2` that only ever laid out an incident triage
board would not have.

## The list

Frozen in `LayoutContract::COMPONENTS` and typed out again in
`Integration/Theme/ComponentContractTest::contractV1()`, so the two disagree
loudly the day somebody renames one. These are the **entry** classes — the name
you write on an element; the parts each one brings are in the table.

| Component | Entry | Its parts |
|---|---|---|
| The plate | `.c` | — |
| The status pill | `.chip` | `.ok` `.warn` `.fail` `.idle` `.acc` |
| The call to action | `.cta` | — |
| The column system | `.grid` | `.g2` `.g3` `.g4` `.g32` |
| Type | `.mono` `.disp` | — |
| The colour words | `.fog` `.acc` `.g` `.w` `.r` `.d` `.muted` | — |
| The card's tab | `.tab` (direct child of `.c`) | `.src`, the qualifier |
| What the card is for | `.use` | `b` for the emphasis |
| The way back | `.backbtn` | the chevron `svg`, sized by the rule; carries `margin-bottom: 16px` |
| The identity band | `.factband` | `.f` a fact, `.k`/`.v` its halves (`em` the unit), `.sp` then `.more`; wraps below 900px |
| The form's action row | `.staddrow` | the last row of a form's BODY — `.fld` grows, `.sp` pushes, the controls state their own 32px height |
| The category swatch | `.catsw` (+ `.sm` `.lg`) | inside a `[data-cat="1".."9"]`; shows the hue, never picks it |
| The focus line | `.focusline` | the **only** left mark a card may carry, and it means focus — a category is a chip or an 8px hue dot, a state is a chip or a stamp |
| The card's quiet door | `.more` (direct child of `.c`) | pinned to the card's top edge, lower case; **at most one per card** — page actions go in the page header, row actions on the row |
| The KPI plate | `.kpi` | `b`/`.disp` the number, `em` the unit, `.sub` the sub-line, `.hot` for the one that matters |
| The KPI strip | `.kstrip` | a modifier on `.grid`, never alone |
| The register table | `table.tbl` | `th` `td` `.num`, and the row's hover |
| The meta row | `.rln` | the two halves are yours (`.k`/`.v`, a `.mono` figure, a colour word); the last row drops the rule |
| The pager | `.rdf-foot` `.rdf-page` | `.pg`, the page you are on |
| The person's mark | `.avatar` | — |
| The row affordance | `.open-btn` | fills on the **row's** hover, not its own |
| The quiet button | `.tgl` | the secondary to `.cta` |
| The caret pair | `.reorder` | two `button`s, up over down, beside a row's grip in an ordered list; the `reorder` controller writes `.reorder-slot`, `.reorder-gap` and `.reorder-lifted` itself ([Moving a row](theming.md#moving-a-row-within-a-list)) |

**The way back is written as one line**, at the top of the page body:

```twig
<a class="backbtn" href="…">{{ ux_icon('shell:chevron-left') }} All modules</a>
```

It is not a page action — a link out of a record belongs where the reading
starts, not in `shell_page_actions` — and it carries its own 16px of air, so
the plate below it states no top margin.

**The identity band carries the gap under it.** `.factband` states
`margin: 0 0 20px`, so the element a screen puts below it states no top margin
of its own.

**`.factband` is drawn on `--c-raised`.** The identity band is a card, and every
card sits on the raised surface. The design draws it on `var(--card)`, which the
design defines as `var(--raised)` under both palettes — the same surface, under
the name the design's own sheets write.

**The colour words are not the start of a utility set.** They colour one word of
a sentence, and they are the palette's own names. A fifth grey belongs in the
token list or nowhere.

## Two ways to colour a sidebar dot

A row four rungs deep draws an identity dot, and there are two kinds of thing
that colour it. They are not interchangeable and the difference is worth one
paragraph, because picking the wrong one puts a palette in two places.

**`NavItem::$tone` is a CLASS**, printed into `class="mdot …"`. A module's hue
is fixed at build time and belongs to that module, so the module declares
`.ntree .ntm .mdot.<tone>` in its own stylesheet and its navigation source
hands the shell the class name. This is how `incidents` is amber and `roster`
is violet without the shell naming either.

**`NavItem::$swatch` is a VALUE**, printed into `style="background:…"`. Some
rows have a colour their source *computed*: a zone's hue comes from a palette
by its position in its area's set, so there is no class to declare and no
stylesheet that could know how many zones an installation will have. Declaring
them as classes would put the palette in a second place, and the map plate, its
key and the zone cards already read it from one.

The shell interprets neither — both are passthroughs, exactly like `icon`. The
swatch is checked on the way in (`NavItem` refuses anything that is not a three-
or six-digit hex colour), because the one thing a `style` passthrough must not
become is a hole to write CSS through. A row may carry both, neither, or one;
null on both keeps the shell's jade default, which is what the design gives a
row with no colour of its own.

## Two rules the tests enforce

**They spend tokens and name no colour.** No rule in the component section names
an `rgb()` or a hex value, which is what makes all of it correct in both
palettes without a single `html.dark` override. The first literal colour in
there is the first component that will look wrong after dark on somebody else's
page, so a test refuses one.

**They are unscoped, on purpose.** A module's stylesheet loads after the
shell's and may override anything here; what it must never have to do is opt in.
A vocabulary scoped to a shell wrapper would be a vocabulary only the shell's
own pages could speak, which is the opposite of the point.

## The `font:` shorthand carries a line-height

The designs write most small captions with the shorthand:

```css
.ov-ctl .k{font:600 8.5px "JetBrains Mono",ui-monospace,monospace;letter-spacing:.15em}
```

**`font:` RESETS `line-height` to `normal`.** Ported as longhands — which is
this sheet's house style, because a shorthand hides what it overwrites — the
rule inherits the body's `1.5` instead, and the caption silently grows two or
three pixels. One caption is nothing; a column of them moved the action row
from the design's 46px to 49px, which is the kind of drift nobody can name by
looking at it.

So: **every rule ported from a design's `font:` shorthand states its
line-height** — `normal`, or the explicit value where the design wrote one
(`font:800 10px/1 …` becomes `line-height: 1`). Port the shorthand's whole
meaning or none of it.

## What is furniture, and not yours to write

`.pgbody`, `.pghead`, `.pgact`, `.crumb`, `.atabs`, `.page`, `.side`, `.topbar`
and the rest of the frame. The shell writes those from its own templates. A
module that typed one would be drawing the frame instead of filling it — fill a
[socket](blocks.md) instead.

`.pgact` is the page's action row, and the frame lays it out completely: a
wrapping line of controls that are all one height, in which only weight and fill
separate the primary from the quiet ones. Fill `shell_page_actions` with the
controls themselves — a `.cta`, a `.tgl`, a form with a `.fld` — and write no
rule for the row. **A module may not restate `.pgact`.** It cannot reach the
wrapper to put a class on it, so a module that lays out the row again has
written a second definition of one component, and the header renders whichever
sheet the page happened to link last.

`.pgbody` is worth one line of its own, because it was the frame's one class
that nobody styled. A wrapper with no rules is not neutral: a module's first
element collapsed its top margin straight through it and into the frame, so the
gap under a page's tabs moved depending on whether the module led with a
heading, a paragraph or a card. It is now a `flow-root` with the first child's
top margin zeroed, and nothing else — what goes inside stays the module's
business.

## What you get without asking

The same sheet that carries this vocabulary opens with the platform's [box
model](theming.md#the-box-model): `border-box`, on every element and both
pseudo-elements, for every page the frame draws. Each rule above is written
against it, and so is yours — the number in a design is a border box, and now so
is the box the browser gives you. A module may stop restating
`box-sizing: border-box` on its own rules; it must never write `content-box`.

## Adding to it

Same policy as the sockets and the tokens: see [changing the
contract](changing-the-contract.md). Adding a component is a minor version and a
new row in the frozen list. Renaming one is a major version — module templates
across the platform write these names, and a renamed class does not fail a
build, it just stops applying.


## One palette, and a module never writes a colour

Every category in the product — an incident kind, a patrol type, a zone, a
department — takes **`--cat-1` … `--cat-9` by its position in its own declared
order**. A surface writes `data-cat="1".."9"` on the element (or an ancestor)
and reads `var(--cat)`; nothing downstream knows the nine values, and no module
ever names one.

**Kinds are ordered per area.** An area owns its vocabulary, so the same
wire-code may wear a different hue in two areas. That is correct, not a bug.

**A module hands over an index, never a colour.** `AreaNavChild::$cat` and
`Performance\ChartSeries::$cat` are `?int` 1–9; the host resolves them. A hex
across that seam would be right in one theme and wrong in the other, and wrong
again on imagery.

**Three readings, one index.** `--cat-n` turns over with the theme.
`--cat-p-n` is the plate value and does **not**: satellite imagery is dark
whichever way the interface is turned, so `.viewer [data-cat]` swaps `--cat` for
`--cat-plate`, and an SVG authored `fill="var(--cat-3)"` is repainted on a
plate by the shell rather than in every map's markup.

`--cat` falls back to the muted text colour, so an unknown category still draws
— grey, which is the honest thing for a remainder. Order beyond nine wraps to 1;
a taxonomy with more than nine members is a design problem, not a palette one.

### FLAGGED: the palette is written twice

`widget.css` restates the palette as Tailwind channel triplets beside the hex
tokens here. The root fix is **one source publishing both forms** — the shell's
build, not a hand copy — and until that lands the two can drift. Do not add a
third copy.
