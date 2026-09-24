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

namespace Uhifadhi\Bundle\ShellBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures the shell in
 * config/packages/shell.yaml:
 *
 *   shell:
 *     brand_name: Uhifadhi           # the wordmark beside the brand tile
 *     home_route: organization_dashboard  # where the brandmark links
 *     default_theme: light           # light | dark | system
 *
 * DELIBERATELY TINY, and it must stay that way — a layout bundle is where
 * configuration goes to breed. Every knob here is something the shell CANNOT
 * know: the deployment's name, the host's route names, and a first-visit
 * preference. Anything a designer would decide belongs in the stylesheet, and
 * anything a page would decide belongs in a block.
 *
 * A FOURTH KEY WAS HERE AND IS GONE. `dev_tools` reserved a flag for a dev-only
 * socket gallery — every block and every token on one page — and the boundary
 * this bundle is built on turned out to forbid the thing it gated: the gallery
 * is a PAGE, a page needs a route and a controller, and the shell ships
 * neither. A knob nothing reads is exactly the lie in a contract that this
 * package's own rules name; it went rather than shipped. If a gallery is ever
 * wanted, it is a template here and a dev-only route in the application.
 *
 * THE WIDGET MACHINERY HAS NO KEY EITHER, and the absence is the decision.
 * There is an obvious place for a `widgets:` section and nothing to put in it:
 * where a dashboard's widgets live, what they are called and how wide they sit
 * are the declaring module's to say, in its catalogue, and a second place to
 * say them would be a second place for the two to disagree. An empty section
 * shipped "for symmetry" is a knob nothing reads, which this tree already
 * refused once.
 *
 * There is deliberately no key listing nav entries, no key listing area tabs
 * and no key listing modules. Those arrive as data through the contracts in
 * src/Contract, because a second place to declare a nav entry is a second place
 * for the two to disagree — and a YAML nav is a nav no permission check ever
 * reaches.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class ShellConfiguration
{
    /** The themes the shell ships. Both first-class; neither is a variant of the other. */
    public const array THEMES = ['light', 'dark', 'system'];

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The shell root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('brand_name')
                    ->info('The wordmark rendered beside the brand tile in the sidebar.')
                    ->defaultValue('Uhifadhi')->cannotBeEmpty()
                ->end()
                ->scalarNode('home_route')
                    ->info('Route the brandmark links to. The shell is installed by an application and cannot know its route names; the default is the organization dashboard the core ships, and an installation that puts something else at its front door says so here.')
                    ->defaultValue('organization_dashboard')->cannotBeEmpty()
                ->end()
                ->enumNode('default_theme')
                    ->info('Theme a visitor who has never chosen one gets: light, dark, or the operating system\'s preference.')
                    ->values(self::THEMES)
                    ->defaultValue('light')
                ->end()
            ->end()
        ;
    }
}
