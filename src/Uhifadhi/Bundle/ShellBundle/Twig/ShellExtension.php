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

namespace Uhifadhi\Bundle\ShellBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Declares the shell's Twig functions. Nothing more — every one of them is
 * built by {@see ShellRuntime}.
 *
 * THE SPLIT IS NOT DECORATION. Twig constructs every EXTENSION as soon as the
 * `twig` service is built, and an image build does exactly that: asset
 * compilation fires an event, UX Icons warms its cache off it, and the icon
 * finder needs Twig. A build stage has no database and no request, so an
 * extension holding anything that reads either kills the BUILD rather than a
 * page. A runtime is constructed lazily, on the first call — which is a render,
 * which is a request.
 *
 * @see https://symfony.com/doc/current/templating/twig_extension.html — the extension declares the functions, a lazy-loaded runtime named as [Runtime::class, 'method'] builds them
 * @see vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php — registerForAutoconfiguration(ExtensionInterface::class)->addTag('twig.extension'); a reusable bundle is not autoconfigured, so config/services.php writes that tag by hand
 */
final class ShellExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // The sidebar's content, collected from the tagged sources.
            new TwigFunction('shell_nav', [ShellRuntime::class, 'nav']),
            // The sheets a page links because a component of somebody
            // else's may be drawn on it — a stylesheet link in the body
            // is not conforming HTML, so the head asks first.
            new TwigFunction('shell_stylesheets', [ShellRuntime::class, 'stylesheets']),
            // The tab strip: the sibling screens of wherever the viewer is —
            // an area's screens, a module's data places, or, on a configure
            // page, that surface's configure sections in their place.
            new TwigFunction('shell_tabs', [ShellRuntime::class, 'tabs']),
            // The organization-level frame: which module's page set this
            // request is inside, its screens as a strip, and how wide the
            // page is looking. Null anywhere else in the product.
            new TwigFunction('shell_org', [ShellRuntime::class, 'org']),
            // The surface's ONE configuration entry, or null where there is
            // nothing to configure. The frame draws it so no module does.
            new TwigFunction('shell_configure', [ShellRuntime::class, 'configure']),
            // "<page> — <place> — <brand>", composed once, by the shell.
            new TwigFunction('shell_title', [ShellRuntime::class, 'title']),
            // Who the top bar names — the viewer's card — or null when nobody.
            new TwigFunction('shell_user_badge', [ShellRuntime::class, 'userBadge']),
            // Whose installation this is — the name the bar states and the
            // short name beside it — or null on one nobody has named.
            new TwigFunction('shell_organization', [ShellRuntime::class, 'organization']),
            // What a visitor who has never chosen a theme gets.
            new TwigFunction('shell_default_theme', [ShellRuntime::class, 'defaultTheme']),
            // The wordmark beside the brand tile, and where the tile links.
            new TwigFunction('shell_brand', [ShellRuntime::class, 'brand']),
            new TwigFunction('shell_impersonation', [ShellRuntime::class, 'impersonation']),
            new TwigFunction('shell_sign_out_url', [ShellRuntime::class, 'signOutUrl']),
            // WHEN A STORED FIGURE IS TRUE AS OF — "as of 13:00" beside a
            // figure read from the facts ledger. Renders the shell's <time>.
            new TwigFunction('shell_as_of', [AsOfRuntime::class, 'asOf'], ['needs_environment' => true, 'is_safe' => ['html']]),
            // NOTHING HERE READS THE INSTALLATION. What is installed is one
            // page's data, and it reaches that page from its own controller as
            // an ordinary variable — a global that exists to serve one template
            // is a global in scope on every page in the platform.
        ];
    }
}
