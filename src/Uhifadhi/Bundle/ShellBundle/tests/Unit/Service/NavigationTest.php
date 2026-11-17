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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Model\NavItem;
use Uhifadhi\Bundle\ShellBundle\Model\NavSection;
use Uhifadhi\Bundle\ShellBundle\Service\Navigation;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * THE COLLECTOR, ON ITS OWN — and the rule it exists to keep: a heading is a
 * place in the sidebar, not a thing a source owns. Two modules that both file a
 * row under "System" have named the same place, and the drawing has one of it.
 */
final class NavigationTest extends TestCase
{
    public function testTwoSourcesFilingUnderTheSameHeadingProduceOneSection(): void
    {
        $navigation = new Navigation([
            self::source(new NavSection('System', [new NavItem(label: 'Files', url: '/files')], position: 30)),
            self::source(new NavSection('System', [new NavItem(label: 'Telemetry', url: '/telemetry')], position: 90)),
        ]);

        $sections = $navigation->sections();

        self::assertCount(1, $sections, 'One heading, both rows — a second "System" heading is the same place drawn twice.');
        self::assertSame('System', $sections[0]->label);
        self::assertSame(
            ['Files', 'Telemetry'],
            array_map(static fn (NavItem $item): string => $item->label, $sections[0]->items),
        );
    }

    /**
     * THE GROUPS COME OUT IN THE PUBLISHED ORDER, whatever a contribution
     * declared. A position is a contributing module's answer to "where among
     * the rows", which it can know; the order of the five headings is the
     * shell's, which no module can know.
     */
    public function testTheGroupsAreDrawnInTheContractsOrderAndNotByPosition(): void
    {
        $navigation = new Navigation([
            self::source(new NavSection(NavGroup::HELP, [new NavItem(label: 'Manual', url: '/manual/')], position: 0)),
            self::source(new NavSection(NavGroup::SETTINGS, [new NavItem(label: 'Settings', url: '/settings')], position: 1)),
            self::source(new NavSection(NavGroup::SYSTEM, [new NavItem(label: 'Telemetry', url: '/telemetry')], position: 90)),
            self::source(new NavSection(NavGroup::SYSTEM, [new NavItem(label: 'Files', url: '/files')], position: 30)),
            self::source(new NavSection(NavGroup::ORGANIZATION, [new NavItem(label: 'Team', url: '/team')], position: 40)),
            self::source(new NavSection(NavGroup::OBSERVATORY, [new NavItem(label: 'Areas', url: '/areas')], position: 99)),
        ]);

        self::assertSame(
            NavGroup::ORDER,
            array_map(static fn (NavSection $section): string => $section->label, $navigation->sections()),
        );
    }

    /**
     * A GROUP THE CONTRACT DOES NOT KNOW IS AN ERROR, not a fifth heading —
     * and the shell says which four exist, at the moment the author is
     * choosing between them.
     */
    public function testAnUnknownGroupIsRefusedWithTheFourNamed(): void
    {
        // DELIBERATELY THE OTHER SPELLING — the near miss this refusal
        // exists for. The product spells it "Organization"; a source that
        // typed the British form used to make a FIFTH heading, and a sidebar
        // with two Organizations in it answers "where am I" with a lie.
        $navigation = new Navigation([
            self::source(new NavSection('Organisation', [new NavItem(label: 'Team', url: '/team')])),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Organisation" is not a sidebar group');

        foreach (NavGroup::ORDER as $group) {
            $this->expectExceptionMessageMatches('/'.$group.'/');
        }

        $navigation->sections();
    }

    public function testRowsInsideAMergedSectionFollowTheDeclaredPositionsNotRegistrationOrder(): void
    {
        $navigation = new Navigation([
            self::source(new NavSection('System', [new NavItem(label: 'Telemetry', url: '/telemetry')], position: 90)),
            self::source(new NavSection('System', [new NavItem(label: 'Files', url: '/files')], position: 30)),
        ]);

        self::assertSame(
            ['Files', 'Telemetry'],
            array_map(static fn (NavItem $item): string => $item->label, $navigation->sections()[0]->items),
        );
    }

    public function testTheOneLitRowRuleReadsTheMergedSection(): void
    {
        $navigation = new Navigation([
            self::source(new NavSection('System', [new NavItem(label: 'Files', url: '/files', current: true)])),
            self::source(new NavSection('System', [new NavItem(label: 'Telemetry', url: '/telemetry', current: true)])),
        ]);

        $this->expectException(\LogicException::class);

        $navigation->sections();
    }

    private static function source(NavSection ...$sections): NavigationSourceInterface
    {
        return new class(array_values($sections)) implements NavigationSourceInterface {
            /** @param list<NavSection> $sections */
            public function __construct(private readonly array $sections)
            {
            }

            public function sections(): iterable
            {
                yield from $this->sections;
            }
        };
    }
}
