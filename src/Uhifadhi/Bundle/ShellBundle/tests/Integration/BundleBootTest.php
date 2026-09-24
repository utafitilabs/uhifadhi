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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration;

use Twig\Loader\FilesystemLoader;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;

/**
 * The smoke test: registering the shell in a real kernel compiles a real
 * container and gives Twig a real namespace. Every page in the platform — the
 * host's own and every module's — rides on those two facts.
 */
final class BundleBootTest extends ShellKernelTestCase
{
    public function testTheBundleBootsInAHostKernel(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('ShellBundle', $kernel->getBundles());
        self::assertInstanceOf(
            ShellBundle::class,
            $kernel->getBundle('ShellBundle'),
        );
    }

    /**
     * Config lives under "shell:", not the class-derived "uhifadhi_shell:"
     * — the alias is part of the host contract and every installation writes it.
     */
    public function testItsConfigurationIsKeyedByTheShellAlias(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('shell', $kernel->getBundle('ShellBundle')
            ->getContainerExtension()?->getAlias());
    }

    public function testItsDefaultsReachTheContainer(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame('Uhifadhi', $container->getParameter('shell.brand_name'));
        self::assertSame('organization_dashboard', $container->getParameter('shell.home_route'));
        self::assertSame('light', $container->getParameter('shell.default_theme'));
        self::assertFalse($container->hasParameter('shell.dev_tools'), 'A knob nothing reads is a lie in the contract.');
    }

    /**
     * THE NAMESPACE IS THE ADDRESS OF THE CONTRACT. Every module page in the
     * platform will name it — `{% extends '@Shell/page.html.twig' %}`
     * — so it is registered and asserted before the first template exists.
     * Symfony derives it from the bundle's name minus "Bundle" and the bundle-root
     * templates/ directory; both halves of that are load-bearing and both are
     * checked here rather than assumed.
     */
    public function testItsTemplateNamespaceResolves(): void
    {
        self::bootKernel();

        $loader = $this->twig()->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $loader);
        self::assertContains('Shell', $loader->getNamespaces());

        $paths = $loader->getPaths('Shell');
        self::assertNotSame([], $paths, 'The shell must expose its templates/ directory to Twig.');
        foreach ($paths as $path) {
            self::assertDirectoryExists($path);
        }
    }

    /**
     * The stylesheet path is a published constant because it is written in more
     * than one place; this pins it to the AssetMapper convention the host and
     * every module bundle already rely on, so a rename of the bundle cannot
     * silently move it.
     */
    public function testItPublishesItsStylesheetPath(): void
    {
        self::assertSame('bundles/shell/shell.css', ShellBundle::STYLESHEET);
    }
}
