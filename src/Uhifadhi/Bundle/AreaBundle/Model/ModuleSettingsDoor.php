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
 * THE DOOR INTO A MODULE'S OWN CONFIGURE PAGE: where it is, and what stands
 * behind it — the module's sections, named in the module's own words, so the
 * door's title can say "Types · Stations · Kinds" without the register
 * knowing what any of those are.
 */
final readonly class ModuleSettingsDoor
{
    /** @param list<string> $sections the module's section labels, in the ruled order */
    public function __construct(
        public string $url,
        public array $sections,
    ) {
    }

    public function title(): string
    {
        return 'Its own settings: '.implode(' · ', $this->sections);
    }
}
