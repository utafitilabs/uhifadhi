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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as DoctrineBundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\RegistryBundle\Entity\FigureFact;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;

/**
 * The smoke test: registering the registry in a real kernel compiles a real
 * container. Everything else in this repository — and every module that ever
 * registers with the registry — rides on that.
 */
final class BundleBootTest extends RegistryKernelTestCase
{
    public function testTheBundleBootsInAHostKernel(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('RegistryBundle', $kernel->getBundles());
        self::assertInstanceOf(
            RegistryBundle::class,
            $kernel->getBundle('RegistryBundle'),
        );
    }

    /**
     * Config lives under "registry:", not the class-derived "registry_bundle:"
     * — the alias is part of the host contract and every installation writes it.
     */
    public function testItsConfigurationIsKeyedByTheRegistryAlias(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('registry', $kernel->getBundle('RegistryBundle')
            ->getContainerExtension()?->getAlias());
    }

    /**
     * The configured defaults reach the container, which is where the runtime
     * will read them from. "operations" is the wave-1 ruling: an unplaced module
     * is somebody's daily work, not a reading of the ecosystem.
     */
    public function testItsDefaultsReachTheContainer(): void
    {
        self::bootKernel();

        self::assertSame('operations', self::getContainer()->getParameter('registry.default_category'));
        self::assertFalse(self::getContainer()->getParameter('registry.dev_tools'));
    }

    /**
     * Zero-config persistence: the registry maps its own entity directory, so a
     * host never writes a doctrine mappings block for the catalogue tables —
     * `composer require` is the whole of the installation.
     */
    public function testItMapsItsOwnEntityDirectory(): void
    {
        self::bootKernel();

        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        /** @var EntityManagerInterface $em */
        $em = $doctrine->getManager();
        $driver = $em->getConfiguration()->getMetadataDriverImpl();
        // DoctrineBundle decorates the chain (custom id-generator support);
        // the namespace registry lives on the chain underneath.
        if ($driver instanceof DoctrineBundleMappingDriver) {
            $driver = $driver->getDriver();
        }

        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertArrayHasKey('Uhifadhi\Bundle\RegistryBundle\Entity', $driver->getDrivers());
        // And the catalogue is what comes out of it — mapped by a bundle the
        // host did nothing to configure beyond installing it. Note the kernel
        // this runs on: the registry alone, with no host resolving the area
        // interface, which a host that has not got there yet must still boot.
        // Canonicalising, not ordering: what comes out of the driver is whatever
        // order the directory was read in, which is the filesystem's business and
        // not a promise this bundle makes. The claim is that all three are mapped.
        self::assertEqualsCanonicalizing(
            [Module::class, AreaModule::class, FigureFact::class],
            array_map(
                static fn (ClassMetadata $metadata): string => $metadata->getName(),
                $em->getMetadataFactory()->getAllMetadata(),
            ),
        );
    }
}
