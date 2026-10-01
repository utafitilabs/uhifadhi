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

namespace Uhifadhi\Contracts\Settings;

/**
 * A SETTING, AS ITS OWNER DECLARES IT. The core or a module says what can be
 * set ("roster.late_threshold"), what it means, what it accepts, its default
 * and how far down it may be customised. Settings › Core and Settings ›
 * Modules › <module> draw a row from it; the store checks every value against
 * it before keeping it.
 *
 * The key is "<owner>.<name>" in lower case, the owner being "core" or the
 * module's slug — so two owners can never declare the same key.
 */
final readonly class SettingDefinition
{
    /**
     * @param list<string> $choices the accepted values of a Choice setting, in the order shown
     */
    public function __construct(
        public string $key,
        public string $owner,
        public string $group,
        public string $label,
        public string $description,
        public SettingType $type,
        public int|bool|string $default,
        public SettingDepth $depth,
        public ?string $unit = null,
        public ?int $min = null,
        public ?int $max = null,
        public array $choices = [],
        public int $position = 0,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A setting key is "<owner>.<name>" in lower case; "%s" is not.', $key));
        }

        if (!str_starts_with($key, $owner.'.')) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" must start with its owner, "%s.".', $key, $owner));
        }

        if ('' === trim($label) || '' === trim($description) || '' === trim($group)) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" needs a label, a description and a group: its row would say nothing.', $key));
        }

        match ($type) {
            SettingType::Number => $this->checkNumber(),
            SettingType::Toggle => $this->checkToggle(),
            SettingType::Choice => $this->checkChoice(),
        };
    }

    /** Whether the store may keep this value for this setting. */
    public function accepts(mixed $value): bool
    {
        return match ($this->type) {
            SettingType::Number => \is_int($value)
                && (null === $this->min || $value >= $this->min)
                && (null === $this->max || $value <= $this->max),
            SettingType::Toggle => \is_bool($value),
            SettingType::Choice => \is_string($value) && \in_array($value, $this->choices, true),
        };
    }

    /** What the field accepts, as the Configure page prints it — "1–120 min" — or null for a switch. */
    public function limits(): ?string
    {
        return match ($this->type) {
            SettingType::Number => null === $this->min && null === $this->max ? null
                : trim(\sprintf('%s–%s %s', $this->min ?? '', $this->max ?? '', $this->unit ?? '')),
            SettingType::Toggle => null,
            SettingType::Choice => implode(' · ', $this->choices),
        };
    }

    private function checkNumber(): void
    {
        if (null !== $this->min && null !== $this->max && $this->min > $this->max) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" accepts nothing: its minimum is above its maximum.', $this->key));
        }

        if (!$this->accepts($this->default)) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" must default to a whole number within its limits.', $this->key));
        }
    }

    private function checkToggle(): void
    {
        if (null !== $this->unit || null !== $this->min || null !== $this->max || [] !== $this->choices) {
            throw new \InvalidArgumentException(\sprintf('The switch "%s" has no unit, limits or choices.', $this->key));
        }

        if (!\is_bool($this->default)) {
            throw new \InvalidArgumentException(\sprintf('The switch "%s" must default to on or off.', $this->key));
        }
    }

    private function checkChoice(): void
    {
        if ([] === $this->choices) {
            throw new \InvalidArgumentException(\sprintf('The choice "%s" needs its choices.', $this->key));
        }

        if (!$this->accepts($this->default)) {
            throw new \InvalidArgumentException(\sprintf('The choice "%s" must default to one of its choices.', $this->key));
        }
    }
}
