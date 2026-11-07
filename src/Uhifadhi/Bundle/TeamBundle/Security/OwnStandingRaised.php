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

/**
 * A WRITE THAT RAISES THE ASKER'S OWN STANDING — the rank they hold moved up
 * its scale, a pair added to their own position, an account opened in a
 * position stronger than their own. The subject a pair is asked about when the
 * one asking is the one who would gain (`isGranted('ranks.configure', new
 * OwnStandingRaised())`).
 *
 * BELOW A SUPER ADMIN, NOBODY RAISES THEIR OWN STANDING (ruled 8 Nov 2026):
 * {@see GrantVoter::decide()} refuses it before the tiers are read, so an
 * Admin's tier does not answer yes to it. A Super Admin manages the
 * installation, their own standing included, and is answered as before.
 */
final readonly class OwnStandingRaised
{
}
