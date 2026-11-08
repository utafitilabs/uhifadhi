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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsRefused;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingValues;
use Uhifadhi\Bundle\ShellBundle\Model\CascadeChoice;
use Uhifadhi\Bundle\ShellBundle\Model\CascadePlace;
use Uhifadhi\Bundle\ShellBundle\Model\CascadeSetting;
use Uhifadhi\Bundle\ShellBundle\Model\CascadeTable;
use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * ONE OWNER'S SETTINGS AS A CASCADE OVER THE AREAS: the table Settings draws,
 * and the save that turns its posted form back into changes for the store.
 *
 * THE FORM POSTS THE WHOLE STATE, not a diff: `org[<key>]` is the default's
 * field, `area[<key>][<uuid>]` an area's own value, and an area with no field
 * follows the default. What differs from the store is written; an area whose
 * value is gone is reset. Number and Choice settings have a control here; a
 * choice posts its stored value, as its radio's value.
 */
final readonly class SettingsCascadeService
{
    public function __construct(
        private SettingsCatalogue $catalogue,
        private SettingsWriter $writer,
        private AreaOfInterestRepository $areas,
    ) {
    }

    public function table(string $owner, string $organization): CascadeTable
    {
        $areas = $this->areas->findAllOrdered();
        $groups = [];
        foreach ($this->editable($owner) as $definition) {
            $values = $this->writer->valuesOf($definition->key);
            $default = $values->organization ?? $definition->default;
            $defaultText = null === $default ? (string) $definition->unsetMeans : $this->say($definition, $default);

            $places = [];
            if ($definition->depth->reaches(SettingDepth::Area)) {
                foreach ($areas as $area) {
                    $uuid = (string) $area->getUuidString();
                    $own = $values->areas[$uuid] ?? null;
                    $places[] = new CascadePlace(
                        $uuid,
                        (string) $area->getName(),
                        'area',
                        null !== $own,
                        null === $own ? $defaultText : $this->say($definition, $own),
                        null === $own ? '' : self::figure($definition, $own),
                    );
                }
            }

            $groups[$definition->group][] = new CascadeSetting(
                $definition->key,
                $definition->label,
                $definition->description,
                $defaultText,
                null === $default ? '' : self::figure($definition, $default),
                $definition->unit,
                $definition->min,
                $definition->max,
                0 === $definition->decimals ? '1' : '0.'.str_repeat('0', $definition->decimals - 1).'1',
                $definition->unsetMeans,
                $definition->depth->reaches(SettingDepth::Department),
                $places,
                array_map(static fn (string $choice): CascadeChoice => new CascadeChoice($choice, $definition->labelOf($choice), $definition->meaningOf($choice)), SettingType::Choice === $definition->type ? $definition->choices : []),
                $definition->note,
            );
        }

        return new CascadeTable($organization, $groups);
    }

    /**
     * @param array<mixed> $organization the posted `org` fields, by key
     * @param array<mixed> $areas        the posted `area` fields, by key then area uuid
     *
     * @throws SettingsRefused naming the first value the store will not keep; nothing is kept
     */
    public function save(string $owner, array $organization, array $areas, string $setBy): int
    {
        $known = array_map(static fn (AreaOfInterest $area): string => (string) $area->getUuidString(), $this->areas->findAllOrdered());
        $changes = [];
        foreach ($this->editable($owner) as $definition) {
            $values = $this->writer->valuesOf($definition->key);
            $posted = $organization[$definition->key] ?? null;
            if (\is_string($posted)) {
                $change = $this->organizationChange($definition, $values, trim($posted));
                if (null !== $change) {
                    $changes[] = $change;
                }
            }

            if (!$definition->depth->reaches(SettingDepth::Area)) {
                continue;
            }
            $own = $areas[$definition->key] ?? [];
            $own = \is_array($own) ? $own : [];
            foreach ($known as $uuid) {
                $stored = $values->areas[$uuid] ?? null;
                $field = $own[$uuid] ?? null;
                if (!\is_string($field)) {
                    if (null !== $stored) {
                        $changes[] = new SettingChange($definition->key, SettingDepth::Area, $uuid, null);
                    }
                    continue;
                }
                $value = self::parse($definition, trim($field));
                if (null === $stored || !self::same($value, $stored)) {
                    $changes[] = new SettingChange($definition->key, SettingDepth::Area, $uuid, $value);
                }
            }
        }

        if ([] !== $changes) {
            $this->writer->apply($changes, $setBy);
        }

        return \count($changes);
    }

    private function organizationChange(SettingDefinition $definition, SettingValues $values, string $field): ?SettingChange
    {
        if ('' === $field && null === $definition->default) {
            return null === $values->organization ? null : new SettingChange($definition->key, SettingDepth::Organization, null, null);
        }

        $value = self::parse($definition, $field);
        $current = $values->organization ?? $definition->default;
        if (null !== $current && self::same($value, $current)) {
            return null;
        }

        return new SettingChange($definition->key, SettingDepth::Organization, null, $value);
    }

    /** Whether the owner declares a setting this cascade has a control for. */
    public function hasSettings(string $owner): bool
    {
        return [] !== $this->editable($owner);
    }

    /**
     * @return list<SettingDefinition>
     */
    private function editable(string $owner): array
    {
        return array_values(array_filter($this->catalogue->forOwner($owner), static fn (SettingDefinition $d): bool => SettingType::Toggle !== $d->type));
    }

    /** A field as the store keeps it; anything else passes as typed, for the store to refuse. */
    private static function parse(SettingDefinition $definition, string $field): int|float|string
    {
        if (SettingType::Choice === $definition->type) {
            return $field;
        }

        if (1 === preg_match('/^-?\d+$/', $field)) {
            return (int) $field;
        }

        if ($definition->decimals > 0 && 1 === preg_match('/^-?\d*\.\d+$/', $field)) {
            return (float) $field;
        }

        return $field;
    }

    /** Whether two values are one: 1 and 1.0 are the same figure. */
    private static function same(int|float|bool|string $a, int|float|bool|string $b): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return $a === $b;
    }

    private function say(SettingDefinition $definition, int|float|bool|string $value): string
    {
        if (SettingType::Choice === $definition->type && \is_string($value)) {
            return $definition->labelOf($value);
        }

        return trim(self::figure($definition, $value).' '.($definition->unit ?? ''));
    }

    private static function figure(SettingDefinition $definition, int|float|bool|string $value): string
    {
        if (\is_float($value)) {
            return rtrim(rtrim(number_format($value, $definition->decimals, '.', ''), '0'), '.');
        }

        return \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
