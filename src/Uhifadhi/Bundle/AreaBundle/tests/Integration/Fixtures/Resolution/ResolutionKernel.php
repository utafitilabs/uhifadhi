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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Fixtures\Resolution;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\CheckoutTempDirTrait;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

/**
 * THE SMALLEST INSTALLATION THE QUESTION CAN HONESTLY BE ASKED IN: framework,
 * doctrine, PostGIS, the registry and this bundle.
 *
 * SMALLEST does not mean fewest bundles — it means nothing present that could
 * ANSWER the question on this bundle's behalf. The registry is here because the registry
 * is what ASKS: its `AreaModule` row points at `AreaInterface` and its own
 * bundle prepends no resolution. PostGIS is here because this bundle's entity
 * has a multipolygon column and a kernel without the type would not compile.
 * Neither of them can supply the answer, so the assertions are about area.
 *
 * The whole point is what is ABSENT from `configureContainer()`: there is no
 * `resolve_target_entities` in the plain variant, because an installation should
 * not have to write one.
 */
final class ResolutionKernel extends Kernel
{
    use CheckoutTempDirTrait;

    /**
     * @param array<class-string, class-string> $override what the INSTALLATION says, if anything —
     *                                                    the escape hatch under test
     */
    public function __construct(
        private readonly array $override = [],
        private readonly string $variant = 'plain',
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new UtafitiLabsPostGISBundle();
        yield new RegistryBundle();
        yield new MercureBundle();
        yield new AreaBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
        ]);

        $orm = [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
            'mappings' => [
                // The installation's own entities. In the plain variant this
                // maps a directory whose one class is never named by anything —
                // exactly the position an installation is in before it disagrees.
                'ResolutionFixtures' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
                    'is_bundle' => false,
                ],
            ],
        ];

        // Written ONLY by the variant testing the escape hatch. The plain kernel
        // says nothing, which is the case an installation is in.
        if ([] !== $this->override) {
            $orm['resolve_target_entities'] = $this->override;
        }

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(UHIFADHI_TEST_DATABASE_URL)%'],
            'orm' => $orm,
        ]);

        // The hub the area bundle requires, with no address — see TestKernel.
        $container->extension('mercure', [
            'hubs' => [
                'default' => [
                    'url' => '',
                    'public_url' => '',
                    'jwt' => ['secret' => 'test-mercure-jwt-secret-at-least-256-bits-long'],
                ],
            ],
        ]);
        $container->services()->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /** Per-variant, so one kernel's compiled container can never answer for the other. */
    public function getCacheDir(): string
    {
        return $this->checkoutTempDir('area/resolution/'.$this->variant);
    }

    public function getLogDir(): string
    {
        return $this->checkoutTempDir('area/resolution/log');
    }
}
