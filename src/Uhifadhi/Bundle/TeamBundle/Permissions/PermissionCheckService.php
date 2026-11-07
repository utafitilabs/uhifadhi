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
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\GrantQuestionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\StepOutcomeEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\GrantVoter;
use Uhifadhi\Bundle\TeamBundle\Service\PermissionChangeLog;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Access\WholeOrganization;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * CAN THIS PERSON DO THIS, HERE — AND WHY? Asked live, of the real rules,
 * for one person.
 *
 * THE WALK IS THE VOTER'S OWN RECORD. {@see GrantVoter::decide()} returns the
 * questions it asked in order; this prints one line per step, in words, each
 * with the door to the fact an administrator would change. The verdict is
 * the decision manager's: where another voter decided otherwise, a `rule`
 * line says so with its reasons.
 *
 * THE COVERAGE LINE is read from the latest run, not asked.
 *
 * @phpstan-type Door array{kind: string, id: int|null, uuid: string|null, name: string}
 * @phpstan-type Line array{q: string, status: string, text: string, door: Door|null}
 * @phpstan-type Coverage array{asOf: ?\DateTimeImmutable, total: int, byPosition: list<array{position: string, count: int}>}
 * @phpstan-type PairCheck array{allowed: bool, question: array{kind: string, key: string, label: string}, place: array{key: string, name: string}|null, lines: list<Line>, coverage: Coverage|null}
 * @phpstan-type Exception array{state: 'in-force'|'not-in-force'|'none', pair: string|null, by: string|null, at: \DateTimeImmutable|null, reason: string|null}
 * @phpstan-type Everything array{pair: string, concern: string, asked: bool, allowed: bool, step: string, line: string, held: bool, group: string}
 * @phpstan-type Lens array{
 *     person: string,
 *     place: array{key: string, name: string},
 *     placeIsOwn: bool,
 *     tierHoldsAll: bool,
 *     tierLabel: string,
 *     cover: array{covers: bool, how: 'organization'|'areas'|'nothing'|'not', areas: list<string>},
 *     department: array{module: string|null, served: list<string>, running: list<string>, hit: string|null}|null,
 *     exception: array{state: 'in-force'|'not-in-force'|'none', pair: string|null, by: string|null, at: \DateTimeImmutable|null, reason: string|null, appliesHere: bool},
 *     everything: list<Everything>,
 *     catalogueCount: int,
 *     allowedHere: int,
 * }
 * @phpstan-type PowerCheck array{allowed: bool, verdict: string, question: array{kind: string, key: string, label: string}, target: array{kind: string, label: string, name: string|null}, lines: list<Line>, coverage: Coverage|null}
 *
 * @see docs/permissions-readers.md#permissioncheckservice
 */
final readonly class PermissionCheckService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAuthorizationCheckerInterface $checker,
        private GrantVoter $voter,
        private ConcernCatalogue $catalogue,
        private PowerCatalogue $powers,
        private PermissionEvaluator $evaluator,
        private PermissionRunRepository $runs,
        private PermissionAnswerRepository $answers,
        private PositionRepository $positions,
        private UserRepository $users,
        private DepartmentRepository $departments,
        private GrantJustificationRepository $justifications,
    ) {
    }

    /**
     * A PAIR OR A POWER, by its key: for a pair `$where` is a place key (or
     * null for where they are placed), for a power a target kind (or null
     * for the power's first).
     *
     * @return PairCheck|PowerCheck
     */
    public function check(User $person, string $question, ?string $where): array
    {
        $power = $this->power($question);
        if (null !== $power) {
            return $this->checkPower($person, $power->key, $where ?? $power->targets[0]->value);
        }

        return $this->checkPair($person, $question, $where);
    }

    /** @return PairCheck */
    public function checkPair(User $person, string $pair, ?string $placeKey): array
    {
        $grant = Grant::tryParse($pair) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a pair.', $pair));
        [$key, $subject, $name] = $this->place($person, $placeKey);

        $decision = new AccessDecision();
        $allowed = $this->checker->isGrantedForUser($person, $pair, $subject, $decision);
        $walk = $this->voter->decide($person, $grant, $subject);
        $lines = $this->walk($walk, true);
        if ($walk->isAllowed() !== $allowed) {
            $lines[] = self::rule($allowed, self::reasons($decision), 'another rule decided');
        }

        return [
            'allowed' => $allowed,
            'question' => ['kind' => QuestionKindEnum::Pair->value, 'key' => $pair, 'label' => $this->pairLabel($pair)],
            'place' => null === $key ? null : ['key' => $key, 'name' => $name],
            'lines' => $lines,
            'coverage' => null === $key ? null : $this->pairCoverage($pair, $key),
        ];
    }

    /** @return PowerCheck */
    public function checkPower(User $person, string $powerKey, string $targetKind): array
    {
        $power = $this->power($powerKey) ?? throw new \InvalidArgumentException(\sprintf('No power is declared as "%s".', $powerKey));
        $kind = PowerTarget::from($targetKind);
        [$found, $target] = $this->evaluator->targetFor($person, $kind);
        $tier = $person->getTeamRole();
        $above = $tier->canManageContent();

        $lines = [$above
            ? self::line(GrantQuestionEnum::Tier->value, StepOutcomeEnum::Passed, $tier->label().' — above the matrix; the permissions are not asked, the power\'s own rule is', self::personDoor($person))
            : self::line(GrantQuestionEnum::Tier->value, StepOutcomeEnum::NotAsked, 'Staff — the tiers alone are not asked', self::personDoor($person))];

        $allowed = $found;
        if (!$found) {
            $lines[] = self::line('rule', StepOutcomeEnum::NotAsked, \sprintf('there is no %s in this installation to ask about', $kind->label()), null);
        }

        $source = $this->powers->sourceOf($power);
        foreach ($found ? $power->questions : [] as $question) {
            $subject = $source->subject($power, $question, $kind, $target);
            $decision = new AccessDecision();
            $granted = $this->checker->isGrantedForUser($person, $question, $subject, $decision);
            $grant = Grant::tryParse($question);
            if (!$above && null !== $grant && $this->catalogue->has($grant)) {
                $walk = $this->voter->decide($person, $grant, $subject);
                $lines = [...$lines, ...$this->walk($walk, false)];
                if ($walk->isAllowed() !== $granted) {
                    $lines[] = self::rule($granted, self::reasons($decision), $power->label);
                }
            } elseif (null === $grant || !$this->catalogue->has($grant) || !$granted) {
                $lines[] = self::rule($granted, self::reasons($decision), $power->label);
            }
            if (!$granted) {
                $allowed = false;
                break;
            }
        }

        return [
            'allowed' => $allowed,
            'verdict' => !$found ? CellAnswer::NoTarget->value : ($allowed ? CellAnswer::Allowed->value : CellAnswer::Refused->value),
            'question' => ['kind' => QuestionKindEnum::Power->value, 'key' => $power->key, 'label' => $power->label],
            'target' => ['kind' => $kind->value, 'label' => $kind->label(), 'name' => self::targetName($target)],
            'lines' => $lines,
            'coverage' => $this->powerCoverage($power->key, $kind),
        ];
    }

    /** The groups of {@see atPlace()}'s `everything`, in the order the page lists them. */
    public const array EVERYTHING_GROUPS = ['allowed', 'exception', 'sensitive', 'placement', 'place', 'department', 'tier-only', 'not-held'];

    /**
     * THE PERSON AT THE PLACE: only what the voter reads of them there — does
     * their placement cover it, does a department they serve run the
     * question's module, their position's exception — and every pair of the
     * catalogue asked there, one walk each through {@see GrantVoter::decide()}:
     * allowed first, then the refusals in the voter's step order. A power is
     * asked of their own placement, so its place is where they are placed. A
     * tier holds every pair by tier: no facts, no table.
     *
     * @param string $where a place key for a pair; ignored for a power
     *
     * @return Lens
     */
    public function atPlace(User $person, string $question, string $where): array
    {
        $isPower = null !== $this->power($question);
        $place = $isPower ? $this->ownPlace($person) : $this->placeOf($where);
        $subject = $this->place($person, $place['key'])[1];
        $placement = $person->getPlacement();
        $tier = $person->getTeamRole();
        $position = $person->getPosition();

        $areas = array_map(static fn (AreaInterface $a): string => (string) $a->getName(), $placement?->getAreas() ?? []);
        $cover = match (true) {
            null === $placement || $placement->reachesNothing() => ['covers' => false, 'how' => 'nothing', 'areas' => []],
            $placement->isWholeOrganization() => ['covers' => true, 'how' => 'organization', 'areas' => []],
            $subject instanceof AreaInterface && $placement->coversArea($subject) => ['covers' => true, 'how' => 'areas', 'areas' => $areas],
            default => ['covers' => false, 'how' => 'not', 'areas' => $areas],
        };

        $exception = $this->exceptionOf($position, $isPower ? null : $question);

        $everything = [];
        if (!$tier->canManageContent()) {
            foreach ($this->catalogue->pairs() as $pair) {
                $walk = $this->voter->decide($person, Grant::parse($pair), $subject);
                $line = self::deciding($this->walk($walk, true));
                $group = match (true) {
                    $walk->isAllowed() => 'allowed',
                    GrantQuestionEnum::TierOnly->value === $line['q'] => 'tier-only',
                    GrantQuestionEnum::Position->value === $line['q'] => 'not-held',
                    GrantQuestionEnum::Exception->value === $line['q'] => $pair === $exception['pair'] ? 'exception' : 'sensitive',
                    default => $line['q'],
                };
                $everything[] = [
                    'pair' => $pair,
                    'concern' => explode('.', $pair)[0],
                    'asked' => $pair === $question,
                    'allowed' => $walk->isAllowed(),
                    'step' => $line['q'],
                    'line' => $line['text'],
                    'held' => null !== $position && $position->hasGrant(Grant::parse($pair)),
                    'group' => $group,
                ];
            }
            usort($everything, static fn (array $a, array $b): int => array_search($a['group'], self::EVERYTHING_GROUPS, true) <=> array_search($b['group'], self::EVERYTHING_GROUPS, true));
        }

        return [
            'person' => self::nameOf($person),
            'place' => $place,
            'placeIsOwn' => $isPower,
            'tierHoldsAll' => $tier->canManageContent(),
            'tierLabel' => $tier->label(),
            'cover' => $cover,
            'department' => $isPower ? null : $this->departmentFacts($placement, $question),
            'exception' => [...$exception, 'appliesHere' => 'in-force' === $exception['state'] && $cover['covers']],
            'everything' => $everything,
            'catalogueCount' => \count($everything),
            'allowedHere' => \count(array_filter($everything, static fn (array $r): bool => $r['allowed'])),
        ];
    }

    /**
     * WHAT THE VOTER READS OF THE PERSON: their tier, position, placement and
     * their position's exception.
     *
     * @return array{tier: string, above: bool, position: array{name: string, uuid: string}|null, placedAt: string, department: string|null, supports: list<string>, exception: Exception}
     */
    public function aboutPerson(User $person): array
    {
        $position = $person->getPosition();
        $placement = $person->getPlacement();

        return [
            'tier' => $person->getTeamRole()->label(),
            'above' => $person->getTeamRole()->canManageContent(),
            'position' => null === $position ? null : ['name' => (string) $position->getName(), 'uuid' => (string) $position->getUuidString()],
            'placedAt' => $placement?->groundLabel() ?? 'nowhere',
            'department' => null === $placement?->getDepartment() ? null : (string) $placement->getDepartment()->getName(),
            'supports' => array_map(static fn (Department $d): string => (string) $d->getName(), $placement?->getSupports() ?? []),
            'exception' => $this->exceptionOf($position, null),
        ];
    }

    /**
     * THE QUESTION BEHIND THE CHECK: the concern of a pair — what it is, how
     * it is granted, who holds it — or the power and what it asks first.
     *
     * @return array{kind: 'pair', description: string, verbs: list<array{verb: string, asked: bool}>, scopes: list<string>, module: string|null, lifts: string|null, tierOnly: bool, positions: int, people: int}|array{kind: 'power', group: string, question: string, label: string, asksFirst: list<string>, target: string, targets: int}
     */
    public function aboutQuestion(string $question, ?string $target): array
    {
        $power = $this->power($question);
        if (null !== $power) {
            $kind = PowerTarget::tryFrom((string) $target) ?? $power->targets[0] ?? null;

            return [
                'kind' => 'power',
                'group' => $power->group->label(),
                'question' => $power->group->question(),
                'label' => $power->label,
                'asksFirst' => array_values(array_filter($power->questions, fn (string $q): bool => null !== Grant::tryParse($q) && $this->catalogue->has(Grant::parse($q)))),
                'target' => null === $kind ? '' : $kind->label(),
                'targets' => \count($power->targets),
            ];
        }

        $grant = Grant::parse($question);
        $concern = $this->catalogue->concern($grant->concern) ?? throw new \InvalidArgumentException(\sprintf('No concern is declared as "%s".', $grant->concern));
        $positions = 0;
        $people = 0;
        foreach ($this->positions->findAllOrdered() as $position) {
            if (!$position->isRetired() && $position->hasGrant($grant)) {
                ++$positions;
                $people += \count(array_filter($this->users->findActiveHolders($position), static fn (User $u): bool => TeamRoleEnum::Staff === $u->getTeamRole()));
            }
        }
        $module = $concern->moduleSlug();

        return [
            'kind' => 'pair',
            'description' => $concern->description(),
            'verbs' => array_map(static fn (Verb $v): array => ['verb' => $v->value, 'asked' => $v === $grant->verb], $concern->verbs()),
            'scopes' => array_map(static fn (ScopeKind $k): string => $k->value, $concern->scopeKinds()),
            'module' => null === $module ? null : $this->moduleName($module, []),
            'lifts' => $concern->lifts(),
            'tierOnly' => $concern->isTierOnly($grant->verb),
            'positions' => $positions,
            'people' => $people,
        ];
    }

    /**
     * EVERY ACTIVE PERSON, by tier: the Super Admins, the Admins, the Staff.
     *
     * @return list<array{tier: string, label: string, people: list<array{id: int, name: string, position: string|null}>}>
     */
    public function people(): array
    {
        $groups = [];
        foreach ([TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin, TeamRoleEnum::Staff] as $tier) {
            $groups[] = ['tier' => $tier->value, 'label' => $tier->label(), 'people' => array_map(static fn (User $u): array => [
                'id' => (int) $u->getId(),
                'name' => self::nameOf($u),
                'position' => null === $u->getPosition() ? null : (string) $u->getPosition()->getName(),
            ], $this->users->findActiveInTier($tier))];
        }

        return $groups;
    }

    /**
     * THE PLACES A PAIR IS ASKED AT: the whole organization, then every area
     * by name.
     *
     * @return list<array{key: string, name: string}>
     */
    public function places(): array
    {
        $class = $this->entityManager->getClassMetadata(Placement::class)->getAssociationTargetClass('areas');
        $places = [['key' => PlaceKey::ORGANIZATION, 'name' => 'the whole organization']];
        foreach ($this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']) as $area) {
            if ($area instanceof AreaInterface) {
                $places[] = ['key' => PlaceKey::of(PlaceKindEnum::Area, $area->getId()), 'name' => (string) $area->getName()];
            }
        }

        return $places;
    }

    /**
     * WHERE THEY ARE PLACED, as a place a pair is asked at: their first area,
     * else the whole organization — placed across it, or placed nowhere.
     *
     * @return array{key: string, name: string}
     */
    public function ownPlace(User $person): array
    {
        $placement = $person->getPlacement();
        $area = null === $placement || $placement->isWholeOrganization() ? null : ($placement->getAreas() ?? [])[0] ?? null;

        return null === $area
            ? ['key' => PlaceKey::ORGANIZATION, 'name' => 'the whole organization']
            : ['key' => PlaceKey::of(PlaceKindEnum::Area, $area->getId()), 'name' => (string) $area->getName()];
    }

    /** @return array{key: string, name: string} */
    private function placeOf(string $key): array
    {
        [, , $name] = $this->place(null, $key);

        return ['key' => $key, 'name' => $name];
    }

    /**
     * THE STEP THAT DECIDED: the line that refused, else the last that passed.
     *
     * @param list<Line> $lines
     *
     * @return Line
     */
    private static function deciding(array $lines): array
    {
        $passed = null;
        foreach ($lines as $line) {
            if (StepOutcomeEnum::Refused->value === $line['status']) {
                return $line;
            }
            if (StepOutcomeEnum::Passed->value === $line['status']) {
                $passed = $line;
            }
        }

        return $passed ?? $lines[\count($lines) - 1];
    }

    /**
     * THEIR POSITION'S EXCEPTION: of the pairs it holds that lift a rule, the
     * asked one when it is one, else the first with a written reason in force,
     * else the first without.
     *
     * @return Exception
     */
    private function exceptionOf(?Position $position, ?string $asked): array
    {
        $none = ['state' => 'none', 'pair' => null, 'by' => null, 'at' => null, 'reason' => null];
        if (null === $position) {
            return $none;
        }
        $lifting = array_values(array_filter($this->catalogue->pairs(), fn (string $pair): bool => $position->hasGrant(Grant::parse($pair)) && null !== $this->catalogue->lifts(explode('.', $pair)[0])));
        if ([] === $lifting) {
            return $none;
        }
        $current = $this->justifications->findCurrentByPosition($position);
        $pick = \in_array($asked, $lifting, true) ? $asked : null;
        foreach ($lifting as $pair) {
            $pick ??= isset($current[$pair]) ? $pair : null;
        }
        $pick ??= $lifting[0];
        $justification = $current[$pick] ?? null;

        return $justification instanceof GrantJustification
            ? ['state' => 'in-force', 'pair' => $pick, 'by' => $justification->getGrantedByName(), 'at' => $justification->getGrantedAt(), 'reason' => $justification->getReason()]
            : ['state' => 'not-in-force', 'pair' => $pick, 'by' => null, 'at' => null, 'reason' => null];
    }

    /**
     * DOES A DEPARTMENT THEY SERVE RUN THE QUESTION'S MODULE — asked of the
     * departments, whether or not the voter reaches the question.
     *
     * @return array{module: string|null, served: list<string>, running: list<string>, hit: string|null}
     */
    private function departmentFacts(?Placement $placement, string $pair): array
    {
        $served = array_values(array_filter([$placement?->getDepartment(), ...($placement?->getSupports() ?? [])]));
        $names = array_map(static fn (Department $d): string => (string) $d->getName(), $served);
        $slug = $this->catalogue->moduleOf(explode('.', $pair)[0]);
        if (null === $slug) {
            return ['module' => null, 'served' => $names, 'running' => [], 'hit' => null];
        }
        $running = [];
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            foreach ($department->getModules() as $module) {
                if ($slug === $module->getSlug()) {
                    $running[] = $department;
                    break;
                }
            }
        }
        $hit = null;
        foreach ($running as $department) {
            if (null === $hit && null !== $placement && $placement->serves($department)) {
                $hit = (string) $department->getName();
            }
        }

        return ['module' => $this->moduleName($slug, $running), 'served' => $names, 'running' => array_map(static fn (Department $d): string => (string) $d->getName(), $running), 'hit' => $hit];
    }

    /**
     * ONE LINE PER STEP THE VOTER TOOK, in words.
     *
     * @return list<Line>
     */
    private function walk(GrantDecision $decision, bool $withTier): array
    {
        $person = $decision->person;
        $pair = $decision->pair;
        $placement = $decision->getPlacement();
        $position = $decision->getPosition();

        $lines = [];
        foreach ($decision->getSteps() as $step) {
            $ok = StepOutcomeEnum::Passed === $step->outcome;
            $no = StepOutcomeEnum::Refused === $step->outcome;
            $line = match ($step->question) {
                GrantQuestionEnum::Tier => $withTier ? [$ok
                    ? $person->getTeamRole()->label().' — above the matrix; nothing else is asked'
                    : 'Staff — the tiers alone are not asked', self::personDoor($person)] : null,
                GrantQuestionEnum::TierOnly => $no ? [\sprintf('only Admins and Super Admins hold %s; no position does', $pair), null] : null,
                GrantQuestionEnum::Position => null === $position
                    ? ['holds no position', self::personDoor($person)]
                    : [\sprintf($ok ? '%s — holds %s' : '%s — does not hold %s', (string) $position->getName(), $pair), self::positionDoor('position', $position)],
                GrantQuestionEnum::Exception => [$this->exceptionText($decision, $step->outcome), null === $position ? null : self::positionDoor('exception', $position)],
                GrantQuestionEnum::Placement => [$ok && null !== $placement
                    ? PermissionChangeLog::placementOf($placement)
                    : 'their placement reaches nothing — '.self::nothingBecause($placement), self::placementDoor($person)],
                GrantQuestionEnum::Place => [self::placeText($decision->subject, $placement, $ok), $no ? self::placementDoor($person) : null],
                GrantQuestionEnum::Department => $this->departmentLine($decision, $step->outcome),
            };
            if (null !== $line) {
                $lines[] = self::line($step->question->value, $step->outcome, $line[0], $line[1]);
            }
        }

        return $lines;
    }

    private function exceptionText(GrantDecision $decision, StepOutcomeEnum $outcome): string
    {
        $justification = $decision->getJustification();

        return match ($outcome) {
            StepOutcomeEnum::NotAsked => 'not a permission that lifts a rule',
            StepOutcomeEnum::Refused => \sprintf('%s lifts %s, and no written reason is in force', $decision->pair, (string) $decision->getLifts()),
            StepOutcomeEnum::Passed => null === $justification ? 'in force' : \sprintf('in force — %s, %s: “%s”',
                $justification->getGrantedByName(), $justification->getGrantedAt()->format('j M Y'), $justification->getReason()),
        };
    }

    private static function nothingBecause(?Placement $placement): string
    {
        return match (true) {
            null === $placement => 'no placement',
            null === $placement->getDepartment() => 'no department',
            default => 'no ground',
        };
    }

    private static function placeText(AreaInterface|WholeOrganization|null $subject, ?Placement $placement, bool $ok): string
    {
        $ground = $placement?->groundLabel() ?? 'nowhere';
        $across = null !== $placement && $placement->isWholeOrganization();

        return match (true) {
            $subject instanceof WholeOrganization => $ok ? 'the whole organization — placed across it' : \sprintf('the whole organization — their placement covers %s only', $ground),
            $subject instanceof AreaInterface => $ok
                ? \sprintf('%s — %s', (string) $subject->getName(), $across ? 'placed across the organization' : 'placed there')
                : \sprintf('%s — their placement does not cover it (placed at %s only)', (string) $subject->getName(), $ground),
            default => $ok ? 'anywhere they are placed — '.$ground : 'their placement covers no place',
        };
    }

    /** @return array{string, Door|null} */
    private function departmentLine(GrantDecision $decision, StepOutcomeEnum $outcome): array
    {
        $module = $decision->getModule();
        $running = $decision->getRunning();
        $served = $decision->getServedBy();
        $own = $decision->getPlacement()?->getDepartment();

        return match (true) {
            null === $module => ['the resource belongs to no module', null],
            StepOutcomeEnum::NotAsked === $outcome => [\sprintf('no department runs %s, so none is asked', $this->moduleName($module, $running)), null],
            StepOutcomeEnum::Passed === $outcome && null !== $served => [\sprintf('%s runs %s', (string) $served->getName(), $this->moduleName($module, $running)), self::departmentDoor($served)],
            default => [\sprintf('%s is run by %s, and they serve %s', $this->moduleName($module, $running),
                implode(', ', array_map(static fn (Department $d): string => (string) $d->getName(), $running)),
                $decision->getPlacement()?->departmentsLabel() ?? 'no department'), null === $own ? null : self::departmentDoor($own)],
        };
    }

    /** @param list<Department> $running */
    private function moduleName(string $slug, array $running): string
    {
        foreach ($running as $department) {
            foreach ($department->getModules() as $module) {
                if ($slug === $module->getSlug()) {
                    return (string) $module->getName();
                }
            }
        }

        return (string) ($this->entityManager->getRepository(Module::class)->findOneBy(['slug' => $slug])?->getName() ?? $slug);
    }

    /**
     * THE PLACE ASKED ABOUT: the key given, or where the person is placed —
     * their first area, the whole organization for a placement across it,
     * and none when they are placed nowhere.
     *
     * @return array{string|null, AreaInterface|WholeOrganization|null, string}
     */
    private function place(?User $person, ?string $placeKey): array
    {
        if (null === $placeKey) {
            $placement = $person?->getPlacement();
            if (null !== $placement && $placement->isWholeOrganization()) {
                return [PlaceKey::ORGANIZATION, new WholeOrganization(), 'the whole organization'];
            }
            $area = ($placement?->getAreas() ?? [])[0] ?? null;

            return null === $area ? [null, null, 'nowhere'] : [PlaceKey::of(PlaceKindEnum::Area, $area->getId()), $area, (string) $area->getName()];
        }

        $place = PlaceKey::parse($placeKey) ?? throw new \InvalidArgumentException(\sprintf('"%s" names no place.', $placeKey));
        if (PlaceKindEnum::Organization === $place[0]) {
            return [PlaceKey::ORGANIZATION, new WholeOrganization(), 'the whole organization'];
        }
        $class = $this->entityManager->getClassMetadata(Placement::class)->getAssociationTargetClass('areas');
        $area = $this->entityManager->getRepository($class)->find($place[1]);
        if (!$area instanceof AreaInterface) {
            throw new \InvalidArgumentException(\sprintf('"%s" names no area.', $placeKey));
        }

        return [$placeKey, $area, (string) $area->getName()];
    }

    /** @return Coverage|null */
    private function pairCoverage(string $pair, string $placeKey): ?array
    {
        $run = $this->runs->findOneLatestFinished();
        $place = PlaceKey::parse($placeKey);
        if (null === $run || null === $place) {
            return null;
        }

        $names = $this->positionNames();
        $rows = [];
        foreach ($this->answers->findByRunAndPlace($run, $place[0], $place[1]) as $answer) {
            if ($pair === $answer->getQuestionKey() && null !== $answer->getPositionId()) {
                $rows[] = ['position' => $names[$answer->getPositionId()] ?? 'a position', 'count' => $answer->getPeopleCount()];
            }
        }

        return self::coverage($run->getStartedAt(), $rows);
    }

    /** @return Coverage|null */
    private function powerCoverage(string $power, PowerTarget $kind): ?array
    {
        $run = $this->runs->findOneLatestFinished();
        if (null === $run) {
            return null;
        }

        $names = $this->positionNames();
        $rows = [];
        foreach ($this->answers->findByRunAndQuestion($run, QuestionKindEnum::Power, $power) as $answer) {
            if ($kind->value === $answer->getTarget() && CellAnswer::Allowed->value === $answer->getVerdict()) {
                $tier = $answer->getTier();
                $rows[] = [
                    'position' => null !== $tier ? (string) TeamRoleEnum::tryFrom($tier)?->label() : ($names[(int) $answer->getPositionId()] ?? 'a position'),
                    'count' => \count($answer->getPeople()),
                ];
            }
        }

        return self::coverage($run->getStartedAt(), $rows);
    }

    /**
     * @param list<array{position: string, count: int}> $rows
     *
     * @return array{asOf: ?\DateTimeImmutable, total: int, byPosition: list<array{position: string, count: int}>}
     */
    private static function coverage(?\DateTimeImmutable $asOf, array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['position']] <=> [$a['count'], $b['position']]);

        return ['asOf' => $asOf, 'total' => array_sum(array_column($rows, 'count')), 'byPosition' => $rows];
    }

    /** @return array<int, string> */
    private function positionNames(): array
    {
        $names = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $names[(int) $position->getId()] = (string) $position->getName();
        }

        return $names;
    }

    private function power(string $key): ?Power
    {
        foreach ($this->powers->all() as $power) {
            if ($key === $power->key) {
                return $power;
            }
        }

        return null;
    }

    private function pairLabel(string $pair): string
    {
        $grant = Grant::tryParse($pair);
        $concern = null === $grant ? null : $this->catalogue->concern($grant->concern);

        return null === $grant || null === $concern ? $pair : $concern->label().' · '.$grant->verb->value;
    }

    /** @return list<string> */
    private static function reasons(AccessDecision $decision): array
    {
        $reasons = [];
        foreach ($decision->votes as $vote) {
            if (VoterInterface::ACCESS_DENIED === $vote->result) {
                $reasons = [...$reasons, ...$vote->reasons];
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param list<string> $reasons
     *
     * @return Line
     */
    private static function rule(bool $granted, array $reasons, string $otherwise): array
    {
        return self::line('rule', $granted ? StepOutcomeEnum::Passed : StepOutcomeEnum::Refused, [] === $reasons ? $otherwise : implode(' ', $reasons), null);
    }

    /**
     * @param Door|null $door
     *
     * @return Line
     */
    private static function line(string $q, StepOutcomeEnum $outcome, string $text, ?array $door): array
    {
        return ['q' => $q, 'status' => $outcome->value, 'text' => $text, 'door' => $door];
    }

    private static function targetName(User|Position|null $target): ?string
    {
        return match (true) {
            $target instanceof User => self::nameOf($target),
            $target instanceof Position => (string) $target->getName(),
            default => null,
        };
    }

    /** @return Door */
    private static function personDoor(User $person): array
    {
        return ['kind' => 'person', 'id' => $person->getId(), 'uuid' => $person->getUuidString(), 'name' => self::nameOf($person)];
    }

    /** @return Door */
    private static function placementDoor(User $person): array
    {
        return ['kind' => 'placement', 'id' => $person->getId(), 'uuid' => $person->getUuidString(), 'name' => self::nameOf($person)];
    }

    /** @return Door */
    private static function positionDoor(string $kind, Position $position): array
    {
        return ['kind' => $kind, 'id' => $position->getId(), 'uuid' => $position->getUuidString(), 'name' => (string) $position->getName()];
    }

    /** @return Door */
    private static function departmentDoor(Department $department): array
    {
        return ['kind' => 'department', 'id' => $department->getId(), 'uuid' => $department->getUuidString(), 'name' => (string) $department->getName()];
    }

    private static function nameOf(User $person): string
    {
        $name = trim($person->getFullName());

        return '' === $name ? (string) $person->getEmail() : $name;
    }
}
