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

use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Contracts\Facts\FactProviderInterface;

/**
 * AN INSTALLATION WITH TWO MODULES THAT COMPUTE FACTS, tagged by hand as a
 * module bundle tags its own, and a clock the specification sets.
 *
 * The clock is the framework's own `clock` service, replaced by a
 * {@see MockClock} — the documented way to fix "now" in a kernel test.
 *
 * @see https://symfony.com/doc/current/components/clock.html#writing-time-sensitive-tests
 */
class FactsHostKernel extends HostKernel
{
    /**
     * The installation's `registry:` configuration, set before booting.
     *
     * @var array<string, mixed>
     */
    public static array $registry = [];

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        if ([] !== static::$registry) {
            $container->extension('registry', static::$registry);
        }

        $services = $container->services();

        $services->set('test.facts.surveys', SurveyFactProvider::class)->tag(FactProviderInterface::TAG);
        $services->set('test.facts.tallies', TallyFactProvider::class)->tag(FactProviderInterface::TAG);

        $services->set('clock', MockClock::class)->args(['2026-09-25 14:00:00'])->public();

        foreach ([
            'registry.facts.reader',
            FigureFactRepository::class,
            'registry.facts.rebuild',
            'registry.facts.providers',
            'registry.facts.schedule_task',
        ] as $id) {
            $services->alias('test.'.$id, $id)->public();
        }
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'-facts-'.substr(hash('xxh128', serialize(static::$registry)), 0, 8);
    }
}
