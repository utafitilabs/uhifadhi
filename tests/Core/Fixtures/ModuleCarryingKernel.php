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
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\BareModuleProvider;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE THROWAWAY APPLICATION WITH ONE MODULE ON IT.
 *
 * The core ships no module, so a specification about `registry:sync` against
 * the core's own migrations needs a provider from somewhere: the registry
 * suite's smallest honest one, tagged by hand exactly as a module bundle tags
 * its own. Its own cache directory, so the compiled container with the module
 * in it is never mistaken for the one without.
 */
final class ModuleCarryingKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->services()
            ->set(BareModuleProvider::class)
            ->tag(RegistryBundle::MODULE_TAG);
    }

    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('application/cache/'.$this->environment.'-module');
    }
}
