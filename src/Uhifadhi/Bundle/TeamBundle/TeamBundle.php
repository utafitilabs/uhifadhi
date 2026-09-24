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

namespace Uhifadhi\Bundle\TeamBundle;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Uhifadhi\Bundle\TeamBundle\DependencyInjection\TeamConfiguration;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Entity\UserInterface as ContractUserInterface;

/**
 * TEAM — who an installation's people are, and how they sign in.
 *
 * The skeleton is the application, the registry carries the modules, the shell
 * is what you see, and this is who is looking. It owns the account, the
 * position that bundles permissions, the departments those positions are filed
 * under, the credential a field client presents, and the screens a person meets
 * before they are anybody: sign in, forgotten password, accept an invitation.
 *
 * IT IS PEOPLE, AND ONLY PEOPLE. It ships no firewall and no access rule:
 * those are one file in the installing project, because only that project knows
 * which of its paths are public, and the skeleton ships that file. What this
 * bundle gives the file to name is the provider entity, the
 * `team.user_checker` that refuses a deactivated account at the door, and the
 * routes the sign-in form posts to.
 *
 * ZERO-CONFIG PERSISTENCE. Registering the bundle maps its own entities (no
 * doctrine block for team_* tables in the installing project) and ANSWERS THE
 * USER CONTRACT (no resolve_target_entities either — see prependExtension), so
 * every module that points a record at a person has an entity to point at.
 */
final class TeamBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S OWN VOCABULARY IS SERVED FROM. The sign-in card is
     * the shell's document plus this sheet — the shell draws frames and knows
     * nothing about a login form, so the card's rules ship here. Stated once,
     * as a constant, because templates/login.html.twig links it and anyone
     * theming the screen has to be able to name it.
     */
    public const string STYLESHEET = 'bundles/team/team.css';

    /**
     * THE PERFORMANCE BOARD'S OWN VOCABULARY, in its own sheet.
     *
     * Separate from {@see STYLESHEET} because it is the grammar of one
     * surface rather than of the bundle: the heat cell, the band rule, the
     * department stamp and the legend are needed by the pages that draw a
     * topic's matrix and by nothing else, so a sign-in screen does not pay
     * for them. A page that renders a matrix links this after the sheet
     * above.
     */
    public const string PERFORMANCE_STYLESHEET = 'bundles/team/performance.css';

    /**
     * The AssetMapper namespace this bundle's assets/ directory is mapped to.
     *
     * It is the npm-style form of the composer package name, and it has to be:
     * Flex keys assets/controllers.json by '@'.<composer package name>, and
     * StimulusBundle resolves that key back to this directory. A different name
     * here is a controller the host cannot find.
     */
    public const string ASSET_NAMESPACE = '@uhifadhi/team-bundle';

    /**
     * The prefix every one of this bundle's Stimulus controllers is addressed
     * by in a template — StimulusBundle's own normalisation of the namespace
     * above ('@' dropped, '/' and '_' to '-'), so `permission-group` is reached
     * as `uhifadhi--team-bundle--permission-group`.
     */
    public const string CONTROLLER_PREFIX = 'uhifadhi--team-bundle--';

    /** Config lives under "team:", not the class-derived "uhifadhi_team:". */
    protected string $extensionAlias = 'team';

    /**
     * THE BUNDLE CLASS SITS AT THE PACKAGE ROOT, beside this bundle's own
     * composer.json, because after a split the package root IS the bundle
     * root.
     *
     * AbstractBundle assumes otherwise. Its default "assume the modern
     * directory structure" answer is `dirname($file, 2)`, which is right for a
     * bundle whose class lives in src/ and two directories too high for one
     * whose class lives at the root — templates/ and public/ would be looked
     * for outside the package.
     *
     * @see vendor/symfony/http-kernel/Bundle/AbstractBundle.php
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        TeamConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /*
         * PREPENDED, LIKE EVERY BLOCK THIS METHOD WRITES — `prependExtensionConfig()`
         * on the builder, the form the docs and symfony/ux-map write — so this
         * config goes first and the application's own doctrine.yaml wins, which
         * is the entire reason shipping a resolution here is safe rather than
         * presumptuous.
         * @see https://symfony.com/doc/current/bundles/prepend_extension.html
         * @see vendor/symfony/ux-map/src/UXMapBundle.php:117
         * It
         * changes nothing for the mappings below, whose key is this bundle's
         * alone and which nothing else writes.
         */

        // Zero-config persistence: the bundle maps its own entities, so an
        // installation never writes a doctrine mappings block for team_* tables.
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Team' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Bundle\\TeamBundle\\Entity',
                            'is_bundle' => false,
                        ],
                    ],

                    /*
                     * THE PACKAGE THAT PROVIDES THE ANSWER IS THE PACKAGE THAT
                     * STATES IT.
                     *
                     * Nearly every module keeps records with a name on them and
                     * points at the CONTRACT rather than at this bundle's User,
                     * because a module that type-hinted the latter would be a
                     * module no installation could run without this one. So
                     * something has to say what the interface means, and for as
                     * long as this bundle is installed the answer is not in
                     * doubt: it is this bundle's User.
                     *
                     * IT IS NOT A HAND-STEP. A hand-step is for a decision
                     * only the installation can make; this is not one — the
                     * line says exactly one thing and has one right value. Left
                     * to the installation its cost is real, because forgetting
                     * it fails a long way from its cause: the container
                     * compiles, the kernel boots, and
                     * `doctrine:migrations:diff` stops on "Class
                     * 'Uhifadhi\Contracts\Entity\UserInterface' does not
                     * exist" with nothing pointing back at the paragraph that
                     * was missed.
                     *
                     * THE ESCAPE HATCH IS THE CONFIGURATION RULE AND NOT A
                     * SWITCH INVENTED HERE: prepended configuration LOSES to
                     * the application's. An installation whose people are its own
                     * entity names that entity under `doctrine.orm.
                     * resolve_target_entities` in its own config and its answer
                     * wins, with nothing to disable first. That property is
                     * precisely what makes shipping a default safe, so it is
                     * tested rather than assumed — see
                     * tests/Integration/Identity/ResolveTargetEntitiesTest.
                     */
                    'resolve_target_entities' => [
                        ContractUserInterface::class => User::class,
                    ],
                ],
            ]);
        }

        /*
         * THE TWO BUDGETS THE FIELD DOOR SPENDS, so an installation writes no
         * rate-limiter configuration at all.
         *
         * PREPENDED, which makes them a DEFAULT rather than a decree: an
         * installation that wants other numbers writes them in its own
         * framework.yaml and its answer wins, with nothing to switch off first.
         * Guarded on the extension because a bundle may not assume the
         * framework's configuration exists in whatever kernel it was put in.
         *
         * FIXED WINDOW, not sliding: what an operator is told is "wait a
         * minute", and a fixed window is the policy that makes that sentence
         * true. Five per identifier is a targeted guess stopped; twenty per
         * address is a spray stopped, and both are far above what a person
         * mistyping a passcode ever reaches.
         *
         * @see https://symfony.com/doc/current/rate_limiter.html
         */
        if ($builder->hasExtension('framework') && interface_exists(RateLimiterFactoryInterface::class)) {
            $builder->prependExtensionConfig('framework', [
                'rate_limiter' => [
                    'team_token_id' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'],
                    'team_token_ip' => ['policy' => 'fixed_window', 'limit' => 20, 'interval' => '1 minute'],
                ],
            ]);
        }

        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/team` and content-versioned — no config
        // here, no assets:install.

        // THE MATRIX'S ONE ENHANCEMENT, SHIPPED WITH THE MATRIX. A bundle
        // contributes no importmap entry, but it does contribute an AssetMapper
        // path and a `symfony.controllers` block in assets/package.json, which
        // is how every symfony/ux package ships a Stimulus controller. Flex
        // writes the host's assets/controllers.json on install; nothing is
        // built. The composer keyword `symfony-ux` is what makes Flex look in
        // here at all — without it everything installs and nothing binds.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            // PREPENDED, THE SHAPE EVERY symfony/ux BUNDLE WRITES — and the one form
            // every block in this method takes, `prependExtensionConfig()` on the
            // builder, so this path goes FIRST and an installation's own framework
            // config wins: \"any other settings done explicitly inside the config/*
            // files would override these prepended settings\".
            //
            // @see https://symfony.com/doc/current/bundles/prepend_extension.html
            // @see https://symfony.com/doc/current/frontend/create_ux_bundle.html
            // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
            // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:58
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/assets' => self::ASSET_NAMESPACE,
                    ],
                ],
            ]);
        }

        // THE TABLES ARRIVE WITH THE CODE. The account, the position, the
        // department and the field token are this bundle's, so their DDL ships
        // here too, under the bundle's own namespace — the shape the
        // migrations bundle documents for a bundle-shipped history:
        //
        // > migrations_paths:
        // >     'SomeBundle\Migrations': '@SomeBundle/Migrations'
        //
        // @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
        // @see vendor/doctrine/doctrine-migrations-bundle/src/DependencyInjection/DoctrineMigrationsExtension.php
        //
        // Guarded: an application may install this bundle without the migrations
        // bundle in its kernel, and there it simply has no history to run.
        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Bundle\\TeamBundle\\Migrations' => __DIR__.'/migrations',
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('config/services.php');

        // Where a visitor who asks for /login while already signed in is sent.
        // A PATH: this bundle cannot know what an installation calls its home
        // screen. See SecurityController for why that is not a route name.
        $builder->setParameter(
            'team.after_sign_in_path',
            \is_string($config['after_sign_in_path'] ?? null) ? $config['after_sign_in_path'] : '/',
        );

        // The line under the mark on the sign-in card. A deployment says who it
        // is here rather than by patching a template out of the vendor tree.
        $builder->setParameter(
            'team.sign_in_lede',
            \is_string($config['sign_in_lede'] ?? null)
                ? $config['sign_in_lede']
                : TeamConfiguration::DEFAULT_SIGN_IN_LEDE,
        );

        // WHAT A LETTER NEEDS THAT A TRANSPORT HAS NO OPINION ABOUT. An empty
        // from-address is the honest default: it is what makes Mail report that
        // this installation cannot send, so the invite path refuses itself in
        // writing instead of dropping a colleague's invitation on the floor.
        $builder->setParameter(
            'team.mail_from',
            \is_string($config['mail_from'] ?? null) ? $config['mail_from'] : '',
        );

        $builder->setParameter(
            'team.installation_name',
            \is_string($config['installation_name'] ?? null)
                ? $config['installation_name']
                : TeamConfiguration::DEFAULT_INSTALLATION_NAME,
        );
    }
}
