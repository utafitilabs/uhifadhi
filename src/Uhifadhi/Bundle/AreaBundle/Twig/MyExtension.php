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

namespace Uhifadhi\Bundle\AreaBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Uhifadhi\Bundle\AreaBundle\Me\AreaMyCards;

/**
 * `area_my_span(from, to)` — hours on watch as a person's own pages print them
 * (`8 h 04`), in one place so the dashboard and the duty log never disagree.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html
 */
final class MyExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('area_my_span', AreaMyCards::span(...))];
    }
}
