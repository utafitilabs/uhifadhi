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
 * WHOEVER KNOWS WHOSE INSTALLATION THIS IS ANSWERS HERE.
 *
 * AN ALIAS, NOT A TAGGED COLLECTION: an installation belongs to one
 * organization, and two sources answering would be two names on one export.
 *
 *     $services->alias('shell.settings.organization_identity', App\Settings\OurIdentity::class);
 *
 * THE ALIAS IS OPTIONAL, AND ITS ABSENCE IS A HONEST STATE. A fresh
 * installation has been given no name of its own and the screen draws the
 * wordmark it was shipped with, with every other field stating that it is not
 * set — which is the page telling somebody exactly what there is to do.
 */
interface OrganizationIdentitySourceInterface
{
    /** The service id the settings section asks for, where anybody answers it. */
    public const string SERVICE = 'shell.settings.organization_identity';

    public function organizationIdentity(): OrganizationIdentity;
}
