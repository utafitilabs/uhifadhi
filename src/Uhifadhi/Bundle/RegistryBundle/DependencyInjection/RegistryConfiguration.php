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

namespace Uhifadhi\Bundle\RegistryBundle\DependencyInjection;

use Cron\CronExpression;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures the registry runtime
 * in config/packages/registry.yaml:
 *
 *   registry:
 *     default_category: operations   # where an unplaced module is filed
 *     dev_tools: false               # dev-only tooling (when@dev / when@test)
 *     facts:
 *       schedule: ['0 6-20 * * *', '0 2 * * *']   # when the open periods are recomputed
 *       timezone: ~                  # the zone those hours are in; ~ is PHP's default
 *
 * DELIBERATELY TINY, and it should stay that way. The registry's job is to carry
 * what modules declare; nearly everything a deployment might want to say is
 * said by a module's own config, not here. There is no key for "which modules
 * exist" and there never will be — installing the bundle IS the declaration.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class RegistryConfiguration
{
    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The registry root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('default_category')
                    ->info('Catalogue category an unrecognised provider category is coerced to.')
                    ->defaultValue('operations')->cannotBeEmpty()
                ->end()
                ->booleanNode('dev_tools')
                    ->info('Register dev-only tooling (seeders, fixtures). The recipe enables this via when@dev/when@test.')
                    ->defaultFalse()
                ->end()
                /*
                 * WHEN THE WORKER RECOMPUTES THE FACTS OF THE PERIODS OPEN NOW.
                 * Every hour of the working day and once at night: nobody
                 * watches coverage tick, and "as of 13:00" is as true as a
                 * reader needs. Each entry is a cron expression the scheduler
                 * reads (https://symfony.com/doc/current/scheduler.html — "cron
                 * expressions"); the hours are the installation's, in
                 * `timezone`, or PHP's default zone when that is left out.
                 */
                ->arrayNode('facts')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('schedule')
                            ->info('Cron expressions: when the open periods’ facts are recomputed.')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $expression): bool => !\is_string($expression) || !CronExpression::isValidExpression($expression))
                                    ->thenInvalid('%s is not a cron expression.')
                                ->end()
                            ->end()
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['0 6-20 * * *', '0 2 * * *'])
                        ->end()
                        ->scalarNode('timezone')
                            ->info('The zone the schedule’s hours are in. Null: PHP’s default zone.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
