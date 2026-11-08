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

namespace Uhifadhi\Bundle\ShellBundle\Model;

/**
 * A SETTINGS CASCADE AS SETTINGS DRAWS IT: one owner's settings in their
 * groups, each with the default for every area and the areas below it. Whoever
 * owns the places builds it; the shell's `settings/_cascade` partial draws it,
 * read-only or as the Configure page's form.
 */
final readonly class CascadeTable
{
    /**
     * @param string                              $organization whose default it is, as the default row names it
     * @param array<string, list<CascadeSetting>> $groups       the settings by group, in the owner's order
     */
    public function __construct(
        public string $organization,
        public array $groups,
    ) {
    }

    /** How many places set their own value of at least one setting. */
    public function placesWithTheirOwn(): int
    {
        $own = [];
        foreach ($this->groups as $settings) {
            foreach ($settings as $setting) {
                foreach ($setting->places as $place) {
                    if ($place->own) {
                        $own[$place->uuid] = true;
                    }
                }
            }
        }

        return \count($own);
    }
}
