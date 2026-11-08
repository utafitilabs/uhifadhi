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

/** ONE MODULE'S SETTINGS PAGE, as Settings › Modules opens onto it. */
final readonly class ModuleSettingsPage
{
    public function __construct(
        public string $label,
        public ?string $description,
        public string $url,
    ) {
    }
}
