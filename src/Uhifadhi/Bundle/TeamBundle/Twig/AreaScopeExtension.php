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
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * THE ONE FACT THE "SCOPED TO <AREA>" BANNER NEEDS — the name of the area a
 * bounded administrator is confined to, or nothing at all when they are
 * unbounded.
 *
 * The area-admin design draws a banner ("You are scoped to Southern Reserve") on the
 * team / department / position management chrome, shown to a bounded (area-X)
 * `team.manage` holder and to nobody else. Every one of those screens is a
 * separate controller, so the banner reads its one input through a Twig function
 * rather than each controller threading the same value into its render context —
 * the fence is stated in one partial, fed from one place.
 *
 * IT NAMES THE AREA THROUGH THE CONTRACT, NEVER AN AREA PACKAGE. The name comes
 * off {@see AreaInterface::getName()} on the
 * authority-area {@see AreaAuthority} already resolves for the voter, so this
 * module points at an area exactly as it points at a person, and requires
 * neither package to do it. A `null` answer is the whole signal: no area means
 * unbounded, which means no banner.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html
 * @see vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php — registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension'); a reusable bundle is not autoconfigured, so config/services.php writes that tag by hand
 */
final class AreaScopeExtension extends AbstractExtension
{
    public function __construct(
        private readonly AreaAuthority $authority,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('team_scope_area', $this->scopeArea(...)),
        ];
    }

    /**
     * The ground this administrator is confined to, in one fragment, or null
     * when they are unbounded (a tier or an organization-wide placement) or
     * not signed in — the null the banner reads as "show nothing".
     *
     * A PLACEMENT MAY NAME SEVERAL AREAS, so the fragment is the first plus a
     * count rather than one name: the banner has one line, and the person's
     * record has the full list.
     */
    public function scopeArea(): ?string
    {
        $areas = $this->authority->authorityAreas();
        if (null === $areas || [] === $areas) {
            return null;
        }

        $names = array_map(static fn (AreaInterface $a): string => (string) $a->getName(), $areas);

        return 1 === \count($names) ? $names[0] : \sprintf('%s +%d', $names[0], \count($names) - 1);
    }
}
