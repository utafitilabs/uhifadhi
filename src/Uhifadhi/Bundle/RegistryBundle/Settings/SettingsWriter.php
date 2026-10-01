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

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\RegistryBundle\Repository\SettingValueRepository;
use Uhifadhi\Contracts\Settings\SettingDepth;

/**
 * THE SAVE BAR'S SAVE. A reviewed batch is checked whole — every change against
 * its definition, its depth and its place — and then kept whole, in one
 * transaction; one bad change refuses the batch and keeps nothing. Who may call
 * it (Super Admins and Admins) is the caller's gate, not this class's.
 */
final readonly class SettingsWriter
{
    public function __construct(
        private SettingsCatalogue $catalogue,
        private SettingValueRepository $values,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private SettingsResolver $resolver,
    ) {
    }

    /**
     * @param list<SettingChange> $changes
     *
     * @throws SettingsRefused naming the first change it will not keep
     */
    public function apply(array $changes, string $setBy): void
    {
        foreach ($changes as $change) {
            $this->check($change);
        }

        $now = $this->clock->now();
        $this->em->wrapInTransaction(function () use ($changes, $setBy, $now): void {
            foreach ($changes as $change) {
                $row = $this->values->at($change->key, $change->level, $change->placeUuid);
                if ($change->isReset()) {
                    if (null !== $row) {
                        $this->em->remove($row);
                    }
                    continue;
                }
                \assert(null !== $change->value);
                if (null === $row) {
                    $this->em->persist(new SettingValue($change->key, $change->level, $change->placeUuid, $change->value, $setBy, $now));
                } else {
                    $row->change($change->value, $setBy, $now);
                }
            }
            $this->em->flush();
        });

        // What the reader held is stale now.
        $this->resolver->reset();
    }

    /** What is set for one setting, for Settings to draw. */
    public function valuesOf(string $key): SettingValues
    {
        $this->catalogue->get($key);
        $organization = null;
        $areas = [];
        $departments = [];
        foreach ($this->values->forKey($key) as $row) {
            match ($row->getLevel()) {
                SettingDepth::Organization => $organization = $row->getValue(),
                SettingDepth::Area => $areas[(string) $row->getPlaceUuid()] = $row->getValue(),
                SettingDepth::Department => $departments[(string) $row->getPlaceUuid()] = $row->getValue(),
            };
        }

        return new SettingValues($organization, $areas, $departments);
    }

    private function check(SettingChange $change): void
    {
        if (!$this->catalogue->has($change->key)) {
            throw new SettingsRefused(\sprintf('No setting is called "%s".', $change->key));
        }
        $definition = $this->catalogue->get($change->key);

        if (!$definition->depth->reaches($change->level)) {
            throw new SettingsRefused(\sprintf('"%s" cannot be customised for %s.', $definition->label, 'department' === $change->level->value ? 'a department' : 'an area'));
        }

        if ((SettingDepth::Organization === $change->level) !== (null === $change->placeUuid)) {
            throw new SettingsRefused(\sprintf('"%s": the organization’s value names no place, and an area’s or a department’s names its own.', $definition->label));
        }

        if (!$change->isReset() && !$definition->accepts($change->value)) {
            throw new SettingsRefused(\sprintf('"%s" does not accept that value%s.', $definition->label, null === $definition->limits() ? '' : ' ('.$definition->limits().')'));
        }
    }
}
