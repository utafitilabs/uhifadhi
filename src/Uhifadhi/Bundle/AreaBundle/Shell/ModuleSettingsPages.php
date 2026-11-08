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

namespace Uhifadhi\Bundle\AreaBundle\Shell;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\SettingsModuleController;
use Uhifadhi\Bundle\AreaBundle\Service\SettingsCascadeService;
use Uhifadhi\Bundle\ShellBundle\Contract\ModuleSettingsPagesInterface;
use Uhifadhi\Bundle\ShellBundle\Model\ModuleSettingsPage;
use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * THE MODULES WITH A SETTINGS PAGE: every installed module whose slug owns a
 * setting this bundle's cascade draws. The module's name and description are
 * its own provider's, so a page exists from the moment the module is
 * installed, whatever the registry's table holds.
 */
final readonly class ModuleSettingsPages implements ModuleSettingsPagesInterface
{
    /**
     * @param iterable<ModuleProviderInterface> $providers every tagged module provider
     */
    public function __construct(
        private iterable $providers,
        private SettingsCascadeService $cascade,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** The installed module whose settings page this slug names, or null where it has none. */
    public function module(string $slug): ?ModuleProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($slug === $provider->slug()) {
                return $this->cascade->hasSettings($slug) ? $provider : null;
            }
        }

        return null;
    }

    public function pages(): array
    {
        $pages = [];
        foreach ($this->providers as $provider) {
            if ($this->cascade->hasSettings($provider->slug())) {
                $pages[$provider->name()] = new ModuleSettingsPage(
                    $provider->name(),
                    $provider->description(),
                    $this->urls->generate(SettingsModuleController::READ_ROUTE, ['slug' => $provider->slug()]),
                );
            }
        }
        ksort($pages);

        return array_values($pages);
    }
}
