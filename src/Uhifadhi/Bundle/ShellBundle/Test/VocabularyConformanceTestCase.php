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

namespace Uhifadhi\Bundle\ShellBundle\Test;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;

/**
 * NOTHING MAY SPEND A NAME NOBODY SHIPS — the build failure that stands in for
 * the rendered page nobody looked at.
 *
 * A bundle draws with two vocabularies it does not own outright: the classes in
 * the stylesheets a page links, and the icons in the sets somebody registered.
 * Both fail SILENTLY. A class no sheet defines does not throw — the element
 * falls back to browser defaults, which look almost right on a developer's
 * machine, where the design's own sheet happens to be open in another tab, and
 * plainly wrong on an installation. An icon whose file nothing ships does not
 * throw either, on a deployment with no outbound network: it is an empty box.
 * Neither is caught by a functional test, because both render a 200.
 *
 * So they are caught here, by reading what the bundle SHIPS and comparing it to
 * what the bundle WRITES.
 *
 * THE TWO CHECKS.
 *
 *   STYLESHEET. Every class a template writes is defined somewhere in the chain
 *   the page actually links — the shell's sheet, this bundle's own, and any
 *   dependency's it links beside them — or named in this bundle's own
 *   JavaScript, because a hook a controller toggles is shipped as surely as a
 *   rule is. And this bundle's sheet REDEFINES no selector the chain already
 *   carries: two definitions of one component load in whichever order the page
 *   happens to link them, and the same control renders differently on two
 *   screens.
 *
 *   ICON. Every icon reference uses a prefix this bundle is allowed to use —
 *   its own alias, and `shell:`, because a page rendered inside the shell may
 *   reuse the shell's marks. Any other prefix fails, `lucide:` included: a
 *   public library's prefix belongs to the installation, which may answer it
 *   with its own artwork or not answer it at all. And every reference under
 *   this bundle's own prefix resolves to a file in the directory the bundle
 *   registers, with nothing to fetch from.
 *
 * HOW A BUNDLE ADOPTS IT. One file in the bundle's suite:
 *
 *     final class VocabularyConformanceTest extends VocabularyConformanceTestCase
 *     {
 *         protected static function bundlePath(): string { return \dirname(__DIR__, 2); }
 *         protected static function alias(): string { return 'sightings'; }
 *         protected static function ownStylesheets(): array { return ['sightings.css']; }
 *     }
 *
 * WHY IT SHIPS IN src/ RATHER THAN IN THIS BUNDLE'S OWN SUITE. A module has to
 * be able to autoload it, and a package's tests are excluded from its
 * classmap. A test base a consumer extends is part of the package's published
 * surface, so it lives beside the code and is exported with it.
 *
 * @see vendor/symfony/framework-bundle/Test/KernelTestCase.php — the same
 *      arrangement: a base class shipped in the package for consumers to
 *      extend, with the test framework left to whoever runs tests.
 */
abstract class VocabularyConformanceTestCase extends TestCase
{
    /**
     * The root of the bundle under test — the directory its composer.json sits
     * in, from which `templates/`, `assets/` and `public/` are read.
     */
    abstract protected static function bundlePath(): string;

    /**
     * The bundle's config alias. It is also the icon prefix the bundle may draw
     * with, because one package answers for one prefix.
     */
    abstract protected static function alias(): string;

    /**
     * The sheets this bundle ships, relative to its `public/` directory. A
     * bundle that ships none returns nothing and only the icon half applies.
     *
     * @return list<string>
     */
    protected static function ownStylesheets(): array
    {
        return [];
    }

    /**
     * The sheets a page links BESIDE this bundle's own, as absolute paths —
     * found through the shipping bundle's class rather than a relative path, so
     * the chain resolves wherever those packages are installed from.
     *
     * The default is the shell's sheet alone, which every page links. A bundle
     * whose pages also link a dependency's adds it; a bundle that is itself the
     * end of the chain returns nothing.
     *
     * @return list<string>
     */
    protected static function linkedStylesheets(): array
    {
        return [self::publicDir(ShellBundle::class).'/shell.css'];
    }

    /**
     * The prefixes this bundle's templates and code may name. Its own alias,
     * and the shell's — a page rendered inside the shell may reuse the shell's
     * marks rather than copying them.
     *
     * @return list<string>
     */
    protected static function allowedIconPrefixes(): array
    {
        return array_values(array_unique([static::alias(), 'shell']));
    }

    /**
     * Where the files answering this bundle's own prefix live. Null when the
     * bundle registers no icon set of its own, in which case it may only draw
     * with the shell's.
     */
    protected static function iconDirectory(): ?string
    {
        $directory = static::bundlePath().'/assets/icons/'.static::alias();

        return is_dir($directory) ? $directory : null;
    }

    /**
     * WHETHER THIS BUNDLE DECLARES A PALETTE OF ITS OWN, and may
     * therefore write colour values.
     *
     * Almost nothing may. The shell declares the product's palette and
     * the atlas declares the ground its imagery is read against; every
     * other sheet SPENDS those and names no colour, which is the whole
     * of why a module is correct after dark without a single dark-mode
     * rule of its own.
     */
    protected static function declaresItsOwnPalette(): bool
    {
        return false;
    }

    /**
     * WHETHER THIS BUNDLE IS THE ONE THAT OWNS THE MONTH GRID. Exactly
     * one package answers true — the atlas, which ships the component —
     * and every other package leaves it alone.
     */
    protected static function ownsTheMonthGrid(): bool
    {
        return false;
    }

    public function testEveryIconReferenceUsesAPrefixThisBundleMayUse(): void
    {
        $allowed = static::allowedIconPrefixes();

        $offenders = [];
        foreach (self::iconReferences() as $name => $where) {
            if (!\in_array(strstr($name, ':', true), $allowed, true)) {
                $offenders[] = $name.' ('.$where.')';
            }
        }

        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            'This bundle draws [%s] under a prefix it may not use — the prefixes it may use are %s. '
            .'Ship the glyph under your own prefix: copy the SVG into the directory your bundle registers '
            .'as ux_icons.icon_sets.%s.path and draw it as %s:<name>.',
            implode(', ', $offenders),
            implode(', ', array_map(static fn (string $p): string => $p.':', $allowed)),
            static::alias(),
            static::alias(),
        ));
    }

    /**
     * NOTHING NAMES A MARK WITHOUT SAYING WHOSE IT IS.
     *
     * A BARE NAME RESOLVES IN THE HOST'S DEFAULT SET, so a bundle that
     * writes one is betting that every installation happens to ship that
     * glyph — and the bet fails SILENTLY until the name is rendered
     * somewhere that has no such icon. One `calendar-clock` in a nav row
     * took a whole module suite down the first time the shell drew that row
     * in a fixture application, because a nav row is rendered by the SHELL,
     * in whatever application mounted the module, which may have no icon
     * directory at all.
     *
     * The three rules above are about namespaced names — that the prefix is
     * one this bundle may use, and that a name under its own prefix is
     * shipped. This is the one below them: that there is a prefix at all.
     */
    public function testNoIconIsNamedWithoutSayingWhoseItIs(): void
    {
        $bare = [];

        foreach (self::sourceFiles() as $path) {
            $contents = (string) file_get_contents($path);
            $where = self::shortPath($path);

            // The three ways a mark is asked for, each matched only when the
            // name carries no `set:` prefix.
            preg_match_all('/ux_icon\(\s*[\'"]([a-z0-9][a-z0-9-]*)[\'"]/', $contents, $called);
            preg_match_all('/<twig:ux:icon[^>]*\sname="([a-z0-9][a-z0-9-]*)"/', $contents, $component);
            preg_match_all('/\bicon:\s*[\'"]([a-z0-9][a-z0-9-]*)[\'"]/', $contents, $named);

            foreach ([...$called[1], ...$component[1], ...$named[1]] as $name) {
                $bare[] = $name.' ('.$where.')';
            }
        }

        $bare = array_values(array_unique($bare));
        sort($bare);

        self::assertSame([], $bare, \sprintf(
            'These name a mark with no set [%s], so it resolves wherever the host happens to look — '
            .'and renders as nothing in an installation that ships no such glyph. Name the set: '
            .'`%s:<name>` for a mark this bundle ships, `shell:<name>` for one the shell does.',
            implode(', ', $bare),
            static::alias(),
        ));
    }

    /**
     * With on-demand fetching off — which is what an installation configures —
     * a name is answered by a file or by nothing at all. So the file is what is
     * asked for.
     */
    public function testEveryIconUnderThisBundlesPrefixResolvesFromTheDirectoryItShips(): void
    {
        $directory = static::iconDirectory();
        $own = static::alias().':';

        $missing = [];
        foreach (self::iconReferences() as $name => $where) {
            if (!str_starts_with($name, $own)) {
                continue;
            }

            if (null === $directory || !is_file($directory.'/'.substr($name, \strlen($own)).'.svg')) {
                $missing[] = $name.' ('.$where.')';
            }
        }

        sort($missing);

        self::assertSame([], $missing, \sprintf(
            'No file in %s answers to [%s]. On a deployment with fetching disabled each of those is an empty box.',
            $directory ?? 'a directory this bundle does not ship',
            implode(', ', $missing),
        ));
    }

    /**
     * AND EVERY `shell:` NAME RESOLVES TO A GLYPH THE SHELL ACTUALLY
     * SHIPS.
     *
     * The check above answers for a bundle's OWN prefix and said nothing
     * about the shell's, which is the prefix every bundle draws most of
     * its marks under — so a nav row asking for a mark nobody added was
     * an empty box on a deployment with fetching off, and green here.
     * The shell's set is read from the shell's own package, wherever it
     * is installed from, so this holds for a module as it holds for the
     * core.
     */
    public function testEveryShellIconTheBundleDrawsIsOneTheShellShips(): void
    {
        $directory = \dirname(new \ReflectionClass(ShellBundle::class)->getFileName() ?: '').'/assets/icons/shell';

        $missing = [];
        foreach (self::iconReferences() as $name => $where) {
            if (!str_starts_with($name, 'shell:')) {
                continue;
            }

            if (!is_file($directory.'/'.substr($name, 6).'.svg')) {
                $missing[] = $name.' ('.$where.')';
            }
        }

        sort($missing);

        self::assertSame([], $missing, \sprintf(
            'No file in %s answers to [%s]. Each of those draws an empty box on a deployment with fetching disabled. '
            .'Add the glyph to the shell\'s set from lucide, verbatim.',
            $directory,
            implode(', ', $missing),
        ));
    }

    public function testEveryClassTheTemplatesWriteIsShippedBySomebody(): void
    {
        $written = self::classesUsedInTemplates();
        if ([] === $written) {
            self::assertSame([], $written, 'A bundle with no templates writes no classes.');

            return;
        }

        $shipped = [...self::classesDefinedIn(self::chain()), ...self::classesNamedInScripts()];

        $missing = array_values(array_diff($written, array_unique($shipped)));
        sort($missing);

        self::assertSame([], $missing, \sprintf(
            'The templates write [%s] and no sheet or script in the chain ships it; those elements render as unstyled markup.',
            implode(', ', array_map(static fn (string $c): string => '.'.$c, $missing)),
        ));
    }

    public function testTheOwnSheetsSpendNoTokenTheChainDoesNotDefine(): void
    {
        $spent = self::tokensUsed(self::ownCss());
        $defined = self::tokensDefined(self::chain());

        $missing = array_values(array_diff($spent, $defined));
        sort($missing);

        self::assertSame([], $missing, \sprintf(
            'This bundle spends token(s) [%s] nothing in the chain defines; those rules inherit instead of painting.',
            implode(', ', array_map(static fn (string $t): string => '--'.$t, $missing)),
        ));
    }

    /**
     * THIS BUNDLE STATES NO RULE THE CHAIN ALREADY STATES. It may reach for a
     * shared class — decorating one, qualified inside a scope of its own — but
     * the moment it writes a rule for a selector the chain already carries,
     * two definitions of one component exist and the page renders whichever
     * loaded last.
     *
     * The comparison is by selector, and comma-separated groups are compared a
     * side at a time. A higher-specificity restyle is not caught by this and is
     * not endorsed by it either; consolidating those is design work with a
     * rendered page to check, not a text sweep.
     */
    public function testTheOwnSheetsRestateNoSelectorTheChainShips(): void
    {
        $shipped = self::selectors(implode("\n", self::linkedCss()));

        // A `:root` block is where a sheet DECLARES its own tokens, so every
        // sheet in a chain carries one and none of them is restating a
        // component.
        $offenders = array_values(array_filter(
            array_intersect(self::selectors(self::ownCss()), $shipped),
            static fn (string $selector): bool => !str_starts_with($selector, ':root'),
        ));
        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            'This bundle restates [%s]; a rule written twice renders differently depending on which sheet loaded last.',
            implode(', ', $offenders),
        ));
    }

    /**
     * A SHEET SPENDS TOKENS AND NAMES NO HUE.
     *
     * The first `#3457B5` in a module's stylesheet is the first thing on
     * that module's pages that will be wrong after dark, wrong on
     * imagery, and wrong again the day the palette moves — and nothing
     * will report it, because a colour that renders is a colour that
     * looks like it worked.
     *
     * A HEX IS THE TEST, and a translucent black or white is not one: a
     * shadow and a veil are the same in both palettes, which is exactly
     * why the shell writes its own elevation that way. What is refused
     * is a HUE — a colour somebody picked instead of naming what they
     * meant.
     */
    /**
     * NO MODULE DRAWS ITS OWN PAGE HINT — ruled: a page that has something to
     * explain says it ONCE, as a fragment at the bottom, in the shell's
     * `.pghint`.
     *
     * THIS ONE HAS A HISTORY, which is why it is a rule and not a note. The
     * same dashed-accent card was drawn FOUR times in four module sheets
     * under four names — `.f-say`, `.wf-say`, `.i-lensnote`, `.p-note` —
     * differing by a padding value, and the design hoisted them itself after
     * the fourth. A fifth near-copy under a fifth name is the failure this
     * refuses: a reader would meet the same card in two weights depending on
     * which page they were on.
     *
     * WHAT IS REFUSED IS A COPY, not the idea. A module writes
     * `@Shell/_page_hint.html.twig` and gets the house one; what it may not
     * do is define a `-say`, `-note` or `-hint` class of its own that draws a
     * dashed bordered block with an accent edge.
     */
    public function testNoOwnSheetDrawsAPageHintOfItsOwn(): void
    {
        $copies = [];
        foreach (self::hintLikeRules(self::ownCss()) as $selector => $body) {
            if (1 === preg_match('/border(?:-color)?\s*:[^;]*dashed|dashed[^;]*(?:--acc|--c-acc)/', $body)) {
                $copies[] = $selector;
            }
        }
        sort($copies);

        self::assertSame([], $copies, \sprintf(
            "This bundle draws its own page hint: %s.\n"
            .'A page says its one thing through @Shell/_page_hint.html.twig — the same card was drawn four times '
            .'in four sheets under four names before it was hoisted, and a fifth is a reader meeting one card in '
            .'two weights.',
            implode(', ', $copies),
        ));
    }

    /**
     * THE RULES WHOSE SELECTOR NAMES A HINT-SHAPED THING, by the words those
     * four copies were called: a class ending in `-say`, `-note` or `-hint`.
     * A longer word does not count — `.p-notebook` is furniture, not a hint.
     *
     * @return array<string, string> selector to the declarations inside it
     */
    private static function hintLikeRules(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $rules = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $selector, $body]) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');
            if (1 === preg_match('/\.[a-z0-9]+-(?:say|note|hint)(?![a-z0-9-])/i', $selector)) {
                $rules[$selector] = $body;
            }
        }

        return $rules;
    }

    /**
     * A RUNNING STATE WEARS THE ACCENT, and never a category or a module's
     * own hue — RULED 2026-09-21, in the owner's words "the accent, since it
     * is reserved for live".
     *
     * THE STATUS SET IS JUDGED. Reported needs a look, ready is waiting,
     * resolved went well, closed is spent — each of those has a semantic
     * token that means what it means. "In progress" is the one that is
     * HAPPENING, and that is a meaning too; it was the one state told apart
     * rather than judged, so it borrowed a CATEGORY token in one module and
     * the patrol hue in another, and a reader had to learn per module what
     * that blue meant.
     *
     * What this refuses is narrow and deliberate: a rule whose selector names
     * a running state, colouring it with `--cat-*` or a `--dept-*`/module
     * hue. Spend `--acc` (the shell ships `.chip.run`, filled), or say what
     * the state MEANS with the token for that meaning.
     */
    public function testNoRunningStateIsColouredWithACategoryOrAModuleHue(): void
    {
        $borrowed = [];
        foreach (self::runningStateRules(self::ownCss()) as $selector => $body) {
            preg_match_all('/var\(\s*(--(?:cat|dept)-[a-z0-9-]+)/i', $body, $found);
            foreach (array_unique($found[1]) as $token) {
                $borrowed[] = $selector.' → var('.$token.')';
            }
        }
        sort($borrowed);

        self::assertSame([], $borrowed, \sprintf(
            "A running state is coloured with a category or a module hue:\n  %s\n"
            .'A status is judged, not told apart — in progress wears the accent (`.chip.run`, filled), '
            .'and the categorical set says only that one thing is not another.',
            implode("\n  ", $borrowed),
        ));
    }

    /**
     * THE RULES WHOSE SELECTOR NAMES A RUNNING STATE.
     *
     * Matched on the state word in a class, because that is the only thing
     * every module's status vocabulary has in common: `.i-st.wip`,
     * `.wf-st.doing`, `.p-st.running`. A word in a longer name does not count
     * — `.ongoing-total` is a figure, not a status — so the class ends at the
     * word or continues with a state modifier.
     *
     * @return array<string, string> selector to the declarations inside it
     */
    private static function runningStateRules(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $rules = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $selector, $body]) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');
            if (1 === preg_match('/\.(?:wip|doing|running|ongoing|inprogress|in-progress|progressing)(?![a-z0-9-])/i', $selector)) {
                $rules[$selector] = $body;
            }
        }

        return $rules;
    }

    public function testNoOwnSheetNamesAColourOfItsOwn(): void
    {
        // The bundle that DECLARES the palette writes its values; the rule
        // is about everybody who spends it.
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', static::declaresItsOwnPalette() ? '' : self::ownCss(), $found);
        $hues = array_values(array_unique($found[0]));
        sort($hues);

        self::assertSame([], $hues, \sprintf(
            'This bundle names the colour(s) [%s]. Spend a token instead — a state token for a meaning, '
            .'or a category for one of a set — or the rule is wrong in one of the two palettes and on imagery.',
            implode(', ', $hues),
        ));
    }

    /**
     * NOBODY REDRAWS THE MONTH. The grid, the day head, the cell and its
     * height are the atlas's `atlas_calendar()`, and a module that
     * writes `.cal` rules of its own has forked the one component two
     * modules already share — the patrols month and the roster's are the
     * same month with different marks in it.
     *
     * WHAT TO DO INSTEAD: draw through the component and change the one
     * thing that is yours to change, the cell's height, through
     * `--cal-cell-height`. What goes IN a cell is yours; the grid it
     * sits in is not.
     *
     * THE GRID, AND NOT THE MARKS IN IT. `.cal-mark`, `.cal-more` and
     * `.cal-nav` are the SHELL's — they were host vocabulary before this
     * component existed, because modules were already drawing marks —
     * and a module decorating one of those is decorating a shared
     * component, which is allowed. What is refused is `.cal` and
     * `.cal-plate`: the seven columns, the cell and its height.
     *
     * A twin under another name — `.patrol-cal`, `.roster-month` — is
     * the same fork and cannot be caught by a text sweep. This catches
     * the honest half.
     */
    public function testNoOwnSheetRedrawsTheMonthGrid(): void
    {
        // The package that SHIPS the month writes these rules; the rule
        // is about everybody else, and one exemption is what makes it
        // enforceable at all.
        $offenders = static::ownsTheMonthGrid() ? [] : array_values(array_filter(
            self::selectors(self::ownCss()),
            static fn (string $selector): bool => 1 === preg_match('/(?:^|[\s>+~])\.cal(?:-plate)?(?:[.:\[\s]|$)/', $selector),
        ));
        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            'This bundle writes its own month grid [%s]. The month is the atlas\'s `atlas_calendar()`: '
            .'draw through it and set the cell height with --cal-cell-height; what goes in a cell is yours.',
            implode(', ', $offenders),
        ));
    }

    /**
     * NO TEMPLATE LINKS A SHEET THE HEAD ALREADY CARRIES.
     *
     * THE DEFECT, NAMED, AND IT HAS RECURRED FOR A YEAR: a page that drew a
     * plate linked the atlas's map sheet by hand, and a page that did not,
     * did not. Then the plate stopped being something a page asks for and
     * became something a WIDGET draws — any cell of a composed surface may
     * draw one — and "a page that draws a plate links map.css" stopped being
     * a rule anybody could keep: the organization Overview composed a map
     * cell onto a page that linked no map sheet, and the plate came apart
     * with no error anywhere. The head cannot be decided by what a page
     * happens to compose.
     *
     * SO THE SHEETS COME THROUGH THE HEAD CONTRACT, in every head, and a
     * hand-written link is now a RESTATEMENT: a second copy of the same
     * rules, at a different point in the load order, which is the drift this
     * suite exists to refuse everywhere else.
     *
     * A BUNDLE MAY LINK ITS OWN. The package that ships a sheet is the one
     * place that may name it — that is how it reaches the chain at all.
     */
    public function testNoTemplateLinksASheetTheHeadAlreadyCarries(): void
    {
        $offenders = [];

        foreach (self::files(static::bundlePath().'/templates', 'twig') as $path) {
            $twig = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));

            preg_match_all('/<link\b[^>]*>/i', $twig, $links);
            foreach ($links[0] as $link) {
                foreach (static::sheetsTheHeadCarries() as $sheet => $named) {
                    if (str_contains($link, '/'.static::alias().'/')) {
                        continue;
                    }

                    foreach ($named as $needle) {
                        if (str_contains($link, $needle)) {
                            $offenders[] = self::shortPath($path).' — '.$sheet;

                            continue 3;
                        }
                    }
                }
            }
        }

        $offenders = array_values(array_unique($offenders));
        sort($offenders);

        self::assertSame([], $offenders, \sprintf(
            'These templates link a sheet the shell already carries [%s]. The shell carries it: '
            .'it is published through the head contract and reaches every page, so a link here is a '
            .'second copy at a different point in the load order. Delete the link.',
            implode(', ', $offenders),
        ));
    }

    /**
     * THE SHEETS THE HEAD ALREADY CARRIES, by the names a template would
     * write them under — the asset path and the constant that resolves to
     * it. A bundle that publishes another one through the contract adds it
     * here so that its own consumers are held to the same rule.
     *
     * @return array<string, list<string>> the sheet, and what naming it looks like
     */
    protected static function sheetsTheHeadCarries(): array
    {
        return [
            'the map sheet' => ['bundles/atlas/map.css', 'AtlasBundle::STYLESHEET'],
            'the chart sheet' => ['bundles/atlas/chart.css', 'AtlasBundle::CHART_STYLESHEET'],
            'the calendar sheet' => ['bundles/atlas/calendar.css', 'AtlasBundle::CALENDAR_STYLESHEET'],
            'the heat sheet' => ['bundles/atlas/heat.css', 'AtlasBundle::HEAT_STYLESHEET'],
        ];
    }

    /**
     * NO LEFT RAIL ON A CARD BUT THE HOUSE FOCUS LINE.
     *
     * RULED 2026-09-21: a card may wear exactly ONE vertical mark on its left
     * edge and it means FOCUS — `.focusline`, which the shell ships. It never
     * means open, never means selected-and-showing, and never means a
     * CATEGORY: a category is a chip or an 8px hue dot, a state is a chip or
     * a stamp. A module that draws its own rail gets a bar a different width
     * from every other bar in the product, and a reader who has learnt that a
     * left mark means "you are here" reads it as that wherever it appears.
     *
     * WHAT COUNTS AS A RAIL: a left border heavier than the sides beside it,
     * and an inset box-shadow offset only on x — the two ways one is drawn.
     * A uniform border is not a rail, and neither is a rule on something that
     * is not a card: this looks at the declaration, so a module drawing an
     * indent guide on a table row or a hairline on a banner passes.
     */
    public function testNoOwnSheetDrawsALeftRailOnACard(): void
    {
        $rails = [];

        foreach (self::cardRules(self::ownCss()) as $selector => $body) {
            // A left border heavier than the shorthand beside it.
            if (1 === preg_match('/border-left\s*:\s*(\d+)px/', $body, $left)
                && (int) $left[1] > self::hairline($body)) {
                $rails[] = $selector.' — border-left: '.$left[1].'px';
            }

            if (1 === preg_match('/border-left-width\s*:\s*(\d+)px/', $body, $width)
                && (int) $width[1] > self::hairline($body)) {
                $rails[] = $selector.' — border-left-width: '.$width[1].'px';
            }

            // An inset shadow offset on x only: a rail drawn as paint.
            if (1 === preg_match('/box-shadow\s*:[^;]*inset\s+\d*[1-9]\d*px\s+0\s+0/', $body)) {
                $rails[] = $selector.' — inset box-shadow rail';
            }
        }

        sort($rails);

        self::assertSame([], $rails, \sprintf(
            "This bundle draws its own left rail on a card [%s].\n".
            'The one left mark a card may carry is the shell\'s `focusline`, and it means focus; '.
            'a category is a chip or a hue dot and a state is a chip or a stamp.',
            implode(', ', $rails),
        ));
    }

    /**
     * A TAB STRIP IS NAVIGATION, AND A TAB GOES SOMEWHERE.
     *
     * `.atabs` is the shell's strip of page tabs, and every entry in it is a
     * door to a page: it carries an `href` a browser can follow, a reader can
     * middle-click, and a crawler, a bookmark and the back button all
     * understand. An `href="#"` is none of those. It is a link that has agreed
     * to look like a link and then does nothing, and the two ways it gets
     * written are both mistakes the page cannot recover from:
     *
     *   - A DESIGN PORTED TOO LITERALLY. The static workspace has no router,
     *     so its tabs point at `#`; ported verbatim, the strip renders
     *     perfectly and every tab is dead. This is the common one.
     *   - A CONTROL WEARING A TAB. Something that toggles a pane rather than
     *     opening a page is a button, and `.atabs` is the wrong vocabulary for
     *     it: a reader who has learnt that this strip changes the page is
     *     owed that meaning everywhere.
     *
     * A TAB NOT YET BUILT IS NOT AN EXCEPTION. It is drawn with the shell's
     * own `.soon` treatment and no `href` at all, which says "there is nothing
     * here yet" rather than "click me and find out".
     */
    public function testNoTabInTheStripIsAnchoredAtNothing(): void
    {
        $dead = [];

        foreach (self::files(static::bundlePath().'/templates', 'twig') as $path) {
            $markup = (string) file_get_contents($path);

            // Comments first: a `#` quoted in prose is not a tab.
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', $markup);

            foreach (self::tabStrips($markup) as $strip) {
                if (1 === preg_match('/<a\b[^>]*\bhref\s*=\s*"#[^"]*"/', $strip)) {
                    $dead[] = self::shortPath($path);
                    break;
                }
            }
        }

        $dead = array_values(array_unique($dead));
        sort($dead);

        self::assertSame([], $dead, \sprintf(
            "A tab in an `.atabs` strip is anchored at nothing [%s].\n".
            'Every entry in the strip is a door to a page and carries a real `href`; a tab that is '.
            'not built yet is drawn with the shell\'s `.soon` treatment and no `href` at all, and a '.
            'control that toggles rather than navigates is a button in some other vocabulary.',
            implode(', ', $dead),
        ));
    }

    /**
     * The markup of every `.atabs` strip in one template — from the opening
     * tag to its matching close, counting nesting, so a link in a strip is
     * told apart from one merely near it.
     *
     * @return list<string>
     */
    private static function tabStrips(string $markup): array
    {
        $strips = [];

        preg_match_all('/<(\w+)\b[^>]*class\s*=\s*"[^"]*\batabs\b[^"]*"[^>]*>/', $markup, $opens, \PREG_OFFSET_CAPTURE);

        foreach ($opens[0] as $index => [$tag, $at]) {
            $element = $opens[1][$index][0];
            $depth = 1;
            $cursor = $at + \strlen($tag);
            $end = \strlen($markup);

            while ($depth > 0 && $cursor < $end) {
                if (1 !== preg_match('#<(/?)'.preg_quote($element, '#').'\b[^>]*>#', $markup, $step, \PREG_OFFSET_CAPTURE, $cursor)) {
                    break;
                }

                $depth += '/' === $step[1][0] ? -1 : 1;
                $cursor = $step[0][1] + \strlen($step[0][0]);
            }

            $strips[] = substr($markup, $at, $cursor - $at);
        }

        return $strips;
    }

    /**
     * The rules in a sheet whose selector names a CARD — the house card, or
     * a module's own `-card`/`card`-suffixed class. Anything else is a row, a
     * banner, a tile or a tree, and none of them is what the ruling is about.
     *
     * @return array<string, string> selector to the declarations inside it
     */
    private static function cardRules(string $css): array
    {
        $rules = [];

        // Comments first: a rule quoted in prose is not a rule the browser reads.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $selector, $body]) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');

            foreach (explode(',', $selector) as $one) {
                $one = trim($one);
                if (1 === preg_match('/(?:^|[\s>+~])\.(?:c|[a-z0-9-]*card)(?:[.:\[]|$)/', $one)) {
                    $rules[$selector] = $body;

                    break;
                }
            }
        }

        return $rules;
    }

    /** The width of the sides beside a left border, or 1 where none is stated. */
    private static function hairline(string $body): int
    {
        return 1 === preg_match('/(?:^|;)\s*border\s*:\s*(\d+)px/', $body, $all) ? (int) $all[1] : 1;
    }

    /**
     * Every icon name this bundle asks for, mapped to one place it is asked
     * from — templates first, where a name is written literally, then the PHP
     * and JavaScript, where navigation rows and rendered markup carry one as
     * data.
     *
     * @return array<string, string>
     */
    final protected static function iconReferences(): array
    {
        $names = [];

        foreach (self::sourceFiles() as $path) {
            $contents = (string) file_get_contents($path);
            $where = self::shortPath($path);

            preg_match_all('/ux_icon\(\s*[\'"]([a-z0-9-]+:[a-z0-9:-]+)[\'"]/', $contents, $called);
            preg_match_all('/<twig:ux:icon[^>]*\sname="([a-z0-9-]+:[a-z0-9:-]+)"/', $contents, $component);
            preg_match_all('/icon:\s*[\'"]([a-z0-9-]+:[a-z0-9-]+)[\'"]/', $contents, $named);

            foreach ([...$called[1], ...$component[1], ...$named[1]] as $name) {
                $names[$name] ??= $where;
            }
        }

        return $names;
    }

    /**
     * The whole chain a page reads from: what somebody else ships, then this
     * bundle's own, which is last because it is the one allowed to decorate.
     *
     * @return list<string>
     */
    final protected static function chain(): array
    {
        return [...self::linkedCss(), self::ownCss()];
    }

    /** @return list<string> */
    private static function linkedCss(): array
    {
        return array_map(self::read(...), static::linkedStylesheets());
    }

    private static function ownCss(): string
    {
        $sheets = array_map(
            static fn (string $name): string => self::read(static::bundlePath().'/public/'.$name),
            static::ownStylesheets(),
        );

        return implode("\n", $sheets);
    }

    /** @param class-string $bundle */
    private static function publicDir(string $bundle): string
    {
        $file = new \ReflectionClass($bundle)->getFileName();
        self::assertIsString($file, $bundle.' must be autoloadable from a file.');

        return \dirname($file).'/public';
    }

    private static function read(string $path): string
    {
        $css = file_get_contents($path);
        self::assertIsString($css, $path.' must ship.');

        // Comments name classes and tokens as prose; only rules count.
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * @param list<string> $sheets
     *
     * @return list<string>
     */
    private static function tokensDefined(array $sheets): array
    {
        $names = [];
        foreach ($sheets as $css) {
            preg_match_all('/--([a-z0-9_-]+)\s*:/i', $css, $matches);
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }

    /**
     * Every token a sheet SPENDS WITHOUT A FALLBACK. `var(--x, #333)` names a
     * token the sheet does not require anybody to define — the fallback is what
     * paints when nothing does, which is a deliberate arrangement rather than
     * the silent inheritance this is looking for.
     *
     * @return list<string>
     */
    private static function tokensUsed(string $css): array
    {
        preg_match_all('/var\(\s*--([a-z0-9_-]+)\s*([,)])/i', $css, $matches, \PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $match) {
            if (')' === $match[2]) {
                $names[] = $match[1];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private static function selectors(string $css): array
    {
        /*
         * A KEYFRAME STEP IS NOT A SELECTOR. `0%`, `70%`, `from` and `to` name
         * points in one animation's own timeline, and two sheets each having a
         * `0%` inside differently-named keyframes are not restating a rule —
         * they are two animations. Left in, the first sheet to grow a
         * `@keyframes` block made every other sheet with one fail a check
         * about selector collisions, which is a check measuring the wrong
         * thing rather than a collision.
         */
        $css = (string) preg_replace('/@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);

        // Everything before a brace that is not itself an at-rule prelude.
        preg_match_all('/(^|\})([^{}@]+)\{/m', $css, $matches);

        $selectors = [];
        foreach ($matches[2] as $group) {
            foreach (explode(',', $group) as $selector) {
                $selector = (string) preg_replace('/\s+/', ' ', trim($selector));
                if ('' !== $selector) {
                    $selectors[] = $selector;
                }
            }
        }

        return array_values(array_unique($selectors));
    }

    /** @return list<string> */
    private static function classesIn(string $text): array
    {
        preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param list<string> $sheets
     *
     * @return list<string>
     */
    private static function classesDefinedIn(array $sheets): array
    {
        $classes = [];
        foreach ($sheets as $css) {
            foreach (self::selectors($css) as $selector) {
                $classes = [...$classes, ...self::classesIn($selector)];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Every class this bundle's own scripts name — the markup they build, the
     * classes they toggle and the selectors they query by. A hook a script
     * reaches for is shipped as surely as a rule is: the script is the file
     * that would have to change.
     *
     * @return list<string>
     */
    private static function classesNamedInScripts(): array
    {
        $classes = [];

        foreach (self::files(static::bundlePath().'/assets', 'js') as $path) {
            $js = (string) file_get_contents($path);

            preg_match_all('/class="([^"]*)"/', $js, $markup);
            preg_match_all('/classList\.[a-zA-Z]+\(\s*.([a-zA-Z][a-zA-Z0-9_-]*)./', $js, $toggled);
            // A selector the script queries by is a hook it depends on too.
            preg_match_all('/querySelector(?:All)?\(\s*.([^\x27"`]+)./', $js, $queried);

            foreach ($markup[1] as $attribute) {
                $classes = [...$classes, ...self::classNames($attribute)];
            }
            $classes = [...$classes, ...$toggled[1]];
            foreach ($queried[1] as $selector) {
                $classes = [...$classes, ...self::classesIn($selector)];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Every class literal a template writes. Interpolations are dropped: what a
     * `{{ }}` produces is somebody else's vocabulary, and guessing at it would
     * fail this on correct markup.
     *
     * @return list<string>
     */
    private static function classesUsedInTemplates(): array
    {
        $classes = [];

        foreach (self::files(static::bundlePath().'/templates', 'twig') as $path) {
            $twig = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));

            preg_match_all('/class="([^"]*)"/', $twig, $attributes);
            foreach ($attributes[1] as $attribute) {
                $literal = (string) preg_replace('/\{[{%].*?[}%]\}/s', ' ', $attribute);
                $classes = [...$classes, ...self::classNames($literal)];
            }
        }

        return array_values(array_unique($classes));
    }

    /** @return list<string> */
    private static function classNames(string $attribute): array
    {
        $classes = [];
        foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
            // A name ending in a hyphen is the literal half of a name an
            // interpolation completes (`w-span-{{ n }}`), and no shipped class
            // ends in one. Reading it as a whole name would fail this on
            // correct markup.
            if (1 === preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*[a-zA-Z0-9_]$/', $class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The bundle's shipped templates, PHP and JavaScript — THE FILES THE BUNDLE
     * WOULD HAVE TO CHANGE, and nothing else under the same root.
     *
     * A working copy is not a package as it was published. Beside the source
     * sit other people's packages (`vendor/`, `node_modules/`), the framework's
     * rendered templates (`var/`), the asset pipeline's digested copies of
     * sources already read once (`public/assets/`), and the suite's own
     * fixtures (`tests/`), which name things no installation ever draws. All of
     * those carry markup; none of it is markup this bundle writes.
     *
     * Reading them fails a bundle for a name somebody else spent — a module
     * with another module installed under it is failed for that module's icon
     * prefix — and the available answer is to widen the prefixes the bundle
     * allows itself, after which the check is asserting nothing.
     *
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $paths = [];
        foreach (['twig', 'php', 'js'] as $extension) {
            $paths = [...$paths, ...self::files(static::bundlePath(), $extension)];
        }

        $notSource = array_map(
            static fn (string $directory): string => static::bundlePath().'/'.$directory.'/',
            ['vendor', 'node_modules', 'var', 'public/assets', 'tests'],
        );

        return array_values(array_filter(
            $paths,
            static function (string $path) use ($notSource): bool {
                foreach ($notSource as $directory) {
                    if (str_starts_with($path, $directory)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /** @return list<string> */
    private static function files(string $directory, string $extension): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($extension === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    private static function shortPath(string $path): string
    {
        $root = static::bundlePath();

        return str_starts_with($path, $root) ? ltrim(substr($path, \strlen($root)), '/') : $path;
    }
}
