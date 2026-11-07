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

use Uhifadhi\Bundle\TeamBundle\Entity\PermissionAnswer;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionDrift;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\QuestionKindEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionAnswerRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionChangeRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Contracts\Access\PowerTarget;

/**
 * DRIFT: AN ANSWER THAT CHANGED BETWEEN TWO RUNS WITH NO HISTORY LINE THAT
 * TOUCHES IT, recorded between the two runs' start times.
 *
 * WHICH LINE TOUCHES WHICH ANSWER, for a pair:
 *
 *   - a line about a DEPARTMENT touches every answer — what a department runs,
 *     where it reaches and who sees it can move any cell;
 *   - a line about a POSITION touches that position's cells;
 *   - a line about a PERSON touches every cell they were in before or are in
 *     now, which is what the stored ids are kept for.
 *
 * FOR A POWER any line between the runs explains a changed answer, because
 * the target a power is asked about is found among everybody.
 *
 * WHAT IS NOT COMPARED: a pair or a place one of the two runs did not ask
 * about. A module installed or an area gazetted between the runs adds
 * questions; it does not make the old answers drift.
 */
final readonly class PermissionDriftService
{
    public function __construct(
        private PermissionAnswerRepository $answers,
        private PermissionChangeRepository $changes,
        private PositionRepository $positions,
    ) {
    }

    /**
     * @param list<PermissionAnswer> $current the run's own answers, not yet flushed
     *
     * @return list<PermissionDrift>
     */
    public function compute(PermissionRun $run, ?PermissionRun $previous, array $current): array
    {
        $from = $previous?->getStartedAt();
        $to = $run->getStartedAt();
        if (null === $previous || null === $from || null === $to) {
            return [];
        }

        $lines = $this->changes->findRecordedBetween($from, $to);
        $before = self::indexed($this->answers->findByRun($previous));
        $after = self::indexed($current);

        $places = [];
        foreach ($previous->getPlaces() as $place) {
            $places[$place['kind'].':'.($place['id'] ?? '')] = true;
        }
        $placeNames = [];
        foreach ($run->getPlaces() as $place) {
            $key = $place['kind'].':'.($place['id'] ?? '');
            if (isset($places[$key])) {
                $placeNames[$key] = $place['name'];
            }
        }
        $pairs = array_flip(array_intersect($previous->getPairs(), $run->getPairs()));
        $powersBefore = [];
        foreach ($before as $answer) {
            if (QuestionKindEnum::Power === $answer->getQuestionKind()) {
                $powersBefore[$answer->getQuestionKey()] = true;
            }
        }
        $names = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $id = $position->getId();
            if (null !== $id) {
                $names[$id] = (string) $position->getName();
            }
        }

        $drift = [];
        foreach (array_keys($before + $after) as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;
            $answer = $now ?? $was;
            if (null === $answer) {
                continue;
            }

            if (QuestionKindEnum::Pair === $answer->getQuestionKind()) {
                $place = ($answer->getPlaceKind()->value ?? '').':'.($answer->getPlaceId() ?? '');
                if (!isset($pairs[$answer->getQuestionKey()], $placeNames[$place])) {
                    continue;
                }
                $old = $was?->getPeopleCount() ?? 0;
                $new = $now?->getPeopleCount() ?? 0;
                if ($old === $new || self::explainsPair($lines, $answer->getPositionId(), [...$was?->getPeople() ?? [], ...$now?->getPeople() ?? []])) {
                    continue;
                }
                $drift[] = new PermissionDrift($run, $previous, QuestionKindEnum::Pair, $answer->getQuestionKey(), $answer->getPlaceKind(), $answer->getPlaceId(),
                    $placeNames[$place], $answer->getPositionId(), self::subjectOf($answer, $names), before: $old, after: $new);

                continue;
            }

            if (!isset($powersBefore[$answer->getQuestionKey()]) || null === $was || null === $now || [] !== $lines) {
                continue;
            }
            $old = CellAnswer::Allowed->value === $was->getVerdict() ? 1 : 0;
            $new = CellAnswer::Allowed->value === $now->getVerdict() ? 1 : 0;
            if ($old !== $new) {
                $drift[] = new PermissionDrift($run, $previous, QuestionKindEnum::Power, $answer->getQuestionKey(), null, null,
                    PowerTarget::tryFrom((string) $answer->getTarget())?->label() ?? (string) $answer->getTarget(),
                    $answer->getPositionId(), self::subjectOf($answer, $names), before: $old, after: $new);
            }
        }

        return $drift;
    }

    /**
     * @param list<PermissionChange> $lines
     * @param list<int>              $people
     */
    private static function explainsPair(array $lines, ?int $position, array $people): bool
    {
        foreach ($lines as $line) {
            $subject = $line->getSubjectId();
            if (PermissionChange::DEPARTMENT === $line->getSubjectKind()
                || (PermissionChange::POSITION === $line->getSubjectKind() && null !== $position && $subject === $position)
                || (PermissionChange::PERSON === $line->getSubjectKind() && \in_array($subject, $people, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<PermissionAnswer> $answers
     *
     * @return array<string, PermissionAnswer>
     */
    private static function indexed(array $answers): array
    {
        $indexed = [];
        foreach ($answers as $answer) {
            $indexed[implode('|', [
                $answer->getQuestionKind()->value,
                $answer->getQuestionKey(),
                $answer->getPlaceKind()?->value,
                $answer->getPlaceId(),
                $answer->getTarget(),
                $answer->getPositionId(),
                $answer->getTier(),
            ])] = $answer;
        }

        return $indexed;
    }

    /** @param array<int, string> $names */
    private static function subjectOf(PermissionAnswer $answer, array $names): string
    {
        $tier = null === $answer->getTier() ? null : TeamRoleEnum::tryFrom($answer->getTier());
        if (null !== $tier) {
            return $tier->label();
        }

        return null === $answer->getPositionId() ? 'no position' : ($names[$answer->getPositionId()] ?? 'a position no longer there');
    }
}
