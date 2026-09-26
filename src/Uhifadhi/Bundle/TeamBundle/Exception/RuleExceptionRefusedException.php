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

namespace Uhifadhi\Bundle\TeamBundle\Exception;

/**
 * AN EXCEPTION TO A RULE WAS ASKED FOR THE WRONG WAY.
 *
 * A grant that lifts a rule everybody else is held to — the control room's
 * "Live locations" lifts the rank rule — is given only by a Super Admin, only
 * with a written reason, and only on its own card. Each refusal says which of
 * those was missing, in words an administrator can act on.
 */
final class RuleExceptionRefusedException extends \RuntimeException
{
    public static function notASuperAdmin(string $label): self
    {
        return new self(\sprintf('Refused — only a Super Admin may give or take away “%s”. It is an exception to a rule everybody else is held to.', $label));
    }

    public static function noReason(string $label): self
    {
        return new self(\sprintf('Refused — “%s” needs a written reason: say why this seat must be an exception. The reason is kept with who gave it and when.', $label));
    }

    public static function notAnException(string $pair): self
    {
        return new self(\sprintf('Refused — “%s” is an ordinary grant. Give it in the matrix, not as an exception.', $pair));
    }

    public static function onItsOwnCard(string $label): self
    {
        return new self(\sprintf('Refused — “%s” is an exception to a rule and is given on its own card, by a Super Admin, with a reason. The matrix cannot give it.', $label));
    }

    public static function alreadyHeld(string $label, string $position): self
    {
        return new self(\sprintf('Refused — “%s” already holds “%s”. Take it away first to give it with a new reason.', $position, $label));
    }

    public static function notHeld(string $label, string $position): self
    {
        return new self(\sprintf('Refused — “%s” does not hold “%s”, so there is nothing to take away.', $position, $label));
    }
}
