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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\TestKernel;

/**
 * AN INSTALLATION WITH THE CORE'S RECIPE LINE for the statement timeout:
 *
 *   registry:
 *       statement_timeout_ms: '%env(int:DATABASE_STATEMENT_TIMEOUT_MS)%'
 *
 * The same compiled container serves the console specification in-process and
 * the web specification through the built-in server's front controller
 * (Fixtures/web/index.php), as one image's cache serves both.
 */
final class StatementTimeoutKernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('registry', [
            'statement_timeout_ms' => '%env(int:DATABASE_STATEMENT_TIMEOUT_MS)%',
        ]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'-statement-timeout';
    }
}
