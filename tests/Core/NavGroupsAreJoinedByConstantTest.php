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

namespace Uhifadhi\Core\Tests\Core;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Contracts\Shell\NavGroup;

/**
 * EVERY NAV SOURCE THE CORE SHIPS JOINS ITS GROUP BY CONSTANT.
 *
 * The shell refuses an unknown group at render, which catches a typo in
 * whoever's installation reached that page first. This catches it here, in
 * the repository that publishes the contract, and it catches the other half
 * too: a source that files under the RIGHT group by writing the string is
 * conforming by luck. The constant is how the group stops being a string a
 * second module can misspell — and the core is the example every module
 * bundle is read against.
 */
#[CoversNothing]
final class NavGroupsAreJoinedByConstantTest extends TestCase
{
    /** The core's own sidebar contributors. */
    private const array SOURCES = [
        \Uhifadhi\Bundle\AreaBundle\Shell\OrgDashboardNavigation::class => NavGroup::OBSERVATORY,
        \Uhifadhi\Bundle\AreaBundle\Shell\AreaNavigation::class => NavGroup::OBSERVATORY,
        \Uhifadhi\Bundle\TeamBundle\Shell\PerformanceNavigation::class => NavGroup::OBSERVATORY,
        \Uhifadhi\Bundle\ShellBundle\Service\OrgModulesNavigation::class => NavGroup::OBSERVATORY,
        \Uhifadhi\Bundle\TeamBundle\Shell\TeamNavigation::class => NavGroup::ORGANIZATION,
        \Uhifadhi\Bundle\ShellBundle\Service\SettingsNavigation::class => NavGroup::SETTINGS,
        \Uhifadhi\Bundle\ShellBundle\Service\ManualNavigation::class => NavGroup::HELP,
    ];

    /**
     * NOT ONE SOURCE IS MISSING FROM THE LIST ABOVE. A contributor added
     * without a line here would be checked by nothing, which is how the
     * sweep quietly stops sweeping.
     */
    public function testEverySourceInSrcIsChecked(): void
    {
        $found = [];
        foreach (self::classesInSource() as $class) {
            if (is_a($class, NavigationSourceInterface::class, true)) {
                $found[] = $class;
            }
        }

        sort($found);
        $checked = array_keys(self::SOURCES);
        sort($checked);

        self::assertSame($checked, $found, 'A nav source was added or removed; name it here with the group it joins.');
    }

    #[DataProvider('theSources')]
    public function testTheSourceFilesUnderAGroupTheContractKnows(string $class, string $group): void
    {
        $declared = \constant($class.'::SECTION');
        \assert(\is_string($declared));

        self::assertTrue(NavGroup::knows($declared), \sprintf('%s files under "%s", which is no sidebar group.', $class, $declared));
        self::assertSame($group, $declared);
    }

    /**
     * AND IT NAMES THE CONSTANT, not the label. Read off the source file,
     * because a value comparison cannot tell a constant from the string it
     * resolves to — and the string is exactly what this ruling removes.
     */
    #[DataProvider('theSources')]
    public function testTheSourceNamesTheConstantAndNotTheLabel(string $class, string $group): void
    {
        if (!class_exists($class)) {
            self::fail(\sprintf('%s does not exist.', $class));
        }

        $file = (string) (new \ReflectionClass($class))->getFileName();
        $written = (string) file_get_contents($file);

        self::assertStringContainsString(
            'public const string SECTION = NavGroup::',
            $written,
            \sprintf('%s must join its group by constant.', $class),
        );
        self::assertStringNotContainsString(
            \sprintf("SECTION = '%s'", $group),
            $written,
            \sprintf('%s still files under the literal "%s".', $class, $group),
        );
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function theSources(): \Generator
    {
        foreach (self::SOURCES as $class => $group) {
            yield substr((string) strrchr($class, '\\'), 1) => [$class, $group];
        }
    }

    /**
     * Every class the core's `src/` declares, by fully qualified name.
     *
     * @return \Generator<string>
     */
    private static function classesInSource(): \Generator
    {
        $root = \dirname(__DIR__, 2).'/src/Uhifadhi';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ('php' !== $file->getExtension() || str_contains($file->getPathname(), '/tests/')) {
                continue;
            }

            $written = (string) file_get_contents($file->getPathname());

            if (1 === preg_match('/^namespace\s+([^;]+);/m', $written, $namespace)
                && 1 === preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $written, $class)) {
                yield trim($namespace[1]).'\\'.$class[1];
            }
        }
    }
}
