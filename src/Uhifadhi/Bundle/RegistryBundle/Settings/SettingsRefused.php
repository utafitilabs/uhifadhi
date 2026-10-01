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

/**
 * A SAVE THE STORE WILL NOT KEEP, saying which change and why — the Configure
 * page shows the message on the bar, and nothing of the batch was kept.
 */
final class SettingsRefused extends \RuntimeException
{
}
