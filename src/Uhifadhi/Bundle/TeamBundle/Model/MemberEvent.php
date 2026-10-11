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

namespace Uhifadhi\Bundle\TeamBundle\Model;

use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;

/**
 * ONE LINE OF A PERSON'S HISTORY — what happened, what it says under it, and
 * when.
 *
 * MOST ARE DERIVED FROM A STORED FACT: when somebody was invited, when their
 * account was made, when a posting began and when they were deactivated. The
 * changes to who may do what — a tier, a position, a placement, a grant —
 * come from the permission history ({@see PermissionChange}), which is where
 * their date is kept.
 */
final readonly class MemberEvent
{
    public function __construct(
        public string $title,
        public string $note,
        public \DateTimeImmutable $when,
    ) {
    }

    /** A line of the permission history, as a record's history card reads it. */
    public static function fromChange(PermissionChange $change): self
    {
        $before = (string) $change->getBefore();
        $after = (string) $change->getAfter();
        $line = ChangeLine::of($change);

        [$title, $detail] = match ($change->getAction()) {
            PermissionChangeEnum::TierChanged => ['Made '.$after, 'was '.$before],
            PermissionChangeEnum::Seated => ['Seated as '.$after, '' === $before ? '' : 'was '.$before],
            PermissionChangeEnum::Unseated => ['Unseated from '.$before, ''],
            PermissionChangeEnum::Placed => ['Placed: '.$after, 'was '.$before],
            PermissionChangeEnum::GrantsChanged => ['Grants changed', implode(', ', array_map(
                static fn (array $part): string => ('added' === $part[0] ? '+ ' : '− ').$part[1],
                $line->diff,
            ))],
            PermissionChangeEnum::ExceptionGiven => ['Exception given: '.$after, ''],
            PermissionChangeEnum::ExceptionTaken => ['Exception taken away: '.$before, ''],
            // An address is a personal detail: the card says it changed, not to what.
            PermissionChangeEnum::EmailChanged => ['Email changed', ''],
            PermissionChangeEnum::Reactivated => ['Reactivated', ''],
            PermissionChangeEnum::ResetLinkSent => ['Reset link sent', ''],
            default => [ucfirst(trim($line->lead.' '.$line->on.' '.$line->tail)), ''],
        };

        return new self($title, implode(' · ', array_filter([$detail, 'by '.self::byLine($change->getByName())])), $change->getRecordedAt());
    }

    /** "Naomi Kileo" as "N. Kileo"; "the system" as it is. */
    private static function byLine(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (PermissionChangeLog::SYSTEM === $name || \count($parts) < 2) {
            return $name;
        }

        return mb_substr($parts[0], 0, 1).'. '.$parts[\count($parts) - 1];
    }
}
