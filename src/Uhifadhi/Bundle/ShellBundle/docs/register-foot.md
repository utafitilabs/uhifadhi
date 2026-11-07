# The register foot and the pager

The contract of the foot under every house table and of the pager that cuts a
table into its pages: `@Shell/_foot.html.twig`, `Model/TablePage` and
`Service/TablePageService` (`shell.table_page`). Its design is the register
foot of `team-module/team/permissions/` and the house rule that every list,
table and register is paginated, always, even at one page.

## Contents

- [What it is](#what-it-is)
- [The pager](#the-pager)
- [The partial](#the-partial)
- [Paged in the browser](#paged-in-the-browser)
- [The house table's sub-line and door](#the-house-tables-sub-line-and-door)
- [The tests](#the-tests)

## What it is

A row under the table, mono 10.5px in fog: at the left the count and the page
size — "45 files · 20 per page", the size a dropdown of 20 · 50 · 100 · All
that reads "all on one page" at All — and at the right "page 2 of 3" with ‹ ›
drawn only where there is a page to go to. The rules are `.rdf-foot`,
`.rdf-size` and `.rdf-page` in `shell.css`.

## The pager

`TablePageService` reads `?page` and `?per` off the request and answers a
`TablePage`; anything not offered falls back (20 a page, page 1, the last page
past the end) rather than failing.

```php
$table = $this->pages->read($request, $this->files->countListed($filter));
$rows = $table->slice($all);                // a table held in full, or
$query->setFirstResult($table->offset)->setMaxResults($table->perPage);
return new Response($this->twig->render('files/index.html.twig', [
    'rows' => $rows, 'table' => $table, 'links' => $this->pages->links($request, $table),
]));
```

| `TablePage` | |
|---|---|
| `total` | the rows the filters left, on every page |
| `size` | `20`, `50`, `100` or `all` (`TablePage::SIZES`, default `TablePage::DEFAULT_SIZE`) |
| `perPage` | the rows a page holds, null at all |
| `page`, `pages` | this page from 1, and how many there are (at least 1) |
| `offset` | the first row of this page, from 0 |
| `slice($rows)` | this page's rows of a table held in full, as a list |
| `isAll()`, `hasPrevious()`, `hasNext()` | |

`links($request, $table)` answers `{sizes: {size: href}, previous, next}`: the
page's own address with its whole query kept, a size starting again at page 1,
an arrow changing only the page; `previous`/`next` are null where there is no
page. Two tables on one page pass their own keys to both calls:
`read($request, $total, 'mpage', 'mper')`.

The service is wired as `shell.table_page` and aliased by its class, so a
module's explicit wiring names it: `->args([service('shell.table_page')])`.

## The partial

`@Shell/_foot.html.twig`, a plain include:

```twig
{% include '@Shell/_foot.html.twig' with {
    count: table.total, noun: 'files', one: 'file',
    perPage: table.size, page: table.page, pages: table.pages, links: links,
} only %}
```

| Parameter | What it is |
|---|---|
| `count` | the rows the filters left, grouped by thousands |
| `noun`, `one` | what they are, plural and singular (`one` defaults to the noun) |
| `perPage` | `20`, `50`, `100` or `all`; `0` is all |
| `page` | this page, 1 when absent |
| `pages` | how many pages; from the count and the size when absent |
| `links` | the pager's links: every size an `a.i-ddopt`, the arrows `a` |
| `client` | true: buttons for the page's own controller in place of links |
| `hidden` | true: the foot is drawn hidden |
| `hooks` | for `client`: see below |

## Paged in the browser

With `client: true` the server draws page one and the choices are buttons: a
size `button.i-ddopt` with `aria-pressed` and its size in `data-per`, an arrow
`button` with its step in `data-go`. A page's controller that reads other
names says so in `hooks`, and the partial writes them:

| Hook | What it is |
|---|---|
| `foot`, `count`, `size`, `menu`, `pager` | attributes (name → value) on the foot, the count's span, the size's `details`, its options' box and the page half |
| `option` | the attribute an option's size is in, `data-per` when absent |
| `all` | the value All carries there, `all` when absent |
| `go` | the attribute an arrow's step is in, `data-go` when absent |

```twig
hooks: {
    foot: {'data-pa-foot': '', 'data-pa-noun': 'actors', 'data-pa-one': 'actor'},
    count: {'data-pa-count': ''}, pager: {'data-pa-page': ''},
    option: 'data-pa-size', all: '0', go: 'data-pa-go',
}
```

## The house table's sub-line and door

Two parts of `table.tbl` the foot's tables lean on:

- `.sub` — a cell's second fact under its first: block, 2px above, 11px 400
  mono, line-height 1.4, in `--dim`. Scoped to `table.tbl`, because `.sub` is
  also the KPI plate's line.
- `td.door` — the last column's door (`a.more`, a chevron, a small form): 1 %
  wide, right-aligned, never wrapped.

## The tests

- `tests/Unit/Model/TablePageTest` and `tests/Unit/Service/TablePageServiceTest`
  hold the arithmetic, the fallbacks, the keys and the links;
- `tests/Integration/Foot/FootIncludeTest` renders the partial by links and in
  the browser's two dialects, feeds it from the wired service, and checks every
  class it writes, `.sub` and `td.door` included, is in `shell.css`;
- `tests/Unit/Template/VocabularyConformanceTest` asks the sheet for every class
  of the partial and for the sub-line's and door's values.
