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

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Model\PermissionGroup;
use Uhifadhi\Bundle\TeamBundle\Model\PermissionRow;
use Uhifadhi\Bundle\TeamBundle\Model\SectionFact;
use Uhifadhi\Bundle\TeamBundle\Model\TierRow;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * WHAT AUTHORITY EXISTS ON THIS INSTALLATION, AND WHO HOLDS IT.
 *
 * TWO THINGS DECIDE IT AND THERE IS NO THIRD. The TIER — three cases, two of
 * which stand above the matrix — and the GRANTS a position carries, each of
 * them a declared concern crossed with one verb. There is no Role entity,
 * none is proposed, and this service asks for none: it reads the two that
 * already decide and aggregates them.
 *
 * IT WRITES NOTHING. The matrix is edited on Positions; a page that could
 * change a grant from the report of it would be a second write path for the
 * fact one screen already owns.
 *
 * TWO PASSES, NOT ONE PER ROW. Every figure below is counted in one pass over
 * the positions and one over the people, because "how many hold
 * `areas.read`" asked per row is a query per row on the one page that lists
 * every row there is.
 *
 * THE BAND IS THE DECLARER'S. A concern belongs to whoever enforces it, and
 * the catalogue already groups by that, so two packages that both call
 * something "Records" keep their own bands.
 */
final readonly class RolesBoard
{
    /**
     * @param iterable<ModuleProviderInterface> $modules every installed module, for the
     *                                                   names on the bands and for the ones
     *                                                   that declare nothing
     */
    public function __construct(
        private ConcernCatalogue $catalogue,
        private PositionRepository $positions,
        private UserRepository $users,
        private iterable $modules = [],
    ) {
    }

    /**
     * @return array{
     *     facts: list<SectionFact>,
     *     tiers: list<TierRow>,
     *     groups: list<PermissionGroup>,
     *     permissions: int,
     *     core: int,
     *     fromModules: int,
     * }
     */
    public function read(): array
    {
        $grouped = $this->catalogue->grouped();
        $positions = $this->positions->findAllOrdered();
        $people = $this->users->findAllByName();

        $pairs = $core = 0;
        foreach ($grouped as $concerns) {
            foreach ($concerns as $concern) {
                $verbs = \count($concern->verbs());
                $pairs += $verbs;
                if (null === $concern->moduleSlug()) {
                    $core += $verbs;
                }
            }
        }

        return [
            'facts' => $this->facts($grouped, $positions, $people, $core, $pairs),
            'tiers' => self::tiers($people),
            'groups' => $this->groups($grouped, $positions, $people),
            'permissions' => $pairs,
            'core' => $core,
            'fromModules' => $pairs - $core,
        ];
    }

    /**
     * @param array<string, list<ConcernInterface>> $grouped
     * @param list<Position>                        $positions
     * @param list<User>                            $people
     *
     * @return list<SectionFact>
     */
    private function facts(array $grouped, array $positions, array $people, int $core, int $pairs): array
    {
        $declaring = [];
        foreach ($grouped as $concerns) {
            foreach ($concerns as $concern) {
                if (null !== $concern->moduleSlug()) {
                    $declaring[$concern->moduleSlug()] = true;
                }
            }
        }
        $installed = \count($this->moduleNames());

        $byTier = $byGrant = 0;
        foreach ($people as $person) {
            if (!$person->isActive()) {
                continue;
            }
            if ($person->getTeamRole()->canManageContent()) {
                ++$byTier;
                continue;
            }
            if (true === $person->getPosition()?->grantsVerbOn(TeamConcerns::DIRECTORY, Verb::Manage)) {
                ++$byGrant;
            }
        }

        return [
            new SectionFact('Tiers', (string) \count(TeamRoleEnum::cases()), '2 are escape hatches'),
            new SectionFact('Core grants', (string) $core, 'the platform’s own'),
            new SectionFact(
                'Module grants',
                (string) ($pairs - $core),
                \sprintf('from %d of %d modules', \count($declaring), $installed),
            ),
            new SectionFact('Positions', (string) \count($positions), 'each a set of grants'),
            new SectionFact(
                'May administer',
                (string) ($byTier + $byGrant),
                \sprintf('of %d · %d by tier, %d by grant', \count($people), $byTier, $byGrant),
            ),
        ];
    }

    /**
     * THE THREE TIERS, WITH THE PEOPLE IN THEM NAMED WHERE NAMING IS USEFUL.
     *
     * A TIER SMALL ENOUGH TO NAME IS NAMED, because "there is one Super Admin"
     * is a fact somebody must be able to act on — and if that account is lost
     * there is no second owner and no break-glass. A tier holding the rest of
     * the installation is not a list; Staff says so in the way a reader
     * already thinks of it, and any other crowded tier says how many.
     *
     * @param list<User> $people
     *
     * @return list<TierRow>
     */
    private static function tiers(array $people): array
    {
        /** How many names a tier may state before a list stops being a reading. */
        $nameable = 3;

        $rows = [];
        foreach (TeamRoleEnum::cases() as $tier) {
            $names = [];
            foreach ($people as $person) {
                if ($tier === $person->getTeamRole()) {
                    $names[] = $person->getFullName();
                }
            }

            $rows[] = new TierRow(
                tier: $tier,
                people: \count($names),
                who: match (true) {
                    [] === $names => 'nobody',
                    \count($names) <= $nameable => implode(', ', $names),
                    TeamRoleEnum::Staff === $tier => 'everybody else',
                    default => \sprintf('%d people', \count($names)),
                },
                meaning: $tier->description(),
            );
        }

        return $rows;
    }

    /**
     * EVERY GRANT THERE IS, under the band of whoever declared the concern it
     * names: the platform's own bundles first, then each module that declared
     * one, then the installed modules that declare nothing.
     *
     * @param array<string, list<ConcernInterface>> $grouped
     * @param list<Position>                        $positions
     * @param list<User>                            $people
     *
     * @return list<PermissionGroup>
     */
    private function groups(array $grouped, array $positions, array $people): array
    {
        $holders = [];
        foreach ($people as $person) {
            if (!$person->isActive()) {
                continue;
            }
            foreach ($person->getPosition()?->getGrantValues() ?? [] as $value) {
                $holders[$value] = ($holders[$value] ?? 0) + 1;
            }
        }

        $carriers = [];
        foreach ($positions as $position) {
            foreach ($position->getGrantValues() as $value) {
                $carriers[$value] = ($carriers[$value] ?? 0) + 1;
            }
        }

        $moduleNames = $this->moduleNames();
        $groups = [];
        $declaring = [];
        foreach ($grouped as $declarer => $concerns) {
            $rows = [];
            foreach ($concerns as $concern) {
                if (null !== $concern->moduleSlug()) {
                    $declaring[$concern->moduleSlug()] = true;
                }

                foreach ($concern->verbs() as $verb) {
                    $pair = (string) Grant::of($concern->key(), $verb);
                    $rows[] = new PermissionRow(
                        label: $concern->label().' · '.ucfirst($verb->value),
                        value: $pair,
                        description: $concern->description(),
                        positions: $carriers[$pair] ?? 0,
                        people: $holders[$pair] ?? 0,
                    );
                }
            }

            $slug = $concerns[0]->moduleSlug() ?? null;
            $groups[] = new PermissionGroup(
                heading: $declarer,
                source: null === $slug ? 'the platform' : $slug,
                rows: $rows,
            );
        }

        // AN INSTALLED MODULE THAT DECLARES NOTHING IS DRAWN, so its absence
        // cannot be misread as "not installed".
        foreach ($moduleNames as $slug => $name) {
            if (!isset($declaring[$slug])) {
                $groups[] = new PermissionGroup($name, $slug);
            }
        }

        return $groups;
    }

    /**
     * The label a module's band wears — its own name, from its own provider,
     * so the page never invents a word for somebody else's module.
     *
     * @return array<string, string> slug => display name
     */
    private function moduleNames(): array
    {
        $names = [];
        foreach ($this->modules as $module) {
            $names[$module->slug()] = $module->name();
        }

        return $names;
    }
}
