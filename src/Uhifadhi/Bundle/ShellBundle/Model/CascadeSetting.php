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
 * ONE SETTING'S ROWS: its summary, the one control for its default, and its
 * places. A figure's field takes `min`, `max` and `step`; an empty default
 * field means what `unsetMeans` says, and is allowed only where it says so.
 * A choice is one radio per choice, its inputs the stored values; `note` is a
 * line true whichever value is in force, said under the setting's name.
 */
final readonly class CascadeSetting
{
    /**
     * @param string              $defaultText  the default as the summary and a following place print it — "30 min"
     * @param string              $defaultInput the default field's value, "" while unset
     * @param list<CascadePlace>  $places
     * @param list<CascadeChoice> $choices      empty for a figure
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $defaultText,
        public string $defaultInput,
        public ?string $unit,
        public ?int $min,
        public ?int $max,
        public string $step,
        public ?string $unsetMeans,
        public bool $reachesDepartments,
        public array $places,
        public array $choices = [],
        public ?string $note = null,
    ) {
    }

    /** Whether its control is a choice rather than a figure's field. */
    public function isChoice(): bool
    {
        return [] !== $this->choices;
    }

    /** Whether any choice says what it does, so the default's control carries the meaning line. */
    public function saysMeanings(): bool
    {
        foreach ($this->choices as $choice) {
            if (null !== $choice->meaning) {
                return true;
            }
        }

        return false;
    }

    /** What the default's choice does, or null where it says nothing. */
    public function defaultMeaning(): ?string
    {
        foreach ($this->choices as $choice) {
            if ($choice->value === $this->defaultInput) {
                return $choice->meaning;
            }
        }

        return null;
    }

    /** How many of the places set their own value. */
    public function ownCount(): int
    {
        return \count(array_filter($this->places, static fn (CascadePlace $place): bool => $place->own));
    }
}
