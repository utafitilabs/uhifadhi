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

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\RosterStateEnum;
use Uhifadhi\Bundle\TeamBundle\Model\Facet;
use Uhifadhi\Bundle\TeamBundle\Model\FacetGroup;
use Uhifadhi\Bundle\TeamBundle\Model\FilterOption;
use Uhifadhi\Bundle\TeamBundle\Model\RosterQuery;

/**
 * THE PEOPLE REGISTER'S GROUPED DROPDOWNS — position, department, rank and
 * account, each with its options and their counts.
 *
 * THE COUNTS ARE OF THE WHOLE ROSTER, never the filtered one: a count that
 * moved as you filtered could not tell you what picking an option would do.
 * A nought is drawn, so an option that emptied reads apart from one that
 * never existed.
 *
 * THE RANK FACET GROUPS BY SCALE ONLY WHEN THERE ARE SEVERAL. With one scale
 * nothing on the page says "scale"; with several, each run of ranks sits
 * under its scale's name, because two scales do not compare.
 */
final readonly class RosterFacets
{
    /**
     * @param list<User>     $everybody
     * @param list<Position> $positions
     */
    public function position(array $everybody, array $positions): Facet
    {
        $counts = [];
        $none = 0;
        foreach ($everybody as $person) {
            $id = $person->getPosition()?->getId();
            null === $id ? ++$none : $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        $options = [];
        foreach ($positions as $position) {
            $options[] = new FilterOption((string) $position->getUuidString(), (string) $position->getName(), $counts[(int) $position->getId()] ?? 0);
        }

        return new Facet('position', 'position', 'any position', \count($everybody), [new FacetGroup(null, $options)], new FilterOption(RosterQuery::NO_POSITION, 'No position', $none));
    }

    /**
     * @param list<User>       $everybody
     * @param list<Department> $departments
     */
    public function department(array $everybody, array $departments): Facet
    {
        $options = [];
        foreach ($departments as $department) {
            $count = 0;
            foreach ($everybody as $person) {
                if (null !== $person->getPlacement() && $person->getPlacement()->coversDepartment($department)) {
                    ++$count;
                }
            }
            $options[] = new FilterOption((string) $department->getUuidString(), (string) $department->getName(), $count);
        }

        $nowhere = \count(array_filter($everybody, static fn (User $person): bool => null === $person->getPlacement()));

        return new Facet('department', 'department', 'any department', \count($everybody), [new FacetGroup(null, $options)], new FilterOption(RosterQuery::NO_DEPARTMENT, 'No department', $nowhere));
    }

    /**
     * @param list<User>      $everybody
     * @param list<RankScale> $scales
     * @param list<Rank>      $ranks         the ranks in use, scale by scale in seniority order
     * @param array<int, int> $holdersByRank ranks held now, keyed by the rank's id
     */
    public function rank(array $everybody, array $scales, array $ranks, array $holdersByRank): Facet
    {
        $several = \count($scales) > 1;
        $groups = [];
        foreach ($scales as $scale) {
            $options = [];
            foreach ($ranks as $rank) {
                if ($rank->getScale() === $scale) {
                    $options[] = new FilterOption((string) $rank->getUuidString(), $rank->getShortCode().' · '.$rank->getName(), $holdersByRank[(int) $rank->getId()] ?? 0);
                }
            }
            if ([] !== $options) {
                $groups[] = new FacetGroup($several ? $scale->getName() : null, $options);
            }
        }

        $held = array_sum($holdersByRank);

        return new Facet('rank', 'rank', 'any rank', \count($everybody), $groups, new FilterOption(RosterQuery::NO_RANK, 'No rank', max(0, \count($everybody) - $held)), opensLeft: true);
    }

    /** @param list<User> $everybody */
    public function account(array $everybody): Facet
    {
        $options = [];
        foreach (RosterStateEnum::cases() as $state) {
            $count = \count(array_filter($everybody, static fn (User $person): bool => match ($state) {
                RosterStateEnum::Active => $person->isActive(),
                RosterStateEnum::Deactivated => !$person->isActive(),
                RosterStateEnum::NeverSignedIn => !$person->isVerified(),
            }));
            $options[] = new FilterOption($state->value, $state->label(), $count);
        }

        return new Facet('state', 'account', 'any account', \count($everybody), [new FacetGroup(null, $options)], opensLeft: true);
    }
}
