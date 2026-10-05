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

namespace Uhifadhi\Bundle\TeamBundle\Security;

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * A PERSON AND THE TIER THEY WOULD BE GIVEN — the subject of
 * `isGranted(MemberVoter::TIER, new TierChange($person, $tier))`, because
 * whether a tier may be set depends on the person and on the tier together.
 */
final readonly class TierChange
{
    public function __construct(
        public User $person,
        public TeamRoleEnum $tier,
    ) {
    }
}
