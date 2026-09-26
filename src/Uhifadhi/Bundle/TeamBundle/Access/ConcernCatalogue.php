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

namespace Uhifadhi\Bundle\TeamBundle\Access;

use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;

/**
 * EVERYTHING THERE IS TO HAVE A PERMISSION ABOUT IN THIS INSTALLATION, folded
 * together from whoever declared it.
 *
 * A CONCERN EXISTS ONLY BY DECLARATION, so this maintains no list of its own:
 * it walks the tagged sources and reports what they said. There is no
 * privileged catalogue in the middle of the product, which is what stops a
 * module's power from appearing on a page its owner never granted — or
 * surviving the module being uninstalled.
 *
 * IT READS THE SOURCES LIVE. The tagged iterator is walked on every call
 * rather than folded in the constructor: what is installed is a fact about
 * the running container, not about deploy time, and a module removed from
 * `bundles.php` should stop being answerable on the next request rather than
 * the next deploy.
 *
 * A COLLISION IS REFUSED RATHER THAN MERGED. Two sources declaring one key is
 * an installation that cannot say what the key means, and quietly keeping the
 * first would make which one wins depend on registration order — a difference
 * nobody can see and everybody would eventually depend on. It throws, naming
 * both declarers, on the first call rather than at a later wrong answer.
 */
final readonly class ConcernCatalogue
{
    /**
     * @param iterable<ConcernSourceInterface> $sources every tagged declaration, in registration order
     */
    public function __construct(
        private iterable $sources = [],
    ) {
    }

    /**
     * EVERY CONCERN, GROUPED UNDER WHOEVER DECLARED IT — the shape the
     * positions page draws, one group per module or core bundle, each tagged
     * with the package that gave it to the installation.
     *
     * @return array<string, list<ConcernInterface>> declarer to its concerns, in declaration order
     */
    public function grouped(): array
    {
        $grouped = [];
        $seen = [];

        foreach ($this->sources as $source) {
            $declarer = $source->declaredBy();
            $grouped[$declarer] ??= [];

            foreach ($source->concerns() as $concern) {
                $key = $concern->key();

                if (isset($seen[$key])) {
                    throw new \LogicException(\sprintf('Two packages declare the concern "%s": %s and %s. A concern key is the word a route, a door and a grant all name it by, so an installation cannot hold two meanings for one - rename one of them.', $key, $seen[$key], $declarer));
                }

                $seen[$key] = $declarer;
                $grouped[$declarer][] = $concern;
            }
        }

        return $grouped;
    }

    /**
     * @return list<ConcernInterface> in declaration order, declarer by declarer
     */
    public function all(): array
    {
        $concerns = [];
        foreach ($this->grouped() as $group) {
            $concerns = [...$concerns, ...$group];
        }

        return $concerns;
    }

    public function concern(string $key): ?ConcernInterface
    {
        foreach ($this->all() as $concern) {
            if ($key === $concern->key()) {
                return $concern;
            }
        }

        return null;
    }

    /** Which package declared this concern, for the caption on its group. */
    public function declarerOf(string $key): ?string
    {
        foreach ($this->grouped() as $declarer => $concerns) {
            foreach ($concerns as $concern) {
                if ($key === $concern->key()) {
                    return $declarer;
                }
            }
        }

        return null;
    }

    /**
     * WHETHER THIS PAIR IS ONE ANYBODY DECLARED — the concern exists AND it
     * supports that verb.
     *
     * A PAIR THE MATRIX WOULD NOT DRAW IS NOT A PAIR, which is why the verb is
     * checked and not only the concern: a cell that means nothing must not be
     * grantable through a route that names it anyway.
     */
    public function has(Grant $grant): bool
    {
        return $this->concern($grant->concern)?->supports($grant->verb) ?? false;
    }

    /**
     * WHAT ONE ACCOUNT HOLDS, as the wire spells it — the same answer the
     * GrantVoter gives, minus the area and the department, which a field
     * client learns from its posting rather than from this list.
     *
     * A tier that manages content holds every pair; anybody else holds the
     * grants on their position that this catalogue can still spell (a grant
     * whose module was uninstalled is not offered as if it were live).
     *
     * @return list<string>
     */
    public function heldBy(User $user): array
    {
        if ($user->getTeamRole()->canManageContent()) {
            return $this->pairs();
        }

        $held = [];
        foreach ($user->getPosition()?->getGrantValues() ?? [] as $value) {
            $grant = Grant::tryParse($value);
            if (null !== $grant && $this->has($grant)) {
                $held[] = $value;
            }
        }

        return $held;
    }

    /**
     * EVERY PAIR THIS INSTALLATION OFFERS, as the strings a position stores
     * and a route names. The order is the matrix's: concern by concern, and
     * within a concern the six verbs in their fixed order, so two readings of
     * the catalogue never disagree about it.
     *
     * @return list<string>
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->all() as $concern) {
            foreach (Verb::cases() as $verb) {
                if ($concern->supports($verb)) {
                    $pairs[] = (string) Grant::of($concern->key(), $verb);
                }
            }
        }

        return $pairs;
    }

    /**
     * THE MODULE A CONCERN BELONGS TO, or null for a core bundle's own. It is
     * how the third question - does the placement cover the department - is
     * answered: a department runs a set of modules, so a concern with no
     * module has no department dimension to ask about.
     */
    public function moduleOf(string $key): ?string
    {
        return $this->concern($key)?->moduleSlug();
    }

    /**
     * THE RULE A GRANT ON THIS CONCERN LIFTS, or null for an ordinary
     * concern and for one nothing declares.
     */
    public function lifts(string $key): ?string
    {
        return $this->concern($key)?->lifts();
    }

    /**
     * EVERY PAIR THAT IS AN EXCEPTION TO A RULE, in the matrix's order. These
     * are declared like any other and never drawn in the matrix: a Super
     * Admin gives them on their own card, with a written reason.
     *
     * @return list<string>
     */
    public function exceptionPairs(): array
    {
        return array_values(array_filter(
            $this->pairs(),
            fn (string $pair): bool => null !== $this->lifts(Grant::parse($pair)->concern),
        ));
    }

    /** Whether the installation declares a concern that is a fact about a person or a case. */
    public function isSensitive(string $key): bool
    {
        return $this->concern($key)?->isSensitive() ?? false;
    }
}
