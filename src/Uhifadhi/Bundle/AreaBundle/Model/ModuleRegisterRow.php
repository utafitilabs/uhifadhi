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

namespace Uhifadhi\Bundle\AreaBundle\Model;

/**
 * ONE ROW OF THE MODULES REGISTER — a catalogued module as this area holds it.
 *
 * FIVE FACTS, ONE PER COLUMN, and no picture: what the module is, whether it
 * runs here, where it stands in the order, whether it has settings of its own
 * and where they are. The template decides what a fact looks like.
 *
 * A PARKED ROW HAS NO POSITION. The order is the order of what runs, so a
 * module that is switched off stands in none, and null says so rather than a
 * number nobody assigned. A pinned module runs first and is not reordered or
 * parked, so the template draws it without a grip and without a switch.
 */
final readonly class ModuleRegisterRow
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public bool $running,
        public bool $pinned,
        /** 1-based among the running rows; null while parked. */
        public ?int $position,
        /** The module's own configure page, or null for a module with none. */
        public ?ModuleSettingsDoor $settings,
    ) {
    }
}
