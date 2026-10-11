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

namespace Uhifadhi\Bundle\TeamBundle\Enum;

/**
 * EVERY KIND OF CHANGE TO WHO MAY DO WHAT (ruled 11 Oct 2026, #75): a tier,
 * a position, a placement, the account itself, sign-in help, a position's
 * grants, an exception, and a department's scope and modules. A rank grants
 * nothing, so a rank change is none of these.
 *
 * The value is what the row stores, so a case is renamed only with its rows.
 */
enum PermissionChangeEnum: string
{
    case TierChanged = 'tier-changed';
    case Seated = 'seated';
    case Unseated = 'unseated';
    case Placed = 'placed';
    case AccountCreated = 'account-created';
    case Invited = 'invited';
    case Deactivated = 'deactivated';
    case Reactivated = 'reactivated';
    case EmailChanged = 'email-changed';
    case ResetLinkSent = 'reset-link-sent';
    case OneTimePasswordIssued = 'one-time-password-issued';
    case GrantsChanged = 'grants-changed';
    case ExceptionGiven = 'exception-given';
    case ExceptionTaken = 'exception-taken';
    case ScopeChanged = 'scope-changed';
    case ModuleAttached = 'module-attached';
    case ModuleDetached = 'module-detached';

    /** What the line is about, as the chip in front of it reads. */
    public function kind(): string
    {
        return match ($this) {
            self::TierChanged => 'tier',
            self::Seated, self::Unseated => 'position',
            self::Placed => 'placement',
            self::AccountCreated, self::Invited, self::Deactivated, self::Reactivated, self::EmailChanged => 'account',
            self::ResetLinkSent, self::OneTimePasswordIssued => 'sign-in',
            self::GrantsChanged => 'grants',
            self::ExceptionGiven, self::ExceptionTaken => 'exception',
            self::ScopeChanged, self::ModuleAttached, self::ModuleDetached => 'department',
        };
    }

    /**
     * What the person did, with `{on}` where the record it was done to goes
     * and `{after}` where the new value belongs in the sentence itself.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::TierChanged => 'made {on} {after}',
            self::Seated => 'seated {on}',
            self::Unseated => 'unseated {on}',
            self::Placed => 'placed {on}',
            self::AccountCreated => 'created an account for {on}',
            self::Invited => 'invited {on}',
            self::Deactivated => 'deactivated {on}',
            self::Reactivated => 'reactivated {on}',
            self::EmailChanged => 'changed the email of {on}',
            self::ResetLinkSent => 'sent {on} a reset link',
            self::OneTimePasswordIssued => 'issued {on} a one-time password',
            self::GrantsChanged => 'changed what {on} grants',
            self::ExceptionGiven => 'gave {on} an exception',
            self::ExceptionTaken => 'took an exception away from {on}',
            self::ScopeChanged => 'changed the scope of {on}',
            self::ModuleAttached => 'attached a module to {on}',
            self::ModuleDetached => 'detached a module from {on}',
        };
    }
}
