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

namespace Uhifadhi\Bundle\RegistryBundle\Service;

use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * A provider's answers, turned into a catalogue row.
 *
 * COERCED, NEVER TRUSTED. A module is written by somebody else and ships
 * on its own release cadence; its category and status are strings, and a typo in
 * one must not be able to break the catalogue for every other module. So an
 * unrecognised value falls back rather than throwing. The coercion is silent by
 * design — the module's tile still appears, filed somewhere sensible — which is
 * also its cost, so each fallback is chosen to be the one that is least wrong.
 *
 * INSTALLABLE OR BASE decides the INITIAL per-area state and nothing else. An
 * installable module arrives parked, so an admin opts it in per area; a base
 * module arrives active, because it is machinery other surfaces already import
 * and an area with it switched off does not have fewer features, it has broken
 * screens. Afterwards the area governs itself: base is not pinned, not
 * permanent, and the seed is create-only.
 *
 * Pure mapping — no container, no database, no clock.
 */
final readonly class ProviderCatalogueMapper
{
    /**
     * @param string $defaultCategory where this DEPLOYMENT files a module it cannot place
     *                                (the `registry.default_category` key), so the platform
     *                                default is not hardcoded a second time here
     */
    public function __construct(
        private string $defaultCategory = 'operations',
    ) {
    }

    /**
     * @param int $registrationOrder the module's index among the installed providers,
     *                               used only as the tie-break for modules that declare
     *                               no position of their own
     *
     * @return array{slug: string, name: string, description: ?string, category: ModuleCategory, status: ModuleStatus,
     *     source: string, icon: ?string, pinned: bool, active: bool, position: int}
     */
    public function toRow(ModuleProviderInterface $provider, int $registrationOrder): array
    {
        return [
            'slug' => $provider->slug(),
            'name' => $provider->name(),
            // One line under the name in the modules register, or nothing: a
            // module that says nothing beyond its name is not padded out.
            'description' => $provider->description(),
            'category' => ModuleCategory::tryFrom($provider->category()) ?? $this->fallbackCategory(),
            // A module that is installed is running.
            'status' => ModuleStatus::tryFrom($provider->status()) ?? ModuleStatus::Live,
            // No provenance line is an empty one, never a null in a tile.
            'source' => $provider->dataSource() ?? '',
            // Null means "whatever the host draws by default" — rendering is not decided here.
            'icon' => $provider->icon(),
            'pinned' => $provider->pinned(),
            'active' => $provider->base(),
            'position' => $this->position($provider, $registrationOrder),
        ];
    }

    /**
     * A DECLARED POSITION IS HONOURED. The contract documents position() as an
     * ordering hint, and a contract method nothing reads is a lie in the
     * contract — an author who sets it would have no way to discover it did
     * nothing. So the provider's answer wins, and registration order is only the
     * tie-break for the modules that declared none.
     *
     * The trait's default is 0, which is therefore read as "no preference" — so
     * for the modules that say nothing (nearly all of them) the ordering is
     * exactly what it always was.
     */
    private function position(ModuleProviderInterface $provider, int $registrationOrder): int
    {
        $declared = $provider->position();

        return 0 !== $declared ? $declared : $registrationOrder;
    }

    private function fallbackCategory(): ModuleCategory
    {
        return ModuleCategory::tryFrom($this->defaultCategory) ?? ModuleCategory::Operations;
    }
}
