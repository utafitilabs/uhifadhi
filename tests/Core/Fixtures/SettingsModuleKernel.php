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

namespace Uhifadhi\Core\Tests\Core\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE THROWAWAY APPLICATION WITH A MODULE THAT DECLARES SETTINGS, tagged by
 * hand exactly as a module bundle tags its own. Its own cache directory, so
 * the compiled container with the module in it is never mistaken for the one
 * without.
 */
final class SettingsModuleKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $services = $container->services();
        $services->set(SightingsModuleProvider::class)->tag(RegistryBundle::MODULE_TAG);
        $services->set(SightingsSettings::class)->tag(SettingDefinitionSourceInterface::TAG);
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('application/cache/'.$this->environment.'-settings-module');
    }
}
