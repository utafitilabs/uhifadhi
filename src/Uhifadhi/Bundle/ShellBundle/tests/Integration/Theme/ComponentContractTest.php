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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\ShellBundle\Contract\LayoutContract;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;
use Uhifadhi\Contracts\Atlas\PlatePalette;

/**
 * SPEC 5 — THE COMPONENT VOCABULARY.
 *
 * The tokens say what jade is. This says what a KPI plate is — and the second
 * one turned out to matter just as much, because four modules independently
 * wrote `class="kpi"` against a rule that lived in none of them.
 *
 * WHERE THESE CAME FROM. The design workspace keeps the shared vocabulary in a
 * vendor sheet and every screen's own sheet says "not repeated here". The
 * platform had no vendor sheet, so the first module to need `.kpi` restated it
 * in its own stylesheet and marked the block "on loan — belongs in the shell".
 * This is the shell collecting the loan: one definition, in the frame, so a
 * module that draws a register table gets the platform's register table rather
 * than its own idea of one.
 *
 * THE BOUNDARY THAT DECIDED THE LIST. For every rule: could a third-party
 * Sightings module use this class without the shell knowing Sightings exists?
 * `.kpi`, `table.tbl`, `.avatar`, `.tgl`, the card's tab and the pager all pass
 * — they are what a plate, a number, a table and a person's mark look like on
 * this platform. Anything encoding a particular module's screens does not pass
 * and stays in that module's own sheet, whatever it is named: a shell that
 * shipped `.pm-deptrow` would be a shell that knows what a department is.
 *
 * ONE NAME WAS WRONG AND IS FIXED HERE. The KPI strip's auto-fitting layout
 * shipped as `.dp-kstrip` — a departments-era prefix on a rule two unrelated
 * modules already use for a strip of plates. It is a generic layout, so it
 * hoists under a generic name, `.kstrip`.
 *
 * WHY THERE IS NO DARK HALF OF THIS FILE. Every rule below spends tokens and
 * names no colour of its own, which is what makes all of it correct in both
 * palettes without a single `html.dark` rule — so the test that dark is
 * first-class here is the one that forbids a literal, not one that counts
 * overrides.
 */
final class ComponentContractTest extends ContractTestCase
{
    /**
     * THE COMPONENT LIST, typed out for the reason the tokens are: a list
     * derived from the stylesheet agrees with the stylesheet.
     *
     * These are the ENTRY classes — the name a module writes on an element.
     * Their parts (`.kpi .sub`, `.rdf-page .pg`, `.tbl .num`) are documented in
     * docs/components.md and are not separately frozen, because a part without
     * its entry is not a thing a module can write.
     *
     * @return list<string>
     */
    public static function contractV1(): array
    {
        return [
            // THE PLATE AND ITS VOCABULARY — shipped since 0.1, listed now.
            'c',            // the card every surface is built from
            'chip',         // the status pill: ok / warn / fail / idle / acc
            'cta',          // the call to action
            'grid',         // the page's column system: g2 / g3 / g4 / g32

            // TYPE AND THE COLOUR WORDS — one word of a sentence, coloured.
            'mono',
            'disp',
            'fog',
            'acc',
            'g',            // ok
            'w',            // warn
            'r',            // fail
            'd',            // dim
            'muted',        // dim, spelled for prose

            // THE CARD'S TAB — a widget says what it is on its own top edge.
            'tab',
            'use',          // and the line under it saying what it is FOR

            // THE WAY BACK — a detail screen's link to the list it came from.
            'backbtn',

            // THE IDENTITY BAND a detail screen opens with.
            'factband',     // .f the fact, .k/.v its halves, .sp and .more the tail

            // The read-only category swatch — a category's colour is shown,
            // never picked.
            'catsw',

            // The one left mark a card may carry, and it means focus.
            'focusline',

            // THE QUIET FORWARD LINK AND THE MODULE DOT — both were defined
            // only inside one parent (`.factband .more`, `.ntree .ntm .mdot`)
            // and both are written elsewhere throughout the core: a `.more`
            // at the end of a card's row came out blue and underlined, and a
            // dot in a table of modules came out as nothing at all. The
            // contract describes what the core actually writes.
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

            // THE KPI PLATE, and the strip it sits in.
            'kpi',
            'kstrip',

            // THE REGISTER TABLE, THE META ROW, and the pager under them.
            'tbl',
            'rln',          // a label and its value, dashed between
            'rdf-foot',
            'rdf-page',

            // ONE THING THAT NEEDS SOMEBODY, as a row: the queue three
            // surfaces draw from three owners' items. Hoisted out of the
            // area overview's own sheet when the second surface needed it.
            'ao-att',

            // THE PERSON'S MARK, AND THE TWO QUIET BUTTONS.
            'avatar',
            'open-btn',
            'tgl',

            // THE FORM FIELD every filter/search input is drawn as.
            'fld',

            // a form's actions, as the last row of its own body
            'staddrow',

            // THE HOUSE CREATE CARD — a register creates the thing it lists at
            // the top of its own page, in this card, never in a second form.
            // Three sheets carried a drifted copy of it before it was said here.
            'dcadd',        // the heading that names what is being created
            'crcard',       // the card itself: .crgrid > .crfield > .crlab
            'seg',          // the segmented first choice, where there is one

            // TOP-LEVEL SECTION MARKS — what a section surface (Departments,
            // Team, Files) needs that the rest of the vocabulary does not
            // already own. A section is a house surface, so these are house
            // marks and not a sheet only three pages load.
            'sxmore',       // the bound: what is not shown, and the door to it
            'sxmx',         // the attachment matrix's table; its dot and key are the atlas's
            'sxdoors',      // the onward surfaces at the foot of an overview
            'sxdoor',
            'sxfoot',       // a card's footer strip
            'sxgrp',        // the band a grouped table breaks on
            'sxlead',       // a card's framing line and its hand-over control
            'sxq',          // a vocabulary row's quiet edit
            'soon',         // a strip entry named but not drawn yet

            // A FIGURE'S MOVEMENT against the previous period, in a KPI's sub.
            'delta',
        ];
    }

    public function testTheComponentListIsExactlyThis(): void
    {
        self::assertSame(self::contractV1(), LayoutContract::COMPONENTS, <<<'WHY'
            A component class was added, removed or renamed. Module templates
            across the platform write these names — the same change policy
            applies as to the blocks and the tokens (see
            docs/changing-the-contract.md).
            WHY);
    }

    /**
     * Every promised class is actually styled. The list is a promise; the
     * stylesheet is the keeping of it. The failure this catches is a live page
     * whose KPI plates render as one line of running text because the class is
     * written and defined nowhere.
     */
    #[DataProvider('components')]
    public function testEveryPromisedComponentIsStyled(string $class): void
    {
        self::assertMatchesRegularExpression(
            '/\.'.preg_quote($class, '/').'(?![\w-])/',
            $this->stylesheet(),
            \sprintf('.%s is promised by the contract and styled nowhere.', $class),
        );
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function components(): \Generator
    {
        foreach (self::contractV1() as $class) {
            yield $class => [$class];
        }
    }

    /**
     * THE QUIET DOOR CARRIES NO PADDING OF ITS OWN, and the band's belongs to
     * the band.
     *
     * `.more` is written in three places — at the end of an identity band, at
     * the end of a card's own row, and, pinned to a card's top edge, as the
     * card's one quiet door. The band's `9px 15px` sat on the BASE rule, so
     * the first bare `.more` written outside a card or a band came out taller
     * and wider than the row holding it. A component's base rule carries what
     * the component IS; where it sits is the place's business.
     */
    public function testTheQuietDoorTakesItsPaddingFromWhereItSitsAndNotFromTheBaseRule(): void
    {
        $css = $this->stylesheet();

        $base = preg_match('/(?:^|\})\s*\.more\s*\{([^}]*)\}/m', $css, $found) ? $found[1] : '';
        self::assertNotSame('', $base, 'the base rule is there to be checked');
        self::assertStringNotContainsString('padding', $base, 'A bare door pads itself out of its own row.');

        self::assertStringContainsString('.factband .more { padding: 9px 15px; }', $css, "the band's own spacing");

        // And the card's door keeps the pill it has always drawn.
        self::assertMatchesRegularExpression('/\.c > \.more \{[^}]*padding: 3px 9px;/s', $css);
    }

    /**
     * A RUNNING STATE IS THE FILLED ACCENT, AND A READY ONE THE OUTLINE.
     *
     * Ruled 2026-09-21: "the accent, since it is reserved for live". The two
     * are one glance apart on purpose — a thing that is running and a thing
     * that is ready to run are both the accent, told apart by weight and not
     * by hue — and the shell ships both so a module writes the class instead
     * of the colour.
     */
    public function testARunningStatusIsTheFilledAccentBesideTheOutlineOne(): void
    {
        $css = $this->stylesheet();

        self::assertStringContainsString(
            '.chip.run { color: rgb(var(--c-accT)); background: rgb(var(--c-acc)); border-color: rgb(var(--c-acc)); }',
            $css,
            'A running state is the accent, filled.',
        );
        self::assertStringContainsString(
            '.chip.acc { color: rgb(var(--c-acc)); border-color: color-mix(in srgb, rgb(var(--c-acc)) 45%, transparent); }',
            $css,
            'And ready keeps the outline, so the two are a glance apart.',
        );
    }

    /**
     * THE LIVE DOT IS ONE PRIMITIVE IN TWO PRESENTATIONS, and the sheet has to
     * carry both or a module ends up drawing its own.
     *
     * On a plate the mark is an SVG group in the PLATE palette, because
     * imagery is dark in both themes; inline in a list or a legend row it is
     * an `<i>` in the THEME palette, because those sit on the page ground. A
     * sheet that shipped only one of them would send the other's author to
     * pick a colour, and the accent is reserved for exactly this.
     */
    public function testTheLiveDotIsDrawnForThePlateAndForAList(): void
    {
        $css = $this->stylesheet();

        self::assertStringContainsString('svg .livedot .lv-core', $css, 'the mark on a plate');
        self::assertStringContainsString('svg .livedot.stale .lv-core', $css, 'and the same mark, stale');
        self::assertStringContainsString('i.livedot', $css, 'the mark inline in a row');
        self::assertStringContainsString('i.livedot.stale', $css);
        self::assertStringContainsString('i.livedot.none', $css, 'and the outline of one, for nobody');
        self::assertStringContainsString('@keyframes lv-breathe', $css, 'the ring is what breathes');
    }

    /**
     * AND THE BREATHING IS OPTIONAL WHERE MOTION IS. The ring stays either
     * way, because the ring is information; the animation is only a way of
     * noticing it, and a reader who has asked for less motion has not asked
     * to be told less.
     */
    public function testTheLiveDotKeepsItsRingWhenMotionIsRefused(): void
    {
        $css = $this->stylesheet();

        $reduced = preg_match(
            '~@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{(.+?)\n\}~s',
            $css,
            $match,
        ) ? $match[1] : '';

        self::assertStringContainsString('.lv-ring', $reduced, 'the plate mark stops breathing');
        self::assertStringContainsString('i.livedot::after', $reduced, 'and so does the inline one');
        self::assertStringContainsString('animation: none', $reduced);
        self::assertStringNotContainsString('display: none', $reduced, 'the ring itself never goes');
    }

    /**
     * THE COMPONENTS SPEND TOKENS AND NAME NO COLOUR. This is the whole reason
     * there is no dark half of the component section: a rule written as
     * `color: rgb(var(--c-fog))` is already correct under both palettes, and
     * the first `#8a8a8a` in this file is the first component that will look
     * wrong after dark on somebody else's page.
     */
    public function testNoComponentRuleNamesAColourOfItsOwn(): void
    {
        $section = $this->componentSection();

        self::assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}\b|\brgba?\(\s*\d/',
            $section,
            'A component named a literal colour. Spend a token, or it is wrong in one of the two palettes.',
        );
    }

    /**
     * AND THEY ARE UNSCOPED, on purpose. A module's sheet loads after this one
     * and may override; what it must not have to do is opt in. A component
     * section scoped to a shell wrapper would be a vocabulary only the shell's
     * own pages could speak, which is the opposite of the point.
     */
    #[DataProvider('components')]
    public function testTheComponentsAreNotScopedToTheShellsOwnPages(string $class): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/^\s*(?:\.shell|\.page|\.welcome)\b[^{,]*\.'.preg_quote($class, '/').'(?![\w-])/m',
            $this->componentSection(),
            \sprintf('.%s is only styled inside the shell\'s own furniture; a module cannot use it.', $class),
        );
    }

    /**
     * THE FRAME'S OWN BODY WRAPPER IS STYLED. `.pgbody` is emitted by the page
     * frame on every screen the platform draws and, until this release, was
     * styled by nobody — so a module's first element inherited whatever margin
     * it happened to carry and collapsed it through the wrapper into the frame,
     * which is why the gap under a page's tabs moved depending on what the
     * module put first. It is furniture, not vocabulary: the shell writes it,
     * a module never does, which is why it is not on the frozen list above.
     */
    public function testTheFramesPageBodyWrapperIsStyled(): void
    {
        self::assertMatchesRegularExpression(
            '/\.pgbody\s*\{/',
            $this->stylesheet(),
            'The frame emits .pgbody on every page. A class the shell writes and nobody styles is a class that behaves differently on every module.',
        );
    }

    /**
     * THE PAGE-ACTION ROW IS THE FRAME'S, WHOLE. `page.html.twig` writes
     * `<div class="pgact">` itself and gives a module filling
     * `shell_page_actions` no way to add a class to it, so the row's layout can
     * only be stated here. A module that needs two actions side by side and
     * finds `.pgact` is not a row has one move left — restate `.pgact` in its
     * own sheet — and that is the drift the vocabulary conformance test forbids.
     *
     * The values are the design's, read from the two sheets that between them
     * define the row: the slot in nav.css and the row in widgets.css, which
     * every screen wears together as `class="pgact w-pgact"` (129 of the design's
     * 136 page heads). Where the two disagree — the icon size — widgets.css is
     * linked after nav.css at equal specificity and wins, so 14px is what the
     * design renders.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/nav.css lines 86-89
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/widgets.css lines 229-246
     */
    #[DataProvider('pageActionRowDeclarations')]
    public function testTheFrameLaysOutThePageActionRow(string $selector, string $property, string $value): void
    {
        $rule = $this->rule($selector);

        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $rule,
            \sprintf(
                '%s must state `%s: %s` — the design\'s own value. Without it a module with two page '
                .'actions has to restate the row in its own sheet, and the same header renders differently '
                .'on every screen that does.',
                $selector,
                $property,
                $value,
            ),
        );
    }

    /**
     * EVERY CONTROL IN THE ROW SHARES ONE BASELINE, and this is the test
     * that says so in numbers.
     *
     * A page head may hold a bare button, a labelled select and a
     * segmented group at once. They are three different kinds of thing
     * and they must read as one row: the same 32px box, bottom-aligned,
     * with any caption in a line above rather than inside. The rule
     * lives in the frame — `page.html.twig` writes the wrapper and a
     * module cannot add a class to it — so a module that needs a
     * labelled control gets the alignment without asking.
     */
    #[DataProvider('oneBaselineDeclarations')]
    public function testEveryControlInThePageActionRowSharesOneBaseline(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s`, or the row reads as three heights on one line.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function oneBaselineDeclarations(): \Generator
    {
        $declarations = [
            // A LABELLED CONTROL IS A COLUMN: caption above, box below.
            // UNSCOPED NOW: the scope control is the shell's, so a contributed
            // organization-level page can draw one outside `.pgact` too.
            '.ov-ctl' => ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '4px'],
            // AND THE SEGMENTED GROUP AND THE CHIP BESIDE IT are the row's
            // own height, like everything else in it.
            '.pgact .periodpick' => ['height' => '32px', 'box-sizing' => 'border-box'],
            '.pgact .mchip' => ['height' => '32px', 'box-sizing' => 'border-box'],
            // The buttons inside the group fill it without growing it.
            '.pgact .periodpick button' => ['height' => '30px', 'border-radius' => '0'],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield \sprintf('%s { %s }', $selector, $property) => [$selector, $property, $value];
            }
        }
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function pageActionRowDeclarations(): \Generator
    {
        $declarations = [
            // The row itself: a wrapping line of equal-height controls, held to
            // its content beside a title that may run long.
            '.pgact' => [
                'display' => 'flex',
                // THE ROW ALIGNS ON ITS BOTTOM EDGE. A labelled control — a
                // caption above a field — is taller than a bare button, so a
                // row centred on its middle put Configure half a caption
                // higher than the selects beside it, which is the
                // misalignment the owner caught on the performance header.
                // Every box is 32px, so aligning bottoms aligns tops.
                'align-items' => 'flex-end',
                'flex-wrap' => 'wrap',
                'gap' => '9px',
                'flex-shrink' => '0',
                'padding-top' => '4px',
            ],
            // The primary, and the whole reason the row reads as designed: every
            // control in it is one height, and only weight and fill separate the
            // primary from the quiet ones.
            '.pgact .cta' => [
                'height' => '32px',
                'padding' => '0 13px',
                'border-radius' => '9px',
                'font-size' => '12px',
                'font-weight' => '700',
                'white-space' => 'nowrap',
                'text-decoration' => 'none',
            ],
            '.pgact .cta svg' => [
                'width' => '14px',
                'height' => '14px',
            ],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * A CARD'S ACTION CLUSTER IS ONE CLUSTER: the labelled disclosure and
     * the way into the record share a height, a radius and a baseline.
     *
     * BOTH HEIGHTS ARE STATED, not left to type and padding. That is what
     * let `Open` drift to 27 against the disclosure's 24 the day the page's
     * base line-height changed — a pair of controls two pixels out of line
     * reads as a mistake from across the room, and nothing in either rule
     * said what the height was supposed to be.
     */
    #[DataProvider('actionClusterDeclarations')]
    public function testACardsActionClusterIsOneCluster(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s` — the design\'s own value.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function actionClusterDeclarations(): \Generator
    {
        $declarations = [
            '.ov-open' => ['height' => '24px', 'border-radius' => '7px', 'padding' => '0 9px', 'line-height' => '1'],
            '.ovx.xdisc' => ['height' => '24px', 'border-radius' => '7px'],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * A CARD CARRIES AT MOST ONE QUIET DOOR, AND IT IS PINNED TO THE EDGE.
     *
     * The same gesture as the identity band's `.more`, in the same type and
     * colour, drawn where a card carries its way out. Page actions belong in
     * the page header and row actions on the row, so a card with two doors is
     * two cards.
     *
     * IT IS THE SHELL'S BECAUSE IT IS THE CARD'S. The roster's tabs and the
     * house cards all want it; the first module to draw one without it here
     * would pin it with a rule of its own, which is the drift the vocabulary
     * test forbids and exactly why `.more` itself stopped being scoped to
     * `.factband`.
     *
     * `text-transform: none` IS THE ONE LINE THAT IS NOT A COPY. In the design
     * `.more` is band-scoped and the card's door inherits nothing; here `.more`
     * is unscoped on purpose, so without this the door comes out uppercase at a
     * letter-spacing meant for lower case. The inheritance is turned off rather
     * than the base rule bent.
     *
     * The values are the design's, read value for value.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/uhifadhi.css lines 323-333
     */
    #[DataProvider('cardDoorDeclarations')]
    public function testACardsQuietDoorIsPinnedToItsEdge(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s` — the design\'s own value.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function cardDoorDeclarations(): \Generator
    {
        $declarations = [
            '.c > .more' => [
                'position' => 'absolute',
                'top' => '-8px',
                'right' => '13px',
                'z-index' => '2',
                'font-size' => '9.5px',
                'letter-spacing' => '.06em',
                // Not uppercase: the base rule is unscoped here and the design's
                // card door is lower case.
                'text-transform' => 'none',
                'padding' => '3px 9px',
                'border-radius' => '7px',
                'white-space' => 'nowrap',
            ],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * CHOSEN IS FILLED, NOT OUTLINED — and a form's actions are the last row
     * of its body.
     *
     * A ROW OF CHIPS IS SCANNED ALONG ITS LENGTH, and an outline two shades
     * from its neighbours is not a selection anybody sees; the shell drew
     * `.mchip.on` as a tinted border while the design fills it. Same reading
     * as the dropdown's chosen option, which is a tinted ROW rather than
     * coloured text, for the same reason.
     *
     * THE INK GOES WITH THE GROUND. `--c-accT` is what survives on the
     * accent, and the grip and the remove cross take it too — a chip whose
     * label flipped but whose controls stayed dark would read as half-chosen.
     *
     * AND `.staddrow` IS THE FRAME'S. A form inside a configure card ends
     * with its own actions on one hairline, not with a footer strip, which is
     * the card's own furniture and means something else. It was the area's
     * private rule; every configure card in the product wants it.
     *
     * The values are the design's, read value for value.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/uhifadhi.css lines 242-248, 337-340
     */
    #[DataProvider('chosenChipAndFormRowDeclarations')]
    public function testTheChosenChipIsFilledAndAFormsActionsAreItsLastRow(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s` — the design\'s own value.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function chosenChipAndFormRowDeclarations(): \Generator
    {
        $declarations = [
            '.mchip.on' => [
                'color' => 'rgb(var(--c-accT))',
                'background' => 'rgb(var(--c-acc))',
                'border-color' => 'rgb(var(--c-acc))',
            ],
            '.mchip.on .grip' => ['color' => 'rgb(var(--c-accT))'],
            '.staddrow' => [
                'display' => 'flex',
                'align-items' => 'center',
                'gap' => '9px',
                'flex-wrap' => 'wrap',
                'margin-top' => '11px',
                'padding-top' => '11px',
                'border-top' => '1px solid var(--c-ln)',
            ],
            '.staddrow .fld' => ['flex' => '1 1 200px', 'min-width' => '160px'],
            '.staddrow .sp' => ['flex' => '1'],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * ONE PALETTE, AND A MODULE NEVER WRITES A COLOUR.
     *
     * Every category in the product — an incident kind, a patrol type, a
     * zone, a department — takes `--cat-n` by its POSITION in its own
     * declared order. The surface writes `data-cat="1".."9"` and reads
     * `var(--cat)`; nothing downstream knows the nine values.
     *
     * THE INDIRECTION IS THE POINT. A module that handed over a hex would
     * be right in one theme and wrong in the other, and wrong again on
     * imagery — which is why `[data-cat]` resolves TWO things, and why
     * `.viewer` overrides one of them.
     *
     * `--cat` FALLS BACK TO THE MUTED TEXT COLOUR, so an unknown category
     * still draws — grey, which is the honest thing for a remainder.
     */
    #[DataProvider('categoryPaletteDeclarations')]
    public function testOnePaletteServesEveryCategory(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s` — the design\'s own value.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function categoryPaletteDeclarations(): \Generator
    {
        $declarations = [
            // The unindexed fallback, and the plate's.
            '[data-cat]' => ['--cat' => 'var(--fog)', '--cat-plate' => 'var(--plate-dim)'],
            '[data-cat="1"]' => ['--cat' => 'var(--cat-1)', '--cat-plate' => 'var(--cat-p-1)'],
            '[data-cat="9"]' => ['--cat' => 'var(--cat-9)', '--cat-plate' => 'var(--cat-p-9)'],
            // Inside a plate the theme stops applying.
            '.viewer [data-cat]' => ['--cat' => 'var(--cat-plate)'],
            // And an SVG authored with the ordinary token is repainted.
            '.viewer [fill="var(--cat-3)"]' => ['fill' => 'var(--cat-p-3)'],
            '.viewer [stroke="var(--cat-3)"]' => ['stroke' => 'var(--cat-p-3)'],
            // The swatch reads the resolved value and nothing else.
            '.catsw' => ['background' => 'var(--cat, var(--fog))', 'width' => '12px'],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * THE NINE ARE DEFINED IN BOTH THEMES AND ON THE PLATE, and the plate
     * set is NOT a theme: satellite imagery is dark whichever way the
     * interface is turned, so `--cat-p-*` is stated once outside both
     * blocks and the dark theme aliases it rather than restating it.
     */
    public function testTheNineAreDefinedForBothThemesAndForImagery(): void
    {
        $sheet = $this->stylesheet();

        for ($n = 1; $n <= PlatePalette::CATEGORIES; ++$n) {
            self::assertMatchesRegularExpression(
                '/--cat-'.$n.'\s*:/',
                $sheet,
                \sprintf('the palette defines --cat-%d', $n),
            );
            self::assertMatchesRegularExpression(
                '/--cat-p-'.$n.'\s*:\s*#[0-9A-Fa-f]{6}/',
                $sheet,
                \sprintf('and --cat-p-%d, the value imagery keeps', $n),
            );
        }

        // The dark theme aliases the plate values rather than restating them.
        self::assertStringContainsString('--cat-1: var(--cat-p-1);', $sheet);
        self::assertStringContainsString('--cat-18: var(--cat-p-18);', $sheet);
    }

    /**
     * PAST NINE, A SECOND LIGHTNESS RING — ruled 2026-09-21, and the point of
     * it is that it is a RING and not a repeat: eighteen positions, eighteen
     * distinct values, in each of the three readings.
     *
     * An area with eleven zones — Kilimani Crater has eleven — got nine colours
     * and two repeats before this, so two zones at opposite ends of a plate
     * drew the same ring and the key beside it said two different names.
     */
    public function testTheRingIsEighteenDistinctValuesInEveryReading(): void
    {
        $sheet = $this->stylesheet();

        foreach (['--cat-p-' => 'on imagery', '--cat-' => 'on paper'] as $prefix => $where) {
            $values = [];
            for ($n = 1; $n <= PlatePalette::CATEGORIES; ++$n) {
                $stated = 1 === preg_match('/'.preg_quote($prefix.$n, '/').'\s*:\s*(#[0-9A-Fa-f]{6})\s*;/', $sheet, $found)
                    ? $found[1]
                    : null;

                self::assertNotNull($stated, \sprintf('%s%d is a stated value %s', $prefix, $n, $where));
                $values[] = strtoupper($stated);
            }

            self::assertSame(
                PlatePalette::CATEGORIES,
                \count(array_unique($values)),
                \sprintf('Two of the eighteen read the same %s, which is a repeat and not a ring.', $where),
            );
        }
    }

    /**
     * AND EVERY ONE OF THE EIGHTEEN IS ASSIGNED AND REPAINTED. A token nobody
     * can reach through `[data-cat]` is a token that does not exist, and a
     * plate marker written as `fill="var(--cat-14)"` with no repaint rule is
     * a mark drawn in the paper reading on top of imagery.
     */
    public function testEveryPositionIsAssignedAndRepaintedOnAPlate(): void
    {
        $sheet = $this->stylesheet();

        for ($n = 1; $n <= PlatePalette::CATEGORIES; ++$n) {
            self::assertStringContainsString(
                \sprintf('[data-cat="%d"] { --cat: var(--cat-%d); --cat-plate: var(--cat-p-%d); }', $n, $n, $n),
                $sheet,
            );
            self::assertStringContainsString(\sprintf('.viewer [fill="var(--cat-%d)"] { fill: var(--cat-p-%d); }', $n, $n), $sheet);
            self::assertStringContainsString(\sprintf('.viewer [stroke="var(--cat-%d)"] { stroke: var(--cat-p-%d); }', $n, $n), $sheet);
        }
    }

    /**
     * AND A DEPARTMENT IS A CATEGORY LIKE ANY OTHER. The nine `--dept-*`
     * tokens are ALIASES of the nine, not a second palette — a department
     * owns no colour of its own.
     */
    public function testTheDepartmentTokensAreAliasesAndNotASecondPalette(): void
    {
        $sheet = $this->stylesheet();

        self::assertStringContainsString('--dept-ecology:               var(--cat-1);', $sheet);
        self::assertStringContainsString('--dept-veterinary-services:   var(--cat-9);', $sheet);

        self::assertDoesNotMatchRegularExpression(
            '/--dept-[a-z-]+:\s*#[0-9A-Fa-f]{3,6}/',
            $sheet,
            'a department that named its own colour would be a second palette',
        );
    }

    /**
     * THE FOCUS LINE IS THE ONE LEFT MARK A CARD MAY CARRY.
     *
     * RULED 2026-09-21. It means FOCUS — this is the card the reader is on.
     * Not open, not selected-and-showing, and never a CATEGORY: a category is
     * a chip or an 8px hue dot, a state is a chip or a stamp. One class draws
     * every focus mark in the product, so a preset card and a register card
     * cannot come out two different widths.
     *
     * PAINT ONLY, AND THAT IS WHY IT IS A PSEUDO-ELEMENT. A border would add
     * to the box and shove everything below the card down the moment focus
     * arrived; `::after` over the card's own border line changes nothing.
     *
     * INSET BY THE RADIUS. 13px top and bottom is the card's corner radius,
     * so the line starts where the top corner ends and stops where the bottom
     * corner begins rather than crossing them.
     *
     * The values are the design's, read value for value.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/uhifadhi.css lines 323-325
     */
    #[DataProvider('focusLineDeclarations')]
    public function testTheFocusLineIsTheOneLeftMarkACardMayCarry(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf('%s must state `%s: %s` — the design\'s own value.', $selector, $property, $value),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function focusLineDeclarations(): \Generator
    {
        $declarations = [
            // The card is the containing block, or the line lands on the page.
            '.focusline' => ['position' => 'relative'],
            '.focusline::after' => [
                'content' => '""',
                'position' => 'absolute',
                // On the card's own border line, not beside it.
                'left' => '-1px',
                // Inset by the card's 13px radius, top and bottom.
                'top' => '13px',
                'bottom' => '13px',
                'width' => '2px',
                'border-radius' => '2px',
                // Never a hue: focus is the accent, and a category is not focus.
                'background' => 'rgb(var(--c-acc))',
                'z-index' => '2',
                // It is a mark, not a target.
                'pointer-events' => 'none',
            ],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * AND IT IS PAINT, NOT BOX. A border on `.focusline` itself would move
     * every card below it the moment focus arrived, which is the whole reason
     * the mark is a pseudo-element.
     */
    public function testTheFocusLineAddsNothingToTheBox(): void
    {
        $rule = $this->rule('.focusline');

        self::assertDoesNotMatchRegularExpression(
            '/(?:^|;)\s*(?:border|padding|margin|width)\s*:/',
            $rule,
            'the focus line is paint: a box change would shove the page down when focus arrives.',
        );
    }

    /**
     * A BUTTON IS NOT AN ANCHOR THAT HAPPENS TO BE PRESSABLE.
     *
     * `.btn` and `.cta` are written on all three of `<a>`, `<button>` and
     * `<input type="submit">` across the product — a link out of a card, a
     * Discard beside a Save, a form that submits. The user agent gives the
     * last two a ground, a border, a font and a line box of their own, and
     * every one of them has to be turned off in the rule or the pair renders
     * as one house control beside one browser-grey button.
     *
     * `.cta` WAS THE ONE THAT GOT IT WRONG: it named no font at all, so the
     * accent button came out in the system font wherever a form submitted
     * rather than linked. `.btn` had the font and still had no `appearance`
     * and no line-height.
     *
     * LINE-HEIGHT IS `inherit`, NOT A NUMBER. A button's UA line-height is
     * `normal` and an anchor takes the page's, which is what sat the two a
     * few pixels apart; inheriting makes the button match the anchor and
     * moves the anchor not at all, where a stated number would move both.
     *
     * WHAT THIS PROMISES AND WHAT IT DOES NOT. It is a check over the SHEET,
     * like the plate's height: it says the rule carries the declarations that
     * make the three element types render alike, and — with the sibling test
     * below — that nothing narrows the rule to one of them. It cannot say the
     * three COMPUTE alike, because no browser runs here; that is a sweep.
     */
    #[DataProvider('buttonNeutraliserDeclarations')]
    public function testTheButtonRulesReachEveryElementTypeTheyAreWrittenOn(string $selector, string $property, string $value): void
    {
        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $this->rule($selector),
            \sprintf(
                '%s must state `%s: %s`, or a <button> wearing it renders as the browser\'s own.',
                $selector,
                $property,
                $value,
            ),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function buttonNeutraliserDeclarations(): \Generator
    {
        // What a user agent supplies for a <button> and would otherwise win:
        // the chrome, the font, and the line box. Stated once on the shared
        // base and therefore true of all three controls — which is what puts
        // a Discard, a Save and a toggle on ONE baseline whatever element
        // each is written on. A lookup by single selector collects the shared
        // block and the control's own, so this asks the question the page
        // asks: does this control end up with the declaration.
        $neutralisers = [
            'appearance' => 'none',
            '-webkit-appearance' => 'none',
            'font' => 'inherit',
            'line-height' => '1.15',
            'min-height' => '32px',
            'box-sizing' => 'border-box',
        ];

        foreach (['.btn', '.cta', '.tgl'] as $selector) {
            foreach ($neutralisers as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }

        // And the house's own radius, stated on both so neither falls back.
        yield '.btn — border-radius' => ['.btn', 'border-radius', '9px'];
        yield '.cta — border-radius' => ['.cta', 'border-radius', '9px'];
    }

    /**
     * AND NOTHING NARROWS THEM TO AN ELEMENT TYPE.
     *
     * A single `a.btn` anywhere in the chain would make the rule an anchor's
     * rule, and every `<button class="btn">` in the product would quietly
     * stop being a house control. The neutralisers above are only worth
     * stating if the selector they are stated on reaches all three.
     */
    public function testNoSheetNarrowsAButtonRuleToOneElementType(): void
    {
        $narrowed = [];
        foreach (['btn', 'cta'] as $class) {
            if (1 === preg_match('/\b(?:a|button|input)\.'.$class.'\b/', $this->stylesheet(), $match)) {
                $narrowed[] = $match[0];
            }
        }

        self::assertSame(
            [],
            $narrowed,
            'a rule typed to one element is a rule the other two element types do not get.',
        );
    }

    /**
     * A FILTER ROW IS ONE LINE, AND THE PANEL UNDER IT IS ONE PANEL.
     *
     * FOUR BUNDLES DRAW THIS ROW — the incidents register, patrol's list, the
     * zones pages and the stations register — and every one of them writes
     * the shell's classes and nothing of its own. So the row is the shell's,
     * whole: the chip, the search field, the panel, its head, its options and
     * its dots. A module that found the panel here was the wrong panel would
     * restate it in its own sheet, which is the drift the vocabulary test
     * forbids, and four bundles would then have four filter bars.
     *
     * EVERY CONTROL IS 30px ON ONE BASELINE. The chip's own padding computes
     * to 28 and the search field to 32, which sat the row on two tops; inside
     * a filter row both are 30, the vertical padding traded for a line-height.
     *
     * THE LABEL IS NOT CLIPPED. A chip reading "all categories · 47" loses the
     * count to an ellipsis the moment the label is truncated, and the count is
     * the half the reader is filtering on.
     *
     * THE CHOSEN OPTION IS A TINTED ROW, not coloured text: a panel of twelve
     * options is scanned down its left edge, and a word two shades different
     * from its neighbours is not a selection anybody sees.
     *
     * The values are the design's, read value for value.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/uhifadhi.css lines 234-256, 1268-1278
     */
    #[DataProvider('filterRowDeclarations')]
    public function testTheFrameDrawsTheFilterRowAndItsDropdown(string $selector, string $property, string $value): void
    {
        $rule = $this->rule($selector);

        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $rule,
            \sprintf(
                '%s must state `%s: %s` — the design\'s own value. Four bundles draw this row, and one '
                .'of them finding it wrong here restates it in its own sheet.',
                $selector,
                $property,
                $value,
            ),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function filterRowDeclarations(): \Generator
    {
        $declarations = [
            // The row, and the one rule that puts every control on one line.
            '.lfilt' => [
                'display' => 'flex',
                'gap' => '8px',
                'align-items' => 'center',
            ],
            // Asserted a side at a time, because a grouped prelude is read
            // one selector at a time — and both sides have to carry it, which
            // is the whole point of the rule.
            '.lfilt .mchip' => [
                'height' => '30px',
                'padding-top' => '0',
                'padding-bottom' => '0',
                'line-height' => '28px',
            ],
            '.lfilt .lsearch .fld' => [
                'height' => '30px',
                'line-height' => '28px',
            ],
            // The search sits at the far end of the row, at one width.
            '.lsearch' => [
                'margin-left' => 'auto',
            ],
            '.lsearch .fld' => [
                'width' => '246px',
            ],
            // The trigger and its caret.
            '.i-dd' => [
                'display' => 'inline-flex',
            ],
            '.i-ddcaret' => [
                'font-size' => '9px',
                'opacity' => '.7',
            ],
            // The panel.
            '.i-ddmenu' => [
                'min-width' => '196px',
                'padding' => '5px',
                'gap' => '1px',
                'border-radius' => '11px',
            ],
            '.i-ddhead' => [
                'font-size' => '8.5px',
                'letter-spacing' => '.14em',
                'padding' => '6px 9px 4px',
            ],
            '.i-ddopt' => [
                'gap' => '9px',
                'font-size' => '12px',
                'padding' => '7px 9px',
                'border-radius' => '7px',
            ],
            '.i-ddopt-l' => [
                'flex' => '1',
                'white-space' => 'nowrap',
            ],
            '.i-ddopt-n' => [
                'font-size' => '10.5px',
            ],
            '.i-ddsep' => [
                'height' => '1px',
                'margin' => '4px 2px',
            ],
            '.i-dot' => [
                'width' => '8px',
                'height' => '8px',
                'border-radius' => '2px',
            ],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * THE CHOSEN OPTION IS A TINTED ROW. Stated apart from the values above
     * because it is the one rule of the family that is a DECISION rather than
     * a measurement: the design tints the row and accents its count, and an
     * implementation that coloured the label instead would pass every size
     * assertion and still not read as a selection.
     */
    public function testTheChosenOptionIsATintedRowAndNotColouredText(): void
    {
        self::assertMatchesRegularExpression(
            '/background:\s*color-mix\(in srgb,\s*rgb\(var\(--c-acc\)\)\s*12%/',
            $this->rule('.i-ddopt.on'),
            'A panel of twelve options is scanned down its left edge; a word two shades different is not a selection anybody sees.',
        );

        self::assertMatchesRegularExpression(
            '/color:\s*rgb\(var\(--c-acc\)\)/',
            $this->rule('.i-ddopt.on .i-ddopt-n'),
            'The count on the chosen row carries the accent, so the row reads as one thing.',
        );
    }

    /**
     * THE EVIDENCE TILE SHOWS THE PICTURE STORAGE MADE. The tile family is the
     * frame's, and the module that keeps files emits the markup for it: one
     * thumbnail per photograph, drawn in the same `.sh` shell wherever the file
     * is listed, so one file looks like itself on an incident and in the files
     * hub. Until these rules shipped here, the module had markup the frame did
     * not style — a picture layer with no box, a state pill with no pill — and
     * the module's only move was to restate the family in its own sheet, which
     * is the drift the vocabulary conformance test forbids.
     *
     * The values are the design's, read value for value.
     *
     * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/uhifadhi.css lines 1117-1120, 1139-1159
     */
    #[DataProvider('evidenceTileDeclarations')]
    public function testTheFrameDrawsTheEvidenceTilesThumbnailAndItsStates(string $selector, string $property, string $value): void
    {
        $rule = $this->rule($selector);

        self::assertMatchesRegularExpression(
            '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/',
            $rule,
            \sprintf(
                '%s must state `%s: %s` — the design\'s own value. Without it the module that ships the '
                .'markup has to restate the tile family in its own sheet.',
                $selector,
                $property,
                $value,
            ),
        );
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function evidenceTileDeclarations(): \Generator
    {
        $declarations = [
            // The picture layer: a link filling the tile, clipped to the tile's
            // own radius rather than to a radius of its own.
            '.upl-tile.done .sh' => [
                'position' => 'absolute',
                'inset' => '0',
                'display' => 'block',
                'border-radius' => 'inherit',
                'overflow' => 'hidden',
                'text-decoration' => 'none',
            ],
            '.upl-tile.done .sh img' => [
                'width' => '100%',
                'height' => '100%',
                'object-fit' => 'cover',
                'display' => 'block',
            ],
            // It is a link, so it is reachable by keyboard and says so inside
            // its own edge — an outline outside it would be clipped away.
            '.upl-tile.done .sh:focus-visible' => [
                'outline' => '2px solid var(--acc)',
                'outline-offset' => '-2px',
            ],
            // The radial ground is the EMPTY photo slot; under an actual picture
            // a flat ground is what a transparent thumbnail shows through to.
            '.upl-tile.done.shot' => [
                'background' => '#20241F',
            ],
            // Nothing to look at yet, so the tile does not pretend to open.
            '.upl-tile.done.making' => ['cursor' => 'default'],
            '.upl-tile.done.nothumb' => ['cursor' => 'default'],
            // The state pill, on the remove control's line.
            '.upl-tile .th' => [
                'position' => 'absolute',
                'left' => '5px',
                'top' => '5px',
                'height' => '20px',
                'text-transform' => 'uppercase',
            ],
            '.upl-tile.making .th' => [
                'color' => '#F0C368',
                'border-color' => 'rgba(240, 195, 104, .45)',
            ],
            '.upl-tile.nothumb .th' => [
                'color' => '#B9C6BB',
                'border-style' => 'dashed',
            ],
            // A kept DOCUMENT is not a photograph: the same box, the interface's
            // ground, and the chrome in tokens rather than in the photo overlay's
            // literals.
            '.upl-tile.done.doc' => [
                'background' => 'color-mix(in srgb, var(--fog) 8%, transparent)',
                'color' => 'var(--fog)',
            ],
            '.upl-tile.done.doc .fn' => [
                'color' => 'var(--fog)',
                'background' => 'none',
                'padding' => '0',
            ],
            '.upl-tile.done.doc .rm' => [
                'background' => 'var(--cv)',
                'border-color' => 'var(--ln2)',
                'color' => 'var(--fog)',
            ],
            '.upl-tile.done.doc .rm:hover' => [
                'color' => 'var(--fail)',
                'border-color' => 'color-mix(in srgb, var(--fail) 55%, transparent)',
            ],
        ];

        foreach ($declarations as $selector => $properties) {
            foreach ($properties as $property => $value) {
                yield $selector.' — '.$property => [$selector, $property, $value];
            }
        }
    }

    /**
     * NO STATE CHANGES THE TILE'S SIZE OR ITS RADIUS — the promise the family's
     * own comment makes, and the reason a grid of tiles does not jump under the
     * pointer as one of them finishes uploading or grows a thumbnail. The box is
     * stated once, on `.upl-tile`; every state rule may change the ground, the
     * border colour and what is inside, and nothing else.
     *
     * `border-radius: inherit` on the picture layer is the one exception and is
     * the opposite of a drift: it takes the tile's radius rather than naming one.
     */
    public function testNoStateOfTheTileRestatesTheBox(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());

        preg_match_all('/([^{}@]*\.upl-tile[^{}@]*)\{([^{}]*)\}/s', $css, $matches, \PREG_SET_ORDER);
        self::assertNotSame([], $matches, 'The tile family ships in the frame\'s sheet.');

        $offenders = [];
        foreach ($matches as $match) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $match[1]));
            if ('.upl-tile' === $selector) {
                continue;
            }

            foreach (['aspect-ratio', 'width', 'height', 'border-radius'] as $property) {
                if (1 !== preg_match('/(?:^|;)\s*'.$property.'\s*:\s*([^;]+)/', $match[2], $stated)) {
                    continue;
                }

                // The parts inside the box have their own size; only a rule on
                // the tile itself would move the grid.
                if (!str_ends_with($selector, '.upl-tile') && !preg_match('/\.upl-tile[\w.]*$/', $selector)) {
                    continue;
                }

                if ('inherit' === trim($stated[1])) {
                    continue;
                }

                $offenders[] = $selector.' { '.$property.': '.trim($stated[1]).' }';
            }
        }

        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            'A state restates the tile\'s box: [%s]. A tile that resized while uploading would make the '
            .'grid jump under the pointer.',
            implode(', ', $offenders),
        ));
    }

    /**
     * The declarations of one rule, by exact selector. A selector written as
     * part of a comma-separated group counts: the group is how a sheet states
     * one rule for several selectors, and the row's height is stated that way.
     */
    private function rule(string $selector): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
        $quoted = preg_quote($selector, '/');

        // A prelude cannot contain a brace, so it starts where the rule before
        // it ended without the two rules having to share the brace between them.
        preg_match_all('/([^{}@]*)\{([^{}]*)\}/s', $css, $matches, \PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            foreach (explode(',', $match[1]) as $written) {
                if (1 === preg_match('/^\s*'.$quoted.'\s*$/', (string) preg_replace('/\s+/', ' ', $written))) {
                    $declarations[] = trim($match[2]);
                }
            }
        }

        self::assertNotSame([], $declarations, $selector.' is stated nowhere in the frame\'s sheet.');

        return implode(';', $declarations);
    }

    /**
     * The component section, delimited by its own banner so the two tests above
     * judge the vocabulary rather than the whole sheet — which does name
     * colours, in the one place it is allowed to: the palettes.
     */
    private function componentSection(): string
    {
        $css = $this->stylesheet();

        $start = strpos($css, self::SECTION);
        self::assertIsInt($start, 'The component vocabulary ships in a section of its own, so it can be read as one.');

        $end = strpos($css, '/* ====', $start + \strlen(self::SECTION));

        return false === $end ? substr($css, $start) : substr($css, $start, $end - $start);
    }

    private const string SECTION = 'THE COMPONENT VOCABULARY';

    /**
     * A STRIP OF FIGURES IS FOUR TO A ROW — ruled, and stated rather
     * than left to `auto-fit`.
     *
     * FIVE COLLAPSES ON A SMALL LAPTOP: the fifth plate wraps alone and
     * the row reads as four and an orphan. Eight is two rows of four,
     * which is why the count is stated once here and a surface that
     * needs more figures takes another row rather than a narrower
     * column.
     */
    public function testAStripOfFiguresIsFourToARow(): void
    {
        self::assertMatchesRegularExpression(
            '/\.kstrip \{[^}]*grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\)/',
            $this->stylesheet(),
            'A strip that folds on its own content width cannot promise four, and five is what it drew.',
        );
    }

    /**
     * THE LAST ROW BEFORE A FOOT DRAWS NO RULE OF ITS OWN.
     *
     * A card reads as a header, a body and a foot, and the foot announces
     * itself with a rule across the top. The body's rows are separated by
     * rules too, so without this the last row draws one and the foot draws
     * another a pixel below it — two lines where the design has one, on every
     * bounded card in the product.
     *
     * IT IS THE ROW THAT GIVES ITS RULE UP, not the foot. The foot's rule is
     * what says foot, and a card whose body drew no rules at all would lose
     * the boundary if the foot gave its own away.
     *
     * The test names every row idiom that can sit against a foot, because the
     * failure this catches is a fourth one being added later and nobody
     * remembering the rule exists.
     *
     * @param string $row  the row idiom, as its selector
     * @param string $foot the foot it can sit against
     */
    #[DataProvider('everyRowAgainstAFoot')]
    public function testTheLastRowBeforeAFootDrawsNoRuleOfItsOwn(string $row, string $foot): void
    {
        self::assertMatchesRegularExpression(
            '/'.preg_quote($row, '/').':has\(\+\s*'.preg_quote($foot, '/').'\)/',
            $this->stylesheet(),
            \sprintf(
                '`%s` sitting directly before `%s` keeps its own bottom rule, so the card draws two lines where it has one. '.
                'The row drops its rule; the foot keeps its own, because the foot\'s rule is what says foot.',
                $row,
                $foot,
            ),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyRowAgainstAFoot(): iterable
    {
        foreach (['.rln' => 'a meta row', '.ao-att' => 'a queue row'] as $row => $what) {
            foreach (['.sxfoot', '.pvfoot'] as $foot) {
                yield $what.' before '.$foot => [$row, $foot];
            }
        }
    }

    /**
     * A REGISTER TABLE IS THE THIRD IDIOM, and its last row is a cell rather
     * than the element beside the foot — so the rule reaches through the
     * table, and through the scroller a wide table is wrapped in.
     */
    public function testATablesLastRowBeforeAFootDrawsNoRuleEither(): void
    {
        $sheet = $this->stylesheet();

        foreach (['table.tbl:has(+ .sxfoot) tr:last-child td', '.tm-scroll:has(+ .sxfoot) table.tbl tr:last-child td'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $sheet,
                'A register table against a card foot draws its last rule and then the foot draws one under it.',
            );
        }
    }
}
