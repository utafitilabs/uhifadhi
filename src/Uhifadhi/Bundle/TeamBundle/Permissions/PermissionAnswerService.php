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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\WholeOrganization;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * THE ANSWERS A RUN FILES, asked of the real rules.
 *
 * FOR A PAIR, every active Staff member is asked every pair the catalogue
 * offers in every place — the whole organization and each area — through
 * isGrantedForUser(), the same decision manager the routes ask. The tiers
 * are not asked: they are above the matrix, and the page says so in one line
 * from the run's counts.
 *
 * ONCE PER SHAPE. People who hold the same position and stand in the same
 * placement — the same ground, department and supported departments — are
 * the same question to the GrantVoter, so one of them is asked and the
 * answer is filed for all. `askEveryone` asks each person instead, which is
 * how the suite proves the shortcut never changes a cell.
 *
 * FOR A POWER, {@see PermissionEvaluator::ledger()} is asked as it always
 * was — one holder speaking for each position, then an Admin and a Super
 * Admin — and each cell is filed with the people it speaks for.
 *
 * @see https://symfony.com/doc/current/security.html#securing-other-services — "you can use the isGrantedForUser() method to explicitly set the target user"
 */
final readonly class PermissionAnswerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private ConcernCatalogue $catalogue,
        private UserAuthorizationCheckerInterface $checker,
        private PermissionEvaluator $evaluator,
    ) {
    }

    /**
     * THE PLACES A RUN ASKS ABOUT, in the order the page draws them: the
     * whole organization, then each area by name.
     *
     * @return list<array{kind: string, id: int|null, name: string}>
     */
    public function places(): array
    {
        $places = [['kind' => PlaceKindEnum::Organization->value, 'id' => null, 'name' => 'the whole organization']];
        foreach ($this->areas() as $area) {
            $places[] = ['kind' => PlaceKindEnum::Area->value, 'id' => $area->getId(), 'name' => (string) $area->getName()];
        }

        return $places;
    }

    /** @return list<string> */
    public function pairs(): array
    {
        return $this->catalogue->pairs();
    }

    /**
     * @return array{answers: list<PermissionAnswer>, questions: int, shapes: int, people: int, staff: int, superAdmins: int, admins: int}
     */
    public function compute(PermissionRun $run, bool $askEveryone = false): array
    {
        $people = array_values(array_filter($this->users->findAllByName(), static fn (User $u): bool => $u->isActive()));
        $staff = array_values(array_filter($people, static fn (User $u): bool => TeamRoleEnum::Staff === $u->getTeamRole()));

        $shapes = [];
        foreach ($staff as $person) {
            $shapes[$askEveryone ? 'person:'.$person->getId() : self::shapeOf($person)][] = $person;
        }

        $subjects = $this->subjects($run);
        $answers = [];
        $questions = 0;
        foreach ($run->getPairs() as $pair) {
            foreach ($subjects as [$kind, $placeId, $subject]) {
                $cells = [];
                foreach ($shapes as $holders) {
                    ++$questions;
                    if (!$this->checker->isGrantedForUser($holders[0], $pair, $subject)) {
                        continue;
                    }
                    $position = $holders[0]->getPosition()?->getId();
                    foreach ($holders as $holder) {
                        $cells[$position ?? 0][] = (int) $holder->getId();
                    }
                }
                foreach ($cells as $position => $ids) {
                    sort($ids);
                    $answers[] = PermissionAnswer::forPair($run, $pair, $kind, $placeId, 0 === $position ? null : $position, $ids);
                }
            }
        }

        foreach ($this->powerAnswers($run, $people) as $answer) {
            $answers[] = $answer;
        }

        return [
            'answers' => $answers,
            'questions' => $questions,
            'shapes' => \count($shapes),
            'people' => \count($people),
            'staff' => \count($staff),
            'superAdmins' => \count(array_filter($people, static fn (User $u): bool => TeamRoleEnum::SuperAdmin === $u->getTeamRole())),
            'admins' => \count(array_filter($people, static fn (User $u): bool => TeamRoleEnum::Admin === $u->getTeamRole())),
        ];
    }

    /**
     * @param list<User> $people every active person
     *
     * @return list<PermissionAnswer>
     */
    private function powerAnswers(PermissionRun $run, array $people): array
    {
        $answers = [];
        foreach ($this->evaluator->ledger() as $cell) {
            $actor = $cell->actor;
            $tier = $actor->getTeamRole();
            $staff = TeamRoleEnum::Staff === $tier;
            $position = $staff ? $actor->getPosition()?->getId() : null;
            $speaksFor = array_values(array_filter($people, static fn (User $u): bool => $staff
                ? TeamRoleEnum::Staff === $u->getTeamRole() && null !== $position && $u->getPosition()?->getId() === $position
                : $tier === $u->getTeamRole()));
            $allowed = CellAnswer::Allowed === $cell->answer;

            $answers[] = PermissionAnswer::forPower(
                $run,
                $cell->power->key,
                $cell->kind->value,
                $position,
                $staff ? null : $tier->value,
                $cell->answer->value,
                $cell->refusedBy,
                $allowed ? array_map(static fn (User $u): int => (int) $u->getId(), $speaksFor) : [],
            );
        }

        return $answers;
    }

    /**
     * WHAT THE GRANTVOTER READS OF A PERSON, as one key: their position, and
     * their placement's ground, department and supported departments.
     */
    private static function shapeOf(User $person): string
    {
        $placement = $person->getPlacement();

        return implode('|', [
            $person->getPosition()?->getId() ?? '-',
            null === $placement ? 'unplaced' : self::placementShape($placement),
        ]);
    }

    private static function placementShape(Placement $placement): string
    {
        $areas = array_map(static fn (AreaInterface $a): string => $a->getUuidString() ?? (string) $a->getId(), $placement->getAreas() ?? []);
        sort($areas);
        $supports = array_map(static fn (Department $d): int => (int) $d->getId(), $placement->getSupports());
        sort($supports);

        return implode('/', [
            $placement->isWholeOrganization() ? 'org' : implode(',', $areas),
            $placement->getDepartment()?->getId() ?? '-',
            implode(',', $supports),
        ]);
    }

    /**
     * @return list<array{PlaceKindEnum, int|null, AreaInterface|WholeOrganization}>
     */
    private function subjects(PermissionRun $run): array
    {
        $areas = [];
        foreach ($this->areas() as $area) {
            $id = $area->getId();
            if (null !== $id) {
                $areas[$id] = $area;
            }
        }

        $subjects = [];
        foreach ($run->getPlaces() as $place) {
            if (PlaceKindEnum::Organization->value === $place['kind']) {
                $subjects[] = [PlaceKindEnum::Organization, null, new WholeOrganization()];
            } elseif (null !== $place['id'] && isset($areas[$place['id']])) {
                $subjects[] = [PlaceKindEnum::Area, $place['id'], $areas[$place['id']]];
            }
        }

        return $subjects;
    }

    /**
     * EVERY AREA OF THE INSTALLATION, through the association the placement
     * already declares — this bundle never names the class that holds them.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Placement::class)->getAssociationTargetClass('areas');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }
}
