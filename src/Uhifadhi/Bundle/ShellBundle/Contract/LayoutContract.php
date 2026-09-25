<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Bundle\ShellBundle\Contract;

/**
 * THE FROZEN MANIFEST — the shell's public API, as data.
 *
 * Three frames, twenty-three sockets, the theme tokens and one
 * version number. Everything a module is allowed to know about the
 * shell is on this class, and everything on this class is pinned by a test
 * that types the same list out by hand (tests/Sockets/BlockContractTest and
 * tests/Theme/ThemeContractTest). The manifest and the test disagree loudly, on
 * purpose: a list derived from the templates would agree with whatever the
 * templates happen to say, and would have nothing to report the day somebody
 * renames a block.
 *
 * The change policy is in docs/changing-the-contract.md: adding is a
 * minor version and a new row in the frozen test; renaming is a major version, a
 * deprecation cycle, and the old name kept as an alias block for one release.
 */
final class LayoutContract
{
    /**
     * The socket list's version, so a module can require a shell that
     * has the blocks it fills. Without a number, "the shell supports
     * shell_page_tabs" is a fact nobody can assert except by rendering.
     */
    public const int VERSION = 1;

    /** The document: html, head, theme, the four Symfony block names. */
    public const string DOCUMENT = '@Shell/document.html.twig';

    /** The shell: the furniture — sidebar, top bar, footer — around a page. */
    public const string SHELL = '@Shell/shell.html.twig';

    /** The page frame: the module author's rung, and the reason this exists. */
    public const string PAGE = '@Shell/page.html.twig';

    /** The catalogue picture: cards in groups. Data in; no grouping, no URLs. */
    public const string MODULE_GRID = '@Shell/_module_grid.html.twig';

    /**
     * The dark palette's selector. Part of the contract because a module's own
     * stylesheet writes it too — `html.dark .some-card { … }` — so a move to a
     * data attribute would silently unstyle every module stylesheet.
     */
    public const string DARK_SELECTOR = 'html.dark';

    /**
     * Partials a host or a module may include directly. A partial extends
     * nothing and declares no socket; it is a drawing, handed data.
     *
     * @var list<string>
     */
    public const array PARTIALS = [
        self::MODULE_GRID,
        '@Shell/_brand_mark.html.twig',
        '@Shell/_area_tabs.html.twig',
        '@Shell/_nav.html.twig',
        '@Shell/_flashes.html.twig',
    ];

    /**
     * THE TWENTY-THREE SOCKETS, in ladder order: the document's, the shell's,
     * the page frame's. Grouped and annotated in the frozen test, which is the
     * copy a module author should read.
     *
     * @var list<string>
     */
    public const array BLOCKS = [
        // The document — the four standard block names, unchanged.
        'title',
        'stylesheets',
        'javascripts',
        'importmap',
        'body',

        // The shell — the host's furniture. A module fills none of these.
        'shell_banner',
        'shell_impersonation',
        'shell_sidebar',
        'shell_sidebar_brand',
        'shell_sidebar_nav',
        'shell_sidebar_footer',
        'shell_topbar',
        'shell_topbar_actions',
        'shell_main',
        'content',
        'shell_footer',

        // The page frame — the module author's sockets.
        'shell_breadcrumbs',
        'shell_page_head',
        'shell_page_title',
        'shell_page_subtitle',
        'shell_page_actions',
        'shell_page_tabs',
        'shell_flashes',
        'shell_page',
    ];

    /**
     * THE THEME TOKENS. A module's stylesheet is written against
     * these names, so they are frozen exactly as the blocks are.
     *
     * The last six are DERIVED or non-colour and must not be restated per
     * theme: the brand trio rides the channels of --c-acc and --c-cv, and a
     * typeface that changed with the lights would be a different brand after
     * dark.
     *
     * @var list<string>
     */
    public const array TOKENS = [
        // surfaces
        '--c-cv',
        '--c-p1',
        '--c-p2',
        '--c-raised',

        // ink — three weights, and only three
        '--c-tx',
        '--c-fog',
        '--c-dim',

        // accent
        '--c-acc',
        '--c-accT',
        '--c-failT',

        // state
        '--c-ok',
        '--c-warn',
        '--c-fail',
        '--c-crit',

        // edges and depth
        '--c-ln',
        '--c-ln2',
        '--glass',
        '--shadow',
        '--scrim',
        '--lift',
        '--accGlow',

        // derived semantic aliases — the design's own token names, mapped ONCE
        // from the channels above so components and module sheets read them
        // directly (--tx not rgb(var(--c-tx))). They ride the channels and are
        // never redefined per theme, exactly like the brand trio below.
        '--cv',
        '--p1',
        '--p2',
        '--raised',
        '--tx',
        '--fog',
        '--dim',
        '--acc',
        '--accT',
        '--failT',
        '--ok',
        '--warn',
        '--fail',
        '--crit',
        '--ln',
        '--ln2',

        // brand — derived from the channels above
        '--logo-tile',
        '--logo-child',
        '--logo-accent',

        // type
        '--font-display',
        '--font-body',
        '--font-mono',

        // THE CATEGORICAL NINE AND THE RING AFTER THEM, and the plate
        // reading of each. A surface writes `data-cat` and reads `--cat`;
        // these are listed because a module legitimately reads one to paint
        // an SVG attribute, which is the one place the indirection cannot
        // reach. Ten to eighteen are the same nine hues one lightness step
        // further from the ground — a second ring, never a tenth hue.
        '--cat-1',
        '--cat-2',
        '--cat-3',
        '--cat-4',
        '--cat-5',
        '--cat-6',
        '--cat-7',
        '--cat-8',
        '--cat-9',
        '--cat-10',
        '--cat-11',
        '--cat-12',
        '--cat-13',
        '--cat-14',
        '--cat-15',
        '--cat-16',
        '--cat-17',
        '--cat-18',
        '--cat-p-1',
        '--cat-p-2',
        '--cat-p-3',
        '--cat-p-4',
        '--cat-p-5',
        '--cat-p-6',
        '--cat-p-7',
        '--cat-p-8',
        '--cat-p-9',
        '--cat-p-10',
        '--cat-p-11',
        '--cat-p-12',
        '--cat-p-13',
        '--cat-p-14',
        '--cat-p-15',
        '--cat-p-16',
        '--cat-p-17',
        '--cat-p-18',
        // `--cat` and `--cat-plate` are deliberately absent: they are set per
        // element by `[data-cat]`, never on the root, and a module reads them
        // rather than choosing one of the eighteen by number.

        // WHAT A PLATE DRAWS WITH. Imagery is dark in both themes, so none of
        // these turns over and a module never writes a literal on a `.viewer`.
        '--plate-ink',
        '--plate-veil',
        '--plate-rule',
        '--plate-edge',
        '--plate-acc',
        '--plate-ring',
        '--plate-ok',
        '--plate-warn',
        '--plate-fail',
        '--plate-dim',
        '--plate-base',
        '--plate-panel',
        '--plate-ground',
        '--plate-ground-sm',

        // The nine departments, as aliases of the nine — not a second palette.
        '--dept-ecology',
        '--dept-tourism',
        '--dept-engineering',
        '--dept-human-resource',
        '--dept-planning',
        '--dept-ict',
        '--dept-protection-service',
        '--dept-community-development',
        '--dept-veterinary-services',
    ];

    /**
     * THE COMPONENT VOCABULARY — the class names a module may write.
     *
     * The tokens say what jade is; these say what a KPI plate is. Both are
     * public API and both are frozen, for the same reason: four modules
     * independently wrote `class="kpi"` against a rule that lived in none of
     * them, and the day the shell renames it they all render as running text.
     *
     * These are the ENTRY classes. Their parts — `.kpi .sub`, `.rdf-page .pg`,
     * `.tbl .num`, the chip's and the grid's modifiers — are documented in
     * docs/components.md rather than listed here, because a part without its
     * entry is not a thing a module can write.
     *
     * Note what is NOT on the list, and why: `.pgbody`, `.pghead`, `.crumb`,
     * `.atabs`, `.side`, `.topbar` and the rest of the furniture. The shell
     * writes those itself, from its own templates; a module that typed one
     * would be drawing the frame instead of filling it.
     *
     * AND NO LIBRARY DOOR ON A SURFACE. There is no component for "Add
     * widgets — open the library" at the foot of a dashboard, an overview or
     * any other surface, and there will not be one: a page reaches its widget
     * library through the action in its PAGE HEADER, which is where every
     * design puts it and where a person looks for what a page can do. The
     * dashed ghost tile the shell does ship is the LIBRARY's own composer
     * affordance (`.w-addwidget`), for adding a widget to the preset being
     * composed on that page — it is not a door and must not be borrowed as
     * one. A module that writes a door writes a class the shell does not
     * define, and its vocabulary test fails, which is the enforcement.
     *
     * @var list<string>
     */
    public const array COMPONENTS = [
        // the plate and its vocabulary
        'c',
        'chip',
        'cta',
        'grid',

        // type, and the colour words
        'mono',
        'disp',
        'fog',
        'acc',
        'g',
        'w',
        'r',
        'd',
        'muted',

        // the card's tab, and the line saying what the card is for
        'tab',
        'use',

        // the way back to the list a detail screen came from
        'backbtn',

        // the identity band a detail screen opens with
        'factband',

        // THE READ-ONLY CATEGORY SWATCH. A category's colour is shown, never
        // picked: `.catsw` on an element inside a `[data-cat="1".."9"]` reads
        // `var(--cat)` and draws the one mark that says what hue this thing
        // wears. A module never writes a colour — it writes the index.
        'catsw',

        // THE ONE LEFT MARK A CARD MAY CARRY, and it means FOCUS: this is
        // the card the reader is on. Never open, never selected-and-showing,
        // never a category — a category is a chip or a hue dot and a state is
        // a chip or a stamp. Paint only, so nothing moves when focus arrives.
        'focusline',

        // the quiet forward link — at the end of a band, at the end of a
        // card's own row, and, as a DIRECT CHILD of `.c`, the card's one
        // quiet door pinned to its top edge (a card with two doors is two
        // cards); and the module identity dot, in the sidebar's tree and in
        // any table that names modules
        'more',
        'mdot',

        // THE ORGANIZATION-LEVEL MARKS. An org page is the area page one
        // scope wider, so it borrows everything; these three exist only
        // because "which area" is a column area level does not have.
        // `.orgarea` names the area on a row (its category swatch, never a
        // hue of its own), `.orgband` is one area's row header inside a
        // card, and `.lfilt-n` is what a filter bar is letting through.
        'orgarea',
        'orgband',
        'lfilt-n',

        // THE SCOPE CONTROL, the shell's and not a page's: every
        // organization-level surface the seam contributes gets this one,
        // and a module states none of its own.
        'ov-ctl',

        // THE PAGE HINT — one row, at the BOTTOM, where a page has
        // something to explain. Ruled: said once, as a fragment, under
        // the thing it is about, and never as two cards of prose above
        // a register. One per page, and the shell draws it (`@Shell/
        // _page_hint.html.twig`) so every hint in the product reads
        // alike — the design had four near-copies in four module sheets.
        'pghint',

        // WHERE SOMEBODY IS, RIGHT NOW — the one mark the accent is reserved
        // for. `.livedot` is two presentations of one primitive: a `<g>` inside
        // an SVG, drawn in the plate palette because imagery is dark in both
        // themes, and an `<i>` inline in a list or a legend row, drawn in the
        // theme palette. `.stale` is the same dot dimmed and still — older
        // than two ping intervals — and `.none` is the outline of one, for a
        // person with no fix, which is a row in a key and never a mark on the
        // ground. Presence state is NOT a marker colour: what somebody is
        // doing belongs to the row, wherever the rows are.
        'livedot',

        // the KPI plate, and the strip it sits in
        'kpi',
        'kstrip',

        // the register table, the meta row, and the pager under them
        'tbl',
        'rln',
        'rdf-foot',
        'rdf-page',

        // ONE THING THAT NEEDS SOMEBODY, as a row — the queue three surfaces
        // draw from three different owners' items (an area's overview, the
        // organization dashboard, the settings section). It was the area
        // overview's own until the second surface needed it; the rail is the
        // urgency and it is the only thing on the row that takes a colour.
        'ao-att',

        // the person's mark, and the two quiet buttons
        'avatar',
        'open-btn',
        'tgl',

        // the form field every filter/search input is drawn as
        'fld',

        // a form's actions, as the last row of its own body — one hairline,
        // the quiet controls first and the accent last. Not a footer strip,
        // which is the card's furniture and means something else.
        'staddrow',

        // THE HOUSE CREATE CARD — the thing a register creates is created at
        // the top of the page, in this card, and never in a second form. The
        // heading names it (`dcadd`), the card holds one row of labelled
        // fields (`crcard`) and the first choice, where there is one, is a
        // segmented control (`seg`).
        'dcadd',
        'crcard',
        'seg',

        // TOP-LEVEL SECTION MARKS — the vocabulary a section surface
        // (Departments, Team, Files) needs: the bound a bounded card ends
        // on, the attachment matrix's table, the doors at the
        // foot of an overview, a card's footer strip and its lead, a grouped
        // table's band row, a vocabulary row's quiet edit, and the strip entry
        // for a section that is named but not drawn yet.
        'sxmore',
        'sxmx',
        'sxdoors',
        'sxdoor',
        'sxfoot',
        'sxgrp',
        'sxlead',
        'sxq',
        'soon',

        // a figure's movement against the previous period, in a KPI's sub row
        'delta',
    ];
}
