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

namespace Uhifadhi\Bundle\TeamBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\RankHolding;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\RosterStateEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Model\Page;
use Uhifadhi\Bundle\TeamBundle\Model\RosterQuery;

/**
 * @extends ServiceEntityRepository<User>
 */
/*
 * NOT FINAL, for the reason DepartmentRepository is not: it is a
 * COLLABORATOR now — whether a post stands empty is a count of who holds
 * it — and a collaborator that cannot be doubled forces the unit that
 * depends on it into a database it does not otherwise need. Nothing
 * extends it; the modifier was a default rather than a decision.
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * THE PEOPLE A SURFACE IS ABOUT TO DRAW, in one query — what the person
     * facet seam answers with. Their position and its department are joined
     * eagerly, because the caller asked precisely for those two.
     *
     * @param list<string> $uuids
     *
     * @return list<User>
     */
    public function findByUuids(array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->addSelect('p', 'pl')
            ->leftJoin('u.position', 'p')
            ->leftJoin('u.placement', 'pl')
            ->where('u.uuid IN (:uuids)')
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getResult();

        return $users;
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    public function findOneByRangerCode(string $rangerCode): ?User
    {
        return $this->findOneBy(['rangerCode' => strtolower(trim($rangerCode))]);
    }

    /**
     * The field app's sign-in identifier, resolved the way a ranger might type
     * it: a service number normally, an email address for staff who have no
     * service number. One lookup surface, two honest spellings of "who".
     */
    public function findOneByFieldIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@')
            ? $this->findOneByEmail($identifier)
            : $this->findOneByRangerCode($identifier);
    }

    /**
     * THE /team LIST, whole: one page of people plus enough about the rest to
     * draw the footer.
     *
     * Every criterion comes off the {@see RosterQuery} — the search box, the
     * tier chips, the two selects, the page — so the list's entire state is the
     * URL that asked for it.
     *
     * THE SEARCH IS ILIKE ACROSS FOUR COLUMNS: first name, last name, email and
     * ranger code, which is exactly what the box's placeholder promises. A
     * search that quietly meant less than its placeholder would be worse than
     * one that promised less. LOWER() over both sides rather than a
     * database-specific ILIKE keyword: the bundle does not get to assume which
     * engine an installation runs.
     *
     * THE JOIN TO POSITION IS A LEFT JOIN, and it has to be. A Staff member with
     * no position is the model's zero and belongs in the list; an inner join
     * would silently drop exactly the people the roster most needs to show.
     *
     * PAGING IS DOCTRINE'S OWN PAGINATOR behind {@see Page}. The page number
     * arrived in a URL somebody can type, so it is clamped to at least 1 — past
     * the end is an empty page rather than an exception, because a person
     * guessing at ?page=900 has not caused an error.
     *
     * @return Page<User>
     */
    public function findRoster(RosterQuery $query): Page
    {
        $qb = $this->rosterBuilder($query);

        $page = max(1, $query->page);
        $qb->setFirstResult(($page - 1) * RosterQuery::PER_PAGE)
            ->setMaxResults(RosterQuery::PER_PAGE);

        // fetchJoinCollection: true — the department filter joins a to-MANY
        // (a placement names several departments), so the paginator needs its
        // distinct-identifier pass or a person in two departments is counted
        // twice and the page is short.
        $paginator = new Paginator($qb->getQuery(), null !== $query->department && RosterQuery::NO_DEPARTMENT !== $query->department);

        /** @var list<User> $items */
        $items = array_values(iterator_to_array($paginator));

        return new Page($items, \count($paginator), $page, RosterQuery::PER_PAGE);
    }

    /**
     * EVERY ROW THE ROSTER'S FILTER LEAVES, in the register's order and with
     * no pager — what the export door writes out.
     *
     * @return list<User>
     */
    public function findRosterRows(RosterQuery $query): array
    {
        $qb = $this->rosterBuilder($query);
        $paginator = new Paginator($qb->getQuery(), null !== $query->department && RosterQuery::NO_DEPARTMENT !== $query->department);

        /** @var list<User> $rows */
        $rows = array_values(iterator_to_array($paginator));

        return $rows;
    }

    /** THE ROSTER'S ONE FILTER, shared by the paged register and its export. */
    private function rosterBuilder(RosterQuery $query): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.position', 'p')
            ->addSelect('p')
            // A DEPARTMENT IS A PLACEMENT, so the roster reaches one through
            // the person rather than through their job title.
            ->leftJoin('u.placement', 'pl')
            ->addSelect('pl')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            // The tie-break nobody sees and every pager needs: two people with
            // the same name must not swap places between page 1 and page 2.
            ->addOrderBy('u.id', 'ASC');

        if (null !== $query->q) {
            $qb->andWhere(
                'LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.rangerCode) LIKE :q',
            )->setParameter('q', '%'.strtolower($query->q).'%');
        }

        if (null !== $query->tier) {
            $qb->andWhere('u.teamRole = :tier')->setParameter('tier', $query->tier->value);
        }

        if (RosterQuery::NO_POSITION === $query->position) {
            $qb->andWhere('u.position IS NULL');
        } elseif (null !== $query->position) {
            // An unparseable uuid in the URL matches nobody rather than throwing:
            // a stale bookmark is not a server error.
            $uuid = Uuid::isValid($query->position) ? Uuid::fromString($query->position) : null;
            null !== $uuid
                ? $qb->andWhere('p.uuid = :position')->setParameter('position', $uuid, UuidType::NAME)
                : $qb->andWhere('1 = 0');
        }

        if (RosterQuery::NO_DEPARTMENT === $query->department) {
            // PLACED NOWHERE: no placement, so no department either.
            $qb->andWhere('u.placement IS NULL');
        } elseif (null !== $query->department) {
            $uuid = Uuid::isValid($query->department) ? Uuid::fromString($query->department) : null;
            if (null !== $uuid) {
                // EITHER PLACED ACROSS ALL DEPARTMENTS OR NAMED IN THIS ONE.
                // Somebody placed everywhere is in this department too, and a
                // filter that left them out would disagree with the voter.
                $qb->leftJoin('pl.departments', 'pd')
                    ->andWhere('pl.allDepartments = true OR pd.uuid = :department')
                    ->setParameter('department', $uuid, UuidType::NAME);
            } else {
                $qb->andWhere('1 = 0');
            }
        }

        match ($query->state) {
            RosterStateEnum::Active => $qb->andWhere('u.isActive = true'),
            RosterStateEnum::Deactivated => $qb->andWhere('u.isActive = false'),
            RosterStateEnum::NeverSignedIn => $qb->andWhere('u.isVerified = false'),
            null => null,
        };

        // A SEAM FACET NAMES ITS PEOPLE rather than a column: the station and
        // a module's dropdown answer with identifiers, and the roster's one
        // query narrows to them. A choice that left nobody leaves nobody.
        if (null !== $query->only) {
            [] === $query->only
                ? $qb->andWhere('1 = 0')
                : $qb->andWhere('u.uuid IN (:only)')->setParameter('only', $query->only);
        }

        if (RosterQuery::NO_RANK === $query->rank) {
            $held = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(RankHolding::class, 'hn')
                ->andWhere('hn.person = u')->andWhere('hn.until IS NULL');
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($held->getDQL())));
        } elseif (null !== $query->rank) {
            // THE RANK HELD NOW, never one held before: a promotion moves a
            // person out of the rank they left.
            $uuid = Uuid::isValid($query->rank) ? Uuid::fromString($query->rank) : null;
            if (null !== $uuid) {
                $held = $this->getEntityManager()->createQueryBuilder()
                    ->select('1')->from(RankHolding::class, 'hr')->join('hr.rank', 'rr')
                    ->andWhere('hr.person = u')->andWhere('hr.until IS NULL')->andWhere('rr.uuid = :rank');
                $qb->andWhere($qb->expr()->exists($held->getDQL()))->setParameter('rank', $uuid, UuidType::NAME);
            } else {
                $qb->andWhere('1 = 0');
            }
        }

        return $qb;
    }

    /**
     * The tier chips' counts, keyed by tier value.
     *
     * OVER THE WHOLE ROSTER, never the filtered one. A chip reading "Admin 0"
     * because somebody is currently searching for "grace" is a chip lying about
     * the installation — the counts are what the chips would show you, not what
     * you are looking at.
     *
     * @return array<string, int> every tier present, in enum order; a tier
     *                            nobody holds reads 0 rather than being absent
     */
    public function countByTier(): array
    {
        // The column is enum-typed, so Doctrine hydrates the CASE and not the
        // string — hence ->value on the way into the array.
        /** @var list<array{tier: TeamRoleEnum, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u.teamRole AS tier', 'COUNT(u.id) AS n')
            ->groupBy('u.teamRole')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach (TeamRoleEnum::cases() as $tier) {
            $counts[$tier->value] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row['tier']->value] = (int) $row['n'];
        }

        return $counts;
    }

    /** Everybody, deactivated included — the roster does not hide the people who left. */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Active accounts in any of the given tiers — the administrators-by-tier count. */
    public function countActiveInTiers(TeamRoleEnum ...$tiers): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.isActive = true')
            ->andWhere('u.teamRole IN (:tiers)')
            ->setParameter('tiers', array_map(static fn (TeamRoleEnum $t): string => $t->value, $tiers))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many active people hold one of these positions — the
     * administrators-by-permission count, once the caller has worked out which
     * positions grant it.
     *
     * @param list<Position> $positions
     */
    public function countActiveHoldingAnyPosition(array $positions): int
    {
        if ([] === $positions) {
            return 0;
        }

        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.isActive = true')
            ->andWhere('u.position IN (:positions)')
            ->setParameter('positions', $positions)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * EVERYBODY ACTIVE WHO HOLDS THIS POSITION RIGHT NOW - the list a full
     * position's refusal names, and the reason it is a list rather than a
     * count: "that position is full" is not actionable and "Joseph Mollel
     * holds it" is.
     *
     * DEACTIVATED PEOPLE DO NOT OCCUPY A SEAT. Somebody who left in March is
     * not standing in the post, and counting them would make a vacancy
     * unfillable until an administrator went and deleted a record the model
     * deliberately keeps.
     *
     * @return list<User>
     */
    public function findActiveHolders(Position $position): array
    {
        /** @var list<User> $holders */
        $holders = $this->createQueryBuilder('u')
            ->andWhere('u.position = :position')
            ->andWhere('u.isActive = true')
            ->setParameter('position', $position)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $holders;
    }

    /**
     * The attention pane's first row: ACTIVE accounts that have never signed in.
     *
     * Active only, and that is the rule rather than an optimisation.
     * Deactivating somebody RESOLVES their never-signed-in nag — the decision
     * the row asks for has already been taken about them — and a pane that kept
     * chasing a switched-off account is a pane nobody trusts.
     *
     * @return list<User>
     */
    public function findActiveNeverSignedIn(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.isVerified = false')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * The model's zero: active STAFF with no position, who can sign in and do
     * nothing at all.
     *
     * Staff only. A Super Admin or an Admin with no position holds EVERYTHING by
     * tier, and listing them here would make the pane's most alarming row its
     * least accurate one.
     *
     * @return list<User>
     */
    public function findActiveWithoutPosition(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.position IS NULL')
            ->andWhere('u.teamRole = :staff')
            ->setParameter('staff', TeamRoleEnum::Staff->value)
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /** The one the standing-risk row names, when there is exactly one. */
    public function findFirstActiveSuperAdmin(): ?User
    {
        /** @var User|null $user */
        $user = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.teamRole = :tier')
            ->setParameter('tier', TeamRoleEnum::SuperAdmin->value)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    public function findOneByUuid(Uuid $uuid): ?User
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * How many people could still sign in and administer this installation at
     * the top tier. The number the sole-Super-Admin invariant turns on
     * ({@see \Uhifadhi\Bundle\TeamBundle\Service\SuperAdminInvariant}).
     *
     * ACTIVE ONLY, deliberately: a Super Admin who left in March cannot fix
     * anything, so counting them would let the last usable account be
     * deactivated on the strength of one that is not.
     */
    public function countActiveSuperAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.teamRole = :tier')
            ->andWhere('u.isActive = true')
            ->setParameter('tier', TeamRoleEnum::SuperAdmin->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether this installation has anybody at all — the question the bootstrap
     * command asks to decide whether the account it is about to make is the
     * first, and therefore a Super Admin by default.
     */
    public function isEmpty(): bool
    {
        return 0 === (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everyone who can be named as a patrol team member — the roster the field
     * app caches at sign-in. Ordered by name so the phone's picker is stable.
     *
     * NOT the /team page's list: that one searches, filters and pages, and it
     * is {@see findPage()} over a {@see RosterQuery}.
     *
     * @return list<User>
     */
    public function findAllByName(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
