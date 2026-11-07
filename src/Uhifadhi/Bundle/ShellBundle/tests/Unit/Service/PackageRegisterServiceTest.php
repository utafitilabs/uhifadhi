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
use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Model\InstalledPackage;
use Uhifadhi\Bundle\ShellBundle\Service\PackageRegisterService;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * WHAT IS INSTALLED, AS A REGISTER: the installed-from chip (a release or a
 * branch) with its counts over every package, a search over the name and the
 * package's line, and the page the Shell's foot draws.
 */
final class PackageRegisterServiceTest extends TestCase
{
    public function testAPackageIsInstalledFromAReleaseOrFromABranch(): void
    {
        self::assertSame(PackageRegisterService::RELEASE, PackageRegisterService::sourceOf(new InstalledPackage('uhifadhi/a', '0.4.2')));
        self::assertSame(PackageRegisterService::RELEASE, PackageRegisterService::sourceOf(new InstalledPackage('uhifadhi/a', 'v1.0.0-beta1')));
        self::assertSame(PackageRegisterService::BRANCH, PackageRegisterService::sourceOf(new InstalledPackage('uhifadhi/a', '0.2.x-dev')));
        self::assertSame(PackageRegisterService::BRANCH, PackageRegisterService::sourceOf(new InstalledPackage('uhifadhi/a', 'dev-main')));
        self::assertSame(PackageRegisterService::BRANCH, PackageRegisterService::sourceOf(new InstalledPackage('uhifadhi/a', 'dev')));
    }

    public function testTheChipCountsEveryPackageAndTheBarNarrows(): void
    {
        $register = new PackageRegisterService(new TablePageService());

        $read = $register->read(Request::create('/settings/installation', 'GET', ['from' => 'branch']), self::packages());
        self::assertSame(['all' => 3, PackageRegisterService::RELEASE => 2, PackageRegisterService::BRANCH => 1], $read['counts']);
        self::assertSame(['uhifadhi/uhifadhi'], array_map(static fn (InstalledPackage $p): string => $p->name, $read['rows']));
        self::assertSame(['from' => 'branch', 'q' => null], $read['filter']);

        $read = $register->read(Request::create('/settings/installation', 'GET', ['q' => 'TRAIL']), self::packages());
        self::assertSame(['uhifadhi/trail-module'], array_map(static fn (InstalledPackage $p): string => $p->name, $read['rows']), 'the line is searched, in any case');

        $read = $register->read(Request::create('/settings/installation', 'GET', ['from' => 'nowhere']), self::packages());
        self::assertCount(3, $read['rows'], 'a source the chip does not offer narrows nothing');
        self::assertNull($read['filter']['from']);
    }

    public function testTheFootIsTheShellsPageOfWhatIsLeft(): void
    {
        $read = new PackageRegisterService(new TablePageService())->read(Request::create('/settings/installation', 'GET', ['from' => 'release', 'per' => 'all']), self::packages());

        self::assertSame(2, $read['foot']['count']);
        self::assertSame('packages', $read['foot']['noun']);
        self::assertSame('package', $read['foot']['one']);
        self::assertSame('all', $read['foot']['perPage']);
        self::assertStringContainsString('from=release', $read['foot']['links']['sizes']['20']);
    }

    /**
     * @return list<InstalledPackage>
     */
    private static function packages(): array
    {
        return [
            new InstalledPackage('uhifadhi/uhifadhi', '0.2.x-dev', 'the core'),
            new InstalledPackage('uhifadhi/trail-module', '0.4.0', null, 'Trails and their walkers'),
            new InstalledPackage('uhifadhi/gate-module', '1.2.0', null, 'Gates and the barrier log'),
        ];
    }
}
