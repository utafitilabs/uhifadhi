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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Dropdown;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\ShellBundle\Contract\LayoutContract;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\ContractTestCase;

/**
 * THE DROPDOWN — the pill trigger and its panel of grouped rows, drawn by the
 * shell from data so a page writes none of its markup.
 *
 * What is checked here is the markup the design's behaviour reads (the
 * wrapper and its state, the trigger with its icon, label and chevron, the
 * hidden panel with its search, the listbox of groups with their heads and
 * counts, the rows with their label and muted part, the nothing-matches
 * line), and that every class the partial writes is one the shell's sheet
 * ships. Opening, filtering and the keyboard are a browser's, and are
 * render-verified.
 *
 * @see /Users/eemjema/Programming/DesignsProjects/uhifadhi-web/house-dropdown.js "THE MARKUP"
 */
final class DropdownIncludeTest extends ContractTestCase
{
    private const string NAME = 'uhifadhi--shell-bundle--dropdown';

    /** @var array<string, mixed> */
    private const array PERSON = [
        'name' => 'person',
        'label' => 'Find a person',
        'icon' => 'shell:user',
        'placeholder' => 'Choose a person',
        'search' => 'Name or position',
        'none' => 'Nobody by that name or position.',
        'searchable' => true,
        'value' => 'r-02',
        'groups' => [
            ['label' => 'Admin', 'options' => [
                ['value' => 'a-01', 'label' => 'Asha Mollel', 'count' => 'Chief Ranger'],
            ]],
            ['label' => 'Staff', 'options' => [
                ['value' => 'r-01', 'label' => 'Baraka Laizer', 'count' => 12],
                ['value' => 'r-02', 'label' => 'Rehema Kileo', 'count' => 0],
                ['value' => 'r-03', 'label' => 'Juma Temba', 'count' => null],
            ]],
        ],
    ];

    /** @var array<string, mixed> */
    private const array AREA = [
        'name' => 'area',
        'label' => 'All areas',
        'default' => '',
        'value' => '',
        'end' => true,
        'groups' => [
            ['label' => 'area', 'options' => [['value' => '', 'label' => 'All areas', 'count' => 31]]],
            ['label' => 'Areas', 'options' => [
                ['value' => 'kilimani', 'label' => 'Kilimani', 'count' => 31],
                ['value' => 'olkeju', 'label' => 'Olkeju National Park', 'count' => 0],
            ]],
        ],
    ];

    public function testTheIncludeIsAPublishedPartial(): void
    {
        self::assertContains('@Shell/_dropdown.html.twig', LayoutContract::PARTIALS);
    }

    public function testTheWrapperCarriesTheControllerAndItsState(): void
    {
        $page = $this->page();
        $person = $page->filter('.pgbody div.dd[data-dd="person"]');

        self::assertCount(1, $person);
        self::assertSame(self::NAME, $person->attr('data-controller'));
        self::assertSame('r-02', $person->attr('data-dd-value'));
        self::assertSame(['dd', 'dd-set'], self::classes($person), 'a value with no default is set');
        self::assertNull($person->attr('data-default'));
        self::assertSame(['button', 'div'], $person->children()->each(static fn (Crawler $c): string => $c->nodeName()));

        $area = $page->filter('div.dd[data-dd="area"]');
        self::assertSame('', $area->attr('data-default'));
        self::assertSame('', $area->attr('data-dd-value'));
        self::assertSame(['dd', 'dd-end'], self::classes($area), 'the default chosen is not set; end aligns the panel right');
    }

    public function testTheTriggerIsAPillWithIconLabelAndChevron(): void
    {
        $trigger = $this->page()->filter('div.dd[data-dd="person"] > button.dd-t');

        self::assertSame('button', $trigger->attr('type'));
        self::assertSame('listbox', $trigger->attr('aria-haspopup'));
        self::assertSame('false', $trigger->attr('aria-expanded'));
        self::assertSame('dd-p-person', $trigger->attr('aria-controls'));
        self::assertSame(['svg', 'span', 'svg'], $trigger->children()->each(static fn (Crawler $c): string => $c->nodeName()));
        self::assertSame(['dd-i', 'dd-v', 'dd-c'], $trigger->children()->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertSame(['true', null, 'true'], $trigger->children()->each(static fn (Crawler $c): ?string => $c->attr('aria-hidden')));

        $label = $trigger->filter('span.dd-v');
        self::assertSame('Rehema Kileo', trim($label->text()), 'the trigger reads the chosen row\'s label');
        self::assertSame('Choose a person', $label->attr('data-placeholder'));
    }

    public function testNothingChosenReadsThePlaceholderAndNoIconDrawsNone(): void
    {
        $page = $this->page([['name' => 'bare', 'label' => 'Any rank', 'groups' => self::AREA['groups']]]);
        $bare = $page->filter('div.dd[data-dd="bare"]');

        self::assertSame(['dd'], self::classes($bare));
        self::assertSame('', $bare->attr('data-dd-value'));
        self::assertSame('Any rank', trim($bare->filter('span.dd-v')->text()), 'the label stands in while nothing is chosen');
        self::assertSame('Any rank', $bare->filter('span.dd-v')->attr('data-placeholder'));
        self::assertCount(0, $bare->filter('.dd-i'));
        self::assertCount(0, $bare->filter('.dd-q'), 'not searchable, no search');
        self::assertCount(0, $bare->filter('.dd-none'));
        self::assertCount(0, $bare->filter('[aria-selected="true"]'));
        self::assertCount(0, $bare->filter('[data-default]'));
    }

    public function testThePanelHoldsTheSearchThenTheListbox(): void
    {
        $panel = $this->page()->filter('div.dd[data-dd="person"] > div.dd-p');

        self::assertSame('dd-p-person', $panel->attr('id'));
        self::assertNotNull($panel->attr('hidden'));
        self::assertSame(['label', 'div'], $panel->children()->each(static fn (Crawler $c): string => $c->nodeName()));

        $search = $panel->filter('label.dd-q');
        self::assertCount(1, $search->filter('svg[aria-hidden="true"]'));
        $input = $search->filter('input');
        self::assertSame('search', $input->attr('type'));
        self::assertSame('Name or position', $input->attr('placeholder'));
        self::assertSame('Find a person', $input->attr('aria-label'));
        self::assertSame('off', $input->attr('autocomplete'));

        $list = $panel->filter('div.dd-l');
        self::assertSame('listbox', $list->attr('role'));
        self::assertSame('Find a person', $list->attr('aria-label'));
        self::assertSame(['div', 'div', 'p'], $list->children()->each(static fn (Crawler $c): string => $c->nodeName()));

        $none = $list->filter('p.dd-none');
        self::assertNotNull($none->attr('hidden'));
        self::assertSame('Nobody by that name or position.', trim($none->text()));
    }

    public function testEachGroupHasAHeadWithItsCountThenItsRows(): void
    {
        $groups = $this->page()->filter('div.dd[data-dd="person"] .dd-l > [role="group"]');

        self::assertSame(['Admin', 'Staff'], $groups->each(static fn (Crawler $g): ?string => $g->attr('aria-label')));
        self::assertSame(['1', '3'], $groups->each(static fn (Crawler $g): string => trim($g->filter('.dd-h > [data-dd-n]')->text())));
        self::assertSame(['Admin', 'Staff'], $groups->each(static fn (Crawler $g): string => trim($g->filter('.dd-h > span')->first()->text())));
        self::assertSame(['true', 'true'], $groups->each(static fn (Crawler $g): ?string => $g->filter('.dd-h')->attr('aria-hidden')));
        self::assertSame(['div', 'button', 'button', 'button'], $groups->eq(1)->children()->each(static fn (Crawler $c): string => $c->nodeName()));
    }

    /** A lead group with no label — the design's "Any …" row — stands alone above the first head. */
    public function testAGroupWithNoLabelDrawsNoHead(): void
    {
        $lead = self::AREA;
        $lead['groups'][0] = ['label' => null, 'options' => [['value' => '', 'label' => 'All areas', 'count' => 31]]];
        $list = $this->page([$lead])->filter('[data-dd="area"] .dd-l');

        $groups = $list->filter('[role="group"]');
        self::assertCount(2, $groups);
        self::assertCount(0, $groups->eq(0)->filter('.dd-h'), 'no head over the lead row');
        self::assertSame('All areas', $groups->eq(0)->attr('aria-label'), 'the group is named by the control');
        self::assertSame('', $groups->eq(0)->filter('.dd-o')->attr('data-value'));
        self::assertSame(['Areas'], $list->filter('.dd-h > span:first-child')->each(static fn (Crawler $c): string => $c->text()), 'every other head is kept');
    }

    public function testARowIsAnOptionWithItsLabelAndMutedPart(): void
    {
        $rows = $this->page()->filter('div.dd[data-dd="person"] button.dd-o');

        self::assertSame(['a-01', 'r-01', 'r-02', 'r-03'], $rows->each(static fn (Crawler $o): ?string => $o->attr('data-value')));
        self::assertSame(['false', 'false', 'true', 'false'], $rows->each(static fn (Crawler $o): ?string => $o->attr('aria-selected')));
        self::assertSame(['button'], array_values(array_unique($rows->each(static fn (Crawler $o): ?string => $o->attr('type')))));
        self::assertSame(['option'], array_values(array_unique($rows->each(static fn (Crawler $o): ?string => $o->attr('role')))));
        self::assertSame(['-1'], array_values(array_unique($rows->each(static fn (Crawler $o): ?string => $o->attr('tabindex')))));
        self::assertSame(['Asha Mollel', 'Baraka Laizer', 'Rehema Kileo', 'Juma Temba'], $rows->each(static fn (Crawler $o): ?string => $o->attr('data-label')));
        self::assertSame('rehema kileo r-02', $rows->eq(2)->attr('data-find'), 'found by its label and its value');
        self::assertSame(['Asha Mollel', 'Baraka Laizer', 'Rehema Kileo', 'Juma Temba'], $rows->each(static fn (Crawler $o): string => trim($o->filter('span.dd-ol')->text())));

        self::assertSame(['Chief Ranger', '12', '0', null], $rows->each(
            static fn (Crawler $o): ?string => $o->filter('span.dd-m')->count() > 0 ? trim($o->filter('span.dd-m')->text()) : null,
        ), 'a count is optional, and zero is a count');
        self::assertSame(['span', 'span'], $rows->eq(1)->children()->each(static fn (Crawler $c): string => $c->nodeName()));
    }

    public function testTheDefaultRowIsMarked(): void
    {
        $rows = $this->page()->filter('div.dd[data-dd="area"] button.dd-o');

        self::assertSame(['', null, null], $rows->each(static fn (Crawler $o): ?string => $o->attr('data-default')));
        self::assertSame(['true', 'false', 'false'], $rows->each(static fn (Crawler $o): ?string => $o->attr('aria-selected')));
        self::assertSame('All areas', trim($this->page()->filter('div.dd[data-dd="area"] span.dd-v')->text()));
    }

    public function testAValueThatMatchesNoRowChoosesNothing(): void
    {
        $page = $this->page([[...self::PERSON, 'value' => 'nobody']]);
        $person = $page->filter('div.dd[data-dd="person"]');

        self::assertSame(['dd'], self::classes($person));
        self::assertSame('', $person->attr('data-dd-value'));
        self::assertSame('Choose a person', trim($person->filter('span.dd-v')->text()));
        self::assertCount(0, $person->filter('[aria-selected="true"]'));
    }

    /**
     * THE COMPACT HEAD TRIGGER — a dropdown in a page head's action row is the
     * row's 32px with a 9px radius, 12/600 text and 14px icons; only the
     * trigger shrinks, the panel keeps its rows of at least 40px.
     */
    public function testSmallIsTheCompactHeadTrigger(): void
    {
        $small = $this->page([[...self::AREA, 'name' => 'scope', 'sm' => true]])->filter('div.dd[data-dd="scope"]');
        self::assertSame(['dd', 'dd-end', 'dd-sm'], self::classes($small));
        self::assertSame(['dd'], self::classes($this->page([[...self::PERSON, 'value' => null]])->filter('div.dd[data-dd="person"]')), 'full size unless asked');

        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
        self::assertMatchesRegularExpression('/\.dd\.dd-sm \.dd-t\s*\{[^}]*height:\s*32px[^}]*border-radius:\s*9px[^}]*font-size:\s*12px[^}]*font-weight:\s*600/', $css);
        self::assertMatchesRegularExpression('/\.dd\.dd-sm\.dd-set \.dd-t\s*\{[^}]*font-weight:\s*700/', $css);
        self::assertMatchesRegularExpression('/\.dd\.dd-sm \.dd-i,\s*\.dd\.dd-sm \.dd-c\s*\{[^}]*width:\s*14px;\s*height:\s*14px/', $css);
        self::assertMatchesRegularExpression('/\.dd\.dd-sm \.dd-o\s*\{[^}]*min-height:\s*40px/', $css, 'the rows stay at 40px under any row that sizes buttons');
    }

    /** Every class the dropdown writes is one the shell's sheet defines. */
    public function testEveryClassTheDropdownWritesIsShipped(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());

        $classes = [];
        foreach ($this->page()->filter('.dd, .dd *') as $element) {
            \assert($element instanceof \DOMElement);
            foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
                if ('' !== $class) {
                    $classes[$class] = true;
                }
            }
        }

        foreach (['dd', 'dd-set', 'dd-end', 'dd-t', 'dd-i', 'dd-v', 'dd-c', 'dd-p', 'dd-q', 'dd-l', 'dd-h', 'dd-o', 'dd-ol', 'dd-m', 'dd-none'] as $class) {
            self::assertArrayHasKey($class, $classes, \sprintf('the dropdown writes .%s', $class));
        }
        foreach (array_keys($classes) as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $css, \sprintf('.%s is written and styled nowhere.', $class));
        }
        self::assertMatchesRegularExpression('/\.page\.dd-lift(?![\w-])/', $css, 'the controller lifts the .page');
    }

    /**
     * @param list<array<string, mixed>> $dropdowns
     */
    private function page(array $dropdowns = [self::PERSON, self::AREA]): Crawler
    {
        return $this->crawl('@fixtures/dropdown_page.html.twig', ['dropdowns' => $dropdowns]);
    }

    /** @return list<string> */
    private static function classes(Crawler $element): array
    {
        $classes = preg_split('/\s+/', trim((string) $element->attr('class'))) ?: [];
        sort($classes);

        return $classes;
    }
}
