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

use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionFinding;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PlaceKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionDriftRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Power;

/**
 * THE STANDING LISTS, THE FRESHNESS LINE AND THE DRIFT of the latest
 * finished run, in the words the hub and the Analysis page print; and the
 * one write the pages make, marking drift reviewed.
 *
 * @phpstan-type WorthRow array{
 *     person: array{id: int, uuid: string, name: string},
 *     question: array{kind: string, key: string, label: string},
 *     place: array{key: string, name: string},
 *     why: string,
 *     kind: string,
 *     group: 'attention'|'confirmation'|'none',
 *     flagged: bool,
 * }
 *
 * @see docs/permissions-readers.md#permissionfindingsreader
 */
final readonly class PermissionFindingsReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PermissionRunRepository $runs,
        private PermissionFindingRepository $findings,
        private PermissionDriftRepository $drift,
        private ConcernCatalogue $catalogue,
        private PowerCatalogue $powers,
        private UserRepository $users,
        private PositionRepository $positions,
        private string $schedule,
        private ?string $timezone = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return array{
     *     asOf: ?\DateTimeImmutable,
     *     lists: list<array{id: string, label: string, alarm: bool, count: int, entries: list<array<string, mixed>>}>,
     * }
     */
    public function standing(): array
    {
        $run = $this->runs->findOneLatestFinished();

        $lists = [];
        foreach (FindingListEnum::cases() as $list) {
            $entries = null === $run ? [] : array_map(fn (PermissionFinding $f): array => $this->entry($list, $f), $this->findings->findByRunAndList($run, $list));
            $lists[] = ['id' => $list->value, 'label' => $list->label(), 'alarm' => $list->isAlarm(), 'count' => \count($entries), 'entries' => $entries];
        }

        return ['asOf' => $run?->getStartedAt(), 'lists' => $lists];
    }

    /**
     * THE LINE ABOVE EVERY PAGE: as of the latest run, and when the next is.
     *
     * @return array{
     *     asOf: ?\DateTimeImmutable, previousRun: ?\DateTimeImmutable, nextRun: \DateTimeImmutable,
     *     pending: bool, running: bool, lastFailure: ?string, questions: int, ms: int,
     * }
     */
    public function freshness(): array
    {
        $run = $this->runs->findOneLatestFinished();
        $previous = null === $run ? null : $this->runs->findOnePreviousFinished($run);
        $failed = $this->runs->findOneLatestFailed();
        $asOf = $run?->getStartedAt();
        $failedAt = $failed?->getFinishedAt();

        return [
            'asOf' => $asOf,
            'previousRun' => $previous?->getStartedAt(),
            'nextRun' => $this->nextRun(),
            'pending' => null !== $this->runs->findOnePending(),
            'running' => null !== $this->runs->findOneRunning(),
            'lastFailure' => null !== $failed && null !== $failedAt && (null === $asOf || $failedAt > $asOf) ? $failed->getFailure() : null,
            'questions' => $run?->getCount('questions') ?? 0,
            'ms' => $run?->getCount('ms') ?? 0,
        ];
    }

    /**
     * THE LATEST RUN'S DRIFT, one line per subject and question with the
     * places — or, for a power, the targets — that moved under it.
     *
     * @return array{
     *     unreviewed: int,
     *     lines: list<array{
     *         subject: string, positionId: int|null, questionKind: string, question: string, label: string,
     *         places: list<array{driftId: int, place: string, was: int, now: int, reviewed: bool}>,
     *         found: ?\DateTimeImmutable, previousRun: ?\DateTimeImmutable,
     *     }>,
     * }
     */
    public function drift(): array
    {
        $run = $this->runs->findOneLatestFinished();
        if (null === $run) {
            return ['unreviewed' => 0, 'lines' => []];
        }

        $lines = [];
        foreach ($this->drift->findByRun($run) as $drift) {
            $key = $drift->getSubjectName().'|'.$drift->getQuestionKind()->value.'|'.$drift->getQuestionKey();
            $lines[$key] ??= [
                'subject' => $drift->getSubjectName(),
                'positionId' => $drift->getPositionId(),
                'questionKind' => $drift->getQuestionKind()->value,
                'question' => $drift->getQuestionKey(),
                'label' => $this->questionLabel($drift->getQuestionKind(), $drift->getQuestionKey()),
                'places' => [],
                'found' => $run->getStartedAt(),
                'previousRun' => $drift->getPreviousRun()?->getStartedAt(),
            ];
            $lines[$key]['places'][] = [
                'driftId' => (int) $drift->getId(),
                'place' => $drift->getPlaceName(),
                'was' => $drift->getBefore(),
                'now' => $drift->getAfter(),
                'reviewed' => $drift->isReviewed(),
            ];
        }

        return ['unreviewed' => $this->drift->countUnreviewedByRun($run), 'lines' => array_values($lines)];
    }

    /**
     * MARKS THE LATEST RUN'S UNREVIEWED DRIFT REVIEWED — all of it, or the
     * ids given — in the name of whoever pressed the button.
     *
     * @param list<int>|null $driftIds
     *
     * @return int how many were marked
     */
    public function markReviewed(User $by, ?array $driftIds = null): int
    {
        $run = $this->runs->findOneLatestFinished();
        if (null === $run) {
            return 0;
        }

        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $name = trim($by->getFullName());
        $name = '' === $name ? (string) $by->getEmail() : $name;
        $marked = 0;
        foreach ($this->drift->findByRun($run) as $drift) {
            if ($drift->isReviewed() || (null !== $driftIds && !\in_array($drift->getId(), $driftIds, true))) {
                continue;
            }
            $drift->review($now, $name);
            ++$marked;
        }
        $this->entityManager->flush();

        return $marked;
    }

    /** The words of a finding, and the kind the page filters by. */
    private const array KINDS = [
        'reaches nothing' => 'reaches-nothing',
        'exception not in force' => 'not-in-force',
        'stored, not honoured' => 'stored-not-honoured',
        'gains power' => 'gains-power',
        'exception in force' => 'in-force',
        'nothing flagged' => 'nothing-flagged',
    ];

    /**
     * THE CHECKS WORTH RUNNING: every active Staff person once, the flagged
     * first by the latest run's findings in this order — each person who
     * reaches nothing, each pair held but not in force, each tier-only pair
     * stored, each gaining-power answer, each exception in force — then
     * everybody else by name, at their first held pair (a sensitive one when
     * they hold one) with nothing flagged. A person a finding has named
     * already keeps that first row. For a power, `place` is the power's first
     * target kind. Empty before a run.
     *
     * @return list<WorthRow>
     */
    public function worthRunning(): array
    {
        $run = $this->runs->findOneLatestFinished();
        if (null === $run) {
            return [];
        }

        $rows = [];
        foreach ($this->findings->findByRunAndList($run, FindingListEnum::ReachNothing) as $finding) {
            $person = null === $finding->getSubjectId() ? null : $this->users->find($finding->getSubjectId());
            if (null !== $person) {
                $this->add($rows, $person, $this->pairRow($person, $this->firstPair($person), self::placeOf($person), 'reaches nothing'));
            }
        }
        foreach ([[FindingListEnum::NotInForce, 'exception not in force'], [FindingListEnum::NotHonoured, 'stored, not honoured']] as [$list, $why]) {
            foreach ($this->findings->findByRunAndList($run, $list) as $finding) {
                $holder = $this->holderOf($finding->getSubjectId());
                if (null !== $holder) {
                    $place = FindingListEnum::NotHonoured === $list ? self::organization() : self::placeOf($holder);
                    $this->add($rows, $holder, $this->pairRow($holder, (string) $finding->getPair(), $place, $why));
                }
            }
        }
        foreach ($this->findings->findByRunAndList($run, FindingListEnum::Gaining) as $finding) {
            $actor = null === $finding->getSubjectId()
                ? $this->firstInTier($finding->getSubjectName())
                : $this->holderOf($finding->getSubjectId());
            $power = $this->power((string) $finding->getPair());
            if (null !== $actor && null !== $power && [] !== $power->targets) {
                $this->add($rows, $actor, [
                    'person' => self::personOf($actor),
                    'question' => ['kind' => QuestionKindEnum::Power->value, 'key' => $power->key, 'label' => $power->label],
                    'place' => ['key' => $power->targets[0]->value, 'name' => $power->targets[0]->label()],
                    'why' => 'gains power',
                    'kind' => self::KINDS['gains power'],
                    'group' => 'attention',
                    'flagged' => true,
                ]);
            }
        }
        foreach ($this->findings->findByRunAndList($run, FindingListEnum::Exceptions) as $finding) {
            $holder = $this->holderOf($finding->getSubjectId());
            if (null !== $holder) {
                $this->add($rows, $holder, $this->pairRow($holder, (string) $finding->getPair(), self::placeOf($holder), 'exception in force'));
            }
        }
        foreach ($this->users->findActiveInTier(TeamRoleEnum::Staff) as $person) {
            $this->add($rows, $person, $this->pairRow($person, $this->firstPair($person, true), self::placeOf($person), 'nothing flagged'));
        }

        return array_values($rows);
    }

    /**
     * A ROW FOR AN ACTIVE STAFF PERSON whom no row names yet.
     *
     * @param array<int, WorthRow> $rows
     * @param WorthRow             $row
     *
     * @param-out array<int, WorthRow> $rows
     */
    private function add(array &$rows, ?User $person, array $row): void
    {
        $id = (int) $person?->getId();
        if (null !== $person && $person->isActive() && TeamRoleEnum::Staff === $person->getTeamRole() && !isset($rows[$id])) {
            $rows[$id] = $row;
        }
    }

    /**
     * @param array{key: string, name: string} $place
     *
     * @return WorthRow
     */
    private function pairRow(User $person, string $pair, array $place, string $why): array
    {
        return [
            'person' => self::personOf($person),
            'question' => ['kind' => QuestionKindEnum::Pair->value, 'key' => $pair, 'label' => $this->pairLabel($pair)],
            'place' => $place,
            'why' => $why,
            'kind' => self::KINDS[$why] ?? $why,
            'group' => match ($why) {
                'exception in force' => 'confirmation',
                'nothing flagged' => 'none',
                default => 'attention',
            },
            'flagged' => 'nothing flagged' !== $why,
        ];
    }

    /**
     * The first pair their position holds, in the catalogue's order — with
     * `$sensitive`, the first sensitive one when they hold one — else the
     * catalogue's first a position may hold.
     */
    private function firstPair(User $person, bool $sensitive = false): string
    {
        $pairs = $this->catalogue->pairs();
        $held = array_values(array_filter($pairs, static fn (string $pair): bool => \in_array($pair, $person->getPosition()?->getGrantValues() ?? [], true)));
        if ($sensitive) {
            foreach ($held as $pair) {
                if ($this->catalogue->isSensitive(explode('.', $pair)[0])) {
                    return $pair;
                }
            }
        }

        return $held[0] ?? $this->catalogue->positionPairs()[0] ?? $pairs[0] ?? '';
    }

    /** The first active person of the tier a gaining finding names by its label. */
    private function firstInTier(string $label): ?User
    {
        foreach (TeamRoleEnum::cases() as $tier) {
            if ($label === $tier->label()) {
                return $this->users->findActiveInTier($tier)[0] ?? null;
            }
        }

        return null;
    }

    private function holderOf(?int $positionId): ?User
    {
        $position = null === $positionId ? null : $this->positions->find($positionId);
        if (null === $position) {
            return null;
        }
        foreach ($this->users->findActiveHolders($position) as $holder) {
            if (TeamRoleEnum::Staff === $holder->getTeamRole()) {
                return $holder;
            }
        }

        return null;
    }

    /** @return array{key: string, name: string} their first area, or the whole organization */
    private static function placeOf(User $person): array
    {
        $placement = $person->getPlacement();
        $area = null === $placement || $placement->isWholeOrganization() ? null : ($placement->getAreas() ?? [])[0] ?? null;

        return null === $area ? self::organization() : ['key' => PlaceKey::of(PlaceKindEnum::Area, $area->getId()), 'name' => (string) $area->getName()];
    }

    /** @return array{key: string, name: string} */
    private static function organization(): array
    {
        return ['key' => PlaceKey::ORGANIZATION, 'name' => 'the whole organization'];
    }

    /** @return array{id: int, uuid: string, name: string} */
    private static function personOf(User $person): array
    {
        $name = trim($person->getFullName());

        return ['id' => (int) $person->getId(), 'uuid' => (string) $person->getUuidString(), 'name' => '' === $name ? (string) $person->getEmail() : $name];
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

    /** When the schedule next asks for a run, in the schedule's own zone. */
    public function nextRun(): \DateTimeImmutable
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $zone = $this->timezone ?? date_default_timezone_get();

        return \DateTimeImmutable::createFromMutable(new CronExpression($this->schedule)->getNextRunDate($now, 0, false, $zone));
    }

    /** @return array<string, mixed> */
    private function entry(FindingListEnum $list, PermissionFinding $finding): array
    {
        $pair = (string) $finding->getPair();

        return match ($list) {
            FindingListEnum::ReachNothing => ['personId' => (int) $finding->getSubjectId(), 'name' => $finding->getSubjectName(), 'position' => $finding->getPositionName(), 'why' => (string) $finding->getDetail()],
            FindingListEnum::NotInForce => ['positionId' => (int) $finding->getSubjectId(), 'position' => $finding->getSubjectName(), 'pair' => $pair, 'pairLabel' => $this->pairLabel($pair), 'why' => (string) $finding->getDetail()],
            FindingListEnum::NotHonoured => ['positionId' => (int) $finding->getSubjectId(), 'position' => $finding->getSubjectName(), 'pair' => $pair, 'pairLabel' => $this->pairLabel($pair)],
            FindingListEnum::Gaining => ['subject' => $finding->getSubjectName(), 'positionId' => $finding->getSubjectId(), 'power' => $pair, 'detail' => (string) $finding->getDetail()],
            FindingListEnum::Vacant => ['positionId' => (int) $finding->getSubjectId(), 'position' => $finding->getSubjectName(), 'since' => $finding->getAt()],
            FindingListEnum::Exceptions => ['positionId' => (int) $finding->getSubjectId(), 'position' => $finding->getSubjectName(), 'pair' => $pair, 'pairLabel' => $this->pairLabel($pair),
                'reason' => (string) $finding->getDetail(), 'by' => (string) $finding->getByName(), 'at' => $finding->getAt()],
            FindingListEnum::SelfChanges => self::selfChange($finding),
        };
    }

    /** @return array{personId: int, name: string, kind: string, what: string, before: string|null, after: string|null, at: ?\DateTimeImmutable} */
    private static function selfChange(PermissionFinding $finding): array
    {
        return [
            'personId' => (int) $finding->getSubjectId(),
            'name' => $finding->getSubjectName(),
            'kind' => (string) $finding->getChangeKind(),
            'what' => (string) $finding->getWhat(),
            'before' => $finding->getBeforeValue(),
            'after' => $finding->getAfterValue(),
            'at' => $finding->getChangedAt(),
        ];
    }

    private function questionLabel(QuestionKindEnum $kind, string $key): string
    {
        if (QuestionKindEnum::Pair === $kind) {
            return $this->pairLabel($key);
        }
        foreach ($this->powers->all() as $power) {
            if ($key === $power->key) {
                return $power->label;
            }
        }

        return $key;
    }

    private function pairLabel(string $pair): string
    {
        $grant = Grant::tryParse($pair);
        $concern = null === $grant ? null : $this->catalogue->concern($grant->concern);

        return null === $grant || null === $concern ? $pair : $concern->label().' · '.$grant->verb->value;
    }
}
