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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Settings\OrganizationIdentitySourceInterface;

/**
 * WHOSE INSTALLATION THIS IS, on a host that has been told. The contract is an
 * alias rather than a tagged collection, so "nobody has said" is the ABSENCE of
 * the alias, not a null answer — which is why this fixture lives on a kernel of
 * its own ({@see NamedHostKernel}) and the plain {@see HostKernel} carries no
 * identity at all. The two kernels are the two states the top bar draws.
 *
 * Read at call time, like every other fixture source here.
 */
final class FixtureOrganizationIdentitySource implements OrganizationIdentitySourceInterface
{
    public function organizationIdentity(): OrganizationIdentity
    {
        return NamedHostKernel::$organization;
    }
}
