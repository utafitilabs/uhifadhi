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
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * HOW OFTEN AN AREA'S HANDSETS REPORT, and when a silent one goes stale — the
 * value in force for the area, from Settings › Core (the organization's, or
 * the area's custom one). One reading for the handset, the presence derivation
 * and any module that counts from it.
 */
final readonly class PingInterval
{
    /**
     * HALF AN HOUR, UNTIL AN ADMIN SAYS OTHERWISE — often enough that a watch
     * shows its people, rarely enough that a phone lasts the shift.
     */
    public const int DEFAULT_MINUTES = 30;

    public function __construct(private SettingsReaderInterface $settings)
    {
    }

    /** The interval in force for this area; anything below a minute reads as the default. */
    public function for(AreaOfInterest $area): int
    {
        $set = $this->settings->value(CoreSettings::PING_INTERVAL, $area->getUuidString());

        return \is_int($set) && $set >= 1 ? $set : self::DEFAULT_MINUTES;
    }

    /** The area's stale-after in minutes, or null: unset keeps the two-interval rule. */
    public function staleAfterFor(AreaOfInterest $area): ?int
    {
        $set = $this->settings->value(CoreSettings::STALE_AFTER, $area->getUuidString());

        return \is_int($set) && $set >= 1 ? $set : null;
    }
}
