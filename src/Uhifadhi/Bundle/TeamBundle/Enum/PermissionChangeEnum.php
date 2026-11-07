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
 * nothing, yet it is a person's standing, so a rank change is a line too
 * (ruled 8 Nov 2026), read beside the seat it is saved with.
 *
 * The value is what the row stores, so a case is renamed only with its rows.
 */
enum PermissionChangeEnum: string
{
    case TierChanged = 'tier-changed';
    case Seated = 'seated';
    case Unseated = 'unseated';
    case Placed = 'placed';
    case RankChanged = 'rank-changed';
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
    case VisibilityChanged = 'visibility-changed';
    case ModuleAttached = 'module-attached';
    case ModuleDetached = 'module-detached';

    /** What the line is about, as the chip in front of it reads. */
    public function kind(): string
    {
        return match ($this) {
            self::TierChanged => 'tier',
            self::Seated, self::Unseated, self::RankChanged => 'position',
            self::Placed => 'placement',
            self::AccountCreated, self::Invited, self::Deactivated, self::Reactivated, self::EmailChanged => 'account',
            self::ResetLinkSent, self::OneTimePasswordIssued => 'sign-in',
            self::GrantsChanged => 'grants',
            self::ExceptionGiven, self::ExceptionTaken => 'exception',
            self::ScopeChanged, self::VisibilityChanged, self::ModuleAttached, self::ModuleDetached => 'department',
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
            self::RankChanged => 'changed the rank of {on}',
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
            self::VisibilityChanged => 'changed who sees {on}',
            self::ModuleAttached => 'attached a module to {on}',
            self::ModuleDetached => 'detached a module from {on}',
        };
    }

    /**
     * What the person did when they did it to themselves, with `{after}`
     * where the new value belongs — "changed their own rank to {after}" — or
     * null for a change nobody makes to themselves or that is not about a
     * person.
     */
    public function selfPhrase(): ?string
    {
        return match ($this) {
            self::TierChanged => 'changed their own tier to {after}',
            self::Seated => 'seated themselves as {after}',
            self::Unseated => 'unseated themselves',
            self::Placed => 'placed themselves',
            self::RankChanged => 'changed their own rank to {after}',
            self::Deactivated => 'deactivated themselves',
            self::Reactivated => 'reactivated themselves',
            self::EmailChanged => 'changed their own email',
            self::ResetLinkSent => 'sent themselves a reset link',
            self::OneTimePasswordIssued => 'issued themselves a one-time password',
            self::AccountCreated, self::Invited, self::GrantsChanged, self::ExceptionGiven, self::ExceptionTaken,
            self::ScopeChanged, self::VisibilityChanged, self::ModuleAttached, self::ModuleDetached => null,
        };
    }
}
