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

namespace Uhifadhi\Bundle\TeamBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\ChangeLine;

/**
 * WRITES THE LINE A CHANGE TO WHO MAY DO WHAT LEAVES (ruled 11 Oct 2026, #75).
 *
 * IT PERSISTS AND NEVER FLUSHES. The service making the change flushes, so the
 * line and the change are written together or not at all.
 *
 * WHO MADE IT is the person the caller names, else the person signed in —
 * behind a switch, the Super Admin who switched, since the change was theirs —
 * else nobody, which is said: "the system", for the console and an import.
 *
 * A VALUE IS WRITTEN AS IT READS NOW: a tier's label, a position's name, a
 * placement's ground and departments, a grant list sorted. A later rename
 * leaves the line reading what was true.
 */
final readonly class PermissionChangeLog
{
    public const string SYSTEM = 'the system';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?TokenStorageInterface $tokens = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function record(
        PermissionChangeEnum $action,
        User|Position|Department $subject,
        ?string $before,
        ?string $after,
        ?string $reason = null,
        ?User $by = null,
    ): void {
        $by ??= $this->signedIn();

        $this->entityManager->persist(new PermissionChange(
            recordedAt: $this->clock?->now() ?? new \DateTimeImmutable(),
            byId: $by?->getId(),
            byName: null === $by ? self::SYSTEM : self::nameOf($by),
            action: $action,
            subjectKind: match (true) {
                $subject instanceof User => PermissionChange::PERSON,
                $subject instanceof Position => PermissionChange::POSITION,
                $subject instanceof Department => PermissionChange::DEPARTMENT,
            },
            subjectId: $subject->getId(),
            subjectUuid: $subject->getUuidString(),
            subjectName: $subject instanceof User ? self::nameOf($subject) : (string) $subject->getName(),
            before: $before,
            after: $after,
            reason: $reason,
        ));
    }

    /** "Kilimani, Tambarare · Operations · supports Ecology", or "placed nowhere". */
    public static function placementOf(?Placement $placement): string
    {
        if (null === $placement) {
            return 'placed nowhere';
        }

        $ground = $placement->isWholeOrganization()
            ? 'the whole organization'
            : implode(', ', array_map(static fn ($area): string => (string) $area->getName(), $placement->getAreas() ?? []));
        $supports = array_map(static fn (Department $d): string => (string) $d->getName(), $placement->getSupports());
        sort($supports);

        return implode(' · ', array_filter([
            '' === $ground ? 'nowhere' : $ground,
            $placement->getDepartment()?->getName(),
            [] === $supports ? null : 'supports '.implode(', ', $supports),
        ]));
    }

    /** "the whole organization", or "Kilimani only". */
    public static function scopeOf(Department $department): string
    {
        $area = $department->getArea();

        return null === $area ? 'the whole organization' : (string) $area->getName().' only';
    }

    /**
     * A set written as one sorted line, or null for none.
     *
     * @param list<string> $items
     */
    public static function listOf(array $items): ?string
    {
        $items = array_values(array_unique($items));
        sort($items);

        return [] === $items ? null : implode(ChangeLine::LIST, $items);
    }

    private function signedIn(): ?User
    {
        $token = $this->tokens?->getToken();
        if ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
