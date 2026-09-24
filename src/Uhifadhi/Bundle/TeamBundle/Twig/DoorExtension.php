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

namespace Uhifadhi\Bundle\TeamBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Uhifadhi\Bundle\TeamBundle\Access\Door;

/**
 * `{% if door('zones.configure', area) %}` — the one way a template asks
 * whether to draw a control.
 *
 * WHY NOT `is_granted`. It would work, and that is the problem: it takes any
 * string at all, so a template can name a pair nothing declares, or a role,
 * or a typo, and the door simply never opens with nothing anywhere saying
 * why. Going through one named function is what lets a conformance test walk
 * every door in the product and hold it against the routes — the second of
 * the four proofs the permission model rests on.
 *
 * IT IS THIS BUNDLE'S because the model is: the team owns positions, seats
 * and the check. A module calls `door()` in its own templates and requires
 * nothing new to do it.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html
 * @see vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php — registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension'); a reusable bundle is not autoconfigured, so config/services.php writes that tag by hand
 * @see vendor/symfony/security-core/Authorization/AuthorizationChecker.php — what Door asks, and what a first-class extension may hold: no request and no database at construction, so building the twig service during an asset build is still safe
 */
final class DoorExtension extends AbstractExtension
{
    public function __construct(
        private readonly Door $door,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('door', $this->door->opens(...)),
        ];
    }
}
