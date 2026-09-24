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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Settings\OrganizationIdentitySourceInterface;

/**
 * THE STAND-IN HOST, PLUS THE ONE LINE AN INSTALLATION WRITES WHEN IT HAS BEEN
 * NAMED — the alias the settings section asks for:
 *
 *     $services->alias('shell.settings.organization_identity', OurIdentity::class);
 *
 * It is a kernel of its own rather than a static on {@see HostKernel} because
 * the contract's "nobody has said" state IS the missing alias, and an alias is
 * decided when the container compiles, not when a test body runs. Two kernels,
 * two states, and the plain host keeps proving that a shell renders for an
 * installation nobody has named yet.
 */
final class NamedHostKernel extends HostKernel
{
    /** What Settings › Organization holds. Set in a test body, reset between tests. */
    public static OrganizationIdentity $organization;

    public static function reset(): void
    {
        parent::reset();

        self::$organization = new OrganizationIdentity('Uhifadhi Conservation Authority', 'UCA');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $services = $container->services();

        $services->set(FixtureOrganizationIdentitySource::class)->public();
        $services->alias(OrganizationIdentitySourceInterface::SERVICE, FixtureOrganizationIdentitySource::class);
    }
}
