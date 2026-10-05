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

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * WHAT THE SIGNED-IN ADMINISTRATOR'S REACH IS — the read side of area-scoped
 * team administration.
 *
 * The voter answers "may this person do X here?" for a single pair. This
 * answers the coarser structural question the department writes need: is the
 * administrator UNBOUNDED (a tier, or somebody placed across the whole
 * organization — able to mint org departments, change scope, touch any area),
 * or confined to the areas they were placed at?
 *
 * The ruling it enforces: an area-X administrator MAY create, rename and
 * deactivate area-level departments in X; may NOT create org-level departments,
 * change any department's scope, or touch org-level or other areas' departments.
 * Any of the forbidden acts widens power past the admin's own boundary — minting
 * an org department, promoting to org, reaching another area — which is
 * escalation. This service is where "past their boundary" is computed; the
 * controller is where it is refused.
 *
 * IT READS THE PLACEMENT, WHICH IS WHERE REACH NOW LIVES. It used to derive
 * the boundary from `actor.position.department.area`, a chain that made
 * somebody's reach a property of their job title; the ruled model records it
 * against the person. Unplaced is not unbounded — it reaches nothing, because
 * the model fails closed.
 */
final readonly class AreaAuthority
{
    public function __construct(
        private TokenStorageInterface $tokens,
    ) {
    }

    public function actor(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    /** The signed-in account's reach. */
    public function reach(): Reach
    {
        return new Reach($this->actor());
    }

    /** @see Reach::isUnbounded() */
    public function isUnbounded(): bool
    {
        return $this->reach()->isUnbounded();
    }

    /**
     * @see Reach::authorityAreas()
     *
     * @return list<AreaInterface>|null
     */
    public function authorityAreas(): ?array
    {
        return $this->reach()->authorityAreas();
    }

    /** @see Reach::covers() */
    public function covers(?AreaInterface $area): bool
    {
        return $this->reach()->covers($area);
    }

    /** @see Reach::reachesDepartment() */
    public function reachesDepartment(?Department $department): bool
    {
        return $this->reach()->reachesDepartment($department);
    }

    /** @see Reach::reachesPerson() */
    public function reachesPerson(User $person): bool
    {
        return $this->reach()->reachesPerson($person);
    }

    /**
     * @see Reach::grantableGrants()
     *
     * @return list<string>|null
     */
    public function grantableGrants(): ?array
    {
        return $this->reach()->grantableGrants();
    }

    /** @see Reach::mayGrantPair() */
    public function mayGrantPair(string $pair): bool
    {
        return $this->reach()->mayGrantPair($pair);
    }
}
