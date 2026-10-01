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

namespace Uhifadhi\Bundle\RegistryBundle\Settings;

use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;

/**
 * EVERY SETTING THE INSTALLATION KNOWS — the core's and each module's, from the
 * tagged sources — by key and by owner. Settings draws one page per owner.
 */
final class SettingsCatalogue
{
    /** @var array<string, SettingDefinition>|null */
    private ?array $byKey = null;

    /**
     * @param iterable<SettingDefinitionSourceInterface> $sources
     */
    public function __construct(private readonly iterable $sources)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): SettingDefinition
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException(\sprintf('No setting is called "%s".', $key));
    }

    /**
     * @return list<SettingDefinition> the owner's settings, in its own order
     */
    public function forOwner(string $owner): array
    {
        $found = array_values(array_filter($this->all(), static fn (SettingDefinition $d): bool => $d->owner === $owner));
        usort($found, static fn (SettingDefinition $a, SettingDefinition $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);

        return $found;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function all(): array
    {
        if (null !== $this->byKey) {
            return $this->byKey;
        }

        $byKey = [];
        foreach ($this->sources as $source) {
            foreach ($source->settingDefinitions() as $definition) {
                if (isset($byKey[$definition->key])) {
                    throw new \LogicException(\sprintf('The setting "%s" is declared twice.', $definition->key));
                }
                $byKey[$definition->key] = $definition;
            }
        }

        return $this->byKey = $byKey;
    }
}
