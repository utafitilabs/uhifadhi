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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;

/**
 * ONE CHANGE AS A LINE READS IT — "Naomi Kileo made Thabo Ndlovu Admin",
 * with what it was before and after.
 *
 * A GRANT LIST READS AS WHAT MOVED. A position may hold twenty pairs; the
 * line names the ones added and the ones taken away, not two lists of twenty.
 */
final readonly class ChangeLine
{
    /** The separator a list of grants or modules is stored with. */
    public const string LIST = ', ';

    /**
     * @param list<array{0: 'was'|'now'|'added'|'removed', 1: string}> $diff
     */
    public function __construct(
        public \DateTimeImmutable $when,
        public string $kind,
        public string $by,
        public string $lead,
        public string $on,
        public string $tail,
        public string $subjectKind,
        public ?string $subjectUuid,
        public array $diff,
        public ?string $reason,
    ) {
    }

    public static function of(PermissionChange $change): self
    {
        $action = $change->getAction();
        [$lead, $tail] = explode('{on}', $action->phrase(), 2) + [1 => ''];

        return new self(
            when: $change->getRecordedAt(),
            kind: $action->kind(),
            by: $change->getByName(),
            lead: trim($lead),
            on: $change->getSubjectName(),
            tail: trim(str_replace('{after}', (string) $change->getAfter(), $tail)),
            subjectKind: $change->getSubjectKind(),
            subjectUuid: $change->getSubjectUuid(),
            diff: self::diff($action, $change->getBefore(), $change->getAfter()),
            reason: $change->getReason(),
        );
    }

    /** @return list<array{0: 'was'|'now'|'added'|'removed', 1: string}> */
    private static function diff(PermissionChangeEnum $action, ?string $before, ?string $after): array
    {
        if (\in_array($action, [PermissionChangeEnum::GrantsChanged, PermissionChangeEnum::ModuleAttached, PermissionChangeEnum::ModuleDetached], true)) {
            $was = self::items($before);
            $now = self::items($after);
            $added = array_values(array_diff($now, $was));
            $removed = array_values(array_diff($was, $now));
            sort($added);
            sort($removed);

            return [
                ...array_map(static fn (string $item): array => ['added', $item], $added),
                ...array_map(static fn (string $item): array => ['removed', $item], $removed),
            ];
        }

        return [
            ...(null === $before || '' === $before ? [] : [['was', $before]]),
            ...(null === $after || '' === $after ? [] : [['now', $after]]),
        ];
    }

    /** @return list<string> */
    private static function items(?string $list): array
    {
        return null === $list || '' === $list ? [] : array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $item): bool => '' !== $item));
    }
}
