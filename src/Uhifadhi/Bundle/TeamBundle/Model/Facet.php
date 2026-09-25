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

namespace Uhifadhi\Bundle\TeamBundle\Model;

/**
 * ONE GROUPED DROPDOWN OF THE PEOPLE REGISTER'S BAR — its query key, the
 * word its menu is headed with, the chip's word when nothing is chosen, the
 * whole set's count, its option groups, and the absence option last ("No
 * rank", "No position") when the facet has one.
 */
final readonly class Facet
{
    /** @param list<FacetGroup> $groups */
    public function __construct(
        public string $key,
        public string $label,
        public string $any,
        public int $total,
        public array $groups,
        public ?FilterOption $absent = null,
        public bool $opensLeft = false,
    ) {
    }

    /** The chosen option's label, for the closed chip; null when nothing is chosen or the value is stale. */
    public function labelOf(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        foreach ($this->groups as $group) {
            foreach ($group->options as $option) {
                if ($option->value === $value) {
                    return $option->label;
                }
            }
        }

        return $this->absent?->value === $value ? $this->absent->label : null;
    }
}
