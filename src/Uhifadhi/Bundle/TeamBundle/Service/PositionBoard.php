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
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Model\GrantGroup;
use Uhifadhi\Bundle\TeamBundle\Model\GrantRow;
use Uhifadhi\Bundle\TeamBundle\Model\HolderRow;
use Uhifadhi\Bundle\TeamBundle\Model\PositionCard;
use Uhifadhi\Bundle\TeamBundle\Model\RuleExceptionRow;
use Uhifadhi\Bundle\TeamBundle\Repository\GrantJustificationRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\ConcernInterface;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * WHAT EVERY POSITION SCREEN READS — the register's cards, the record's
 * matrix and fact band, and the configure page's editor, all derived here.
 *
 * THREE SCREENS, ONE DERIVATION. "12 of 21 concerns · 2 sensitive" is on the
 * register card's head, in the record's fact band and in the configure
 * page's bar; the moment a template counts it for itself the three disagree
 * and nobody notices until an administrator does.
 *
 * WHAT THERE IS TO GRANT IS NOT FIXED. The team's four concerns are this
 * bundle's; the rest arrive with a module and leave with it. So the matrix
 * is read from the declarations every time rather than from a list kept by
 * hand, and a pair a position still holds that nothing declares any more is
 * NOT here: it is an orphan, drawn by the screen that can still revoke it.
 */
final readonly class PositionBoard
{
    public function __construct(
        private PositionRepository $positions,
        private UserRepository $users,
        private ConcernCatalogue $catalogue,
        private ?GrantJustificationRepository $justifications = null,
    ) {
    }

    /**
     * EVERY POSITION, RETIRED ONES INCLUDED, by name.
     *
     * A retired one is in the register and absent from every picker: an
     * administrator who is told a name is taken has to be able to find the
     * row that is holding it.
     *
     * @return list<PositionCard>
     */
    public function register(): array
    {
        return array_map($this->card(...), $this->positions->findAllOrdered());
    }

    public function card(Position $position): PositionCard
    {
        $held = $position->getGrantValues();
        $groups = [];
        $granted = 0;
        $sensitive = 0;
        $verbTotals = [];

        $exceptions = [];
        $current = null;

        foreach ($this->catalogue->grouped() as $declarer => $concerns) {
            $rows = [];
            $module = null;

            foreach ($concerns as $concern) {
                $row = $this->row($concern, $position, $held);

                // AN EXCEPTION TO A RULE IS NEVER A ROW OF THE MATRIX. It is
                // drawn on its own card; it still counts as granted and as
                // sensitive, because it is both.
                if (null !== $concern->lifts()) {
                    $current ??= null === $position->getId() ? [] : ($this->justifications?->findCurrentByPosition($position) ?? []);
                    foreach ($row->verbs as $verb) {
                        $pair = (string) Grant::of($concern->key(), $verb);
                        $exceptions[] = new RuleExceptionRow(
                            key: $concern->key(),
                            pair: $pair,
                            label: $concern->label(),
                            description: $concern->description(),
                            lifts: $concern->lifts(),
                            held: $row->cells[$verb->value] ?? false,
                            current: $current[$pair] ?? null,
                        );
                    }
                    if ($row->isGranted()) {
                        ++$granted;
                        ++$sensitive;
                    }

                    continue;
                }
                $rows[] = $row;
                $module ??= $concern->moduleSlug();

                if (!$row->isGranted()) {
                    continue;
                }

                ++$granted;
                if ($row->sensitive) {
                    ++$sensitive;
                }

                foreach ($row->heldVerbs() as $verb) {
                    $verbTotals[$verb->value] = ($verbTotals[$verb->value] ?? 0) + 1;
                }
            }

            if ([] !== $rows) {
                $groups[] = new GrantGroup($declarer, $module, $rows);
            }
        }

        // THE CORE'S GROUPS FIRST, the widest first among them, then every
        // module's in the order the installation loaded them — the order the
        // design draws: what the platform itself asks about before what a
        // module added. The sort is stable, so nothing else moves.
        usort($groups, static fn (GrantGroup $a, GrantGroup $b): int => match (true) {
            $a->isCore() && $b->isCore() => $b->total() <=> $a->total(),
            $a->isCore() !== $b->isCore() => $b->isCore() <=> $a->isCore(),
            default => 0,
        });

        return new PositionCard(
            position: $position,
            groups: $groups,
            holders: $this->holders($position),
            concernsGranted: $granted,
            concernsDeclared: \count($this->catalogue->all()),
            sensitiveGranted: $sensitive,
            verbTotals: $verbTotals,
            exceptions: $exceptions,
        );
    }

    /**
     * THE POSITION'S EXCEPTIONS WITH THEIR WHOLE HISTORY — the record's card.
     *
     * @return list<RuleExceptionRow>
     */
    public function exceptionsWithHistory(Position $position): array
    {
        $history = null === $position->getId() ? [] : ($this->justifications?->findByPositionNewestFirst($position) ?? []);

        return array_map(
            static fn (RuleExceptionRow $row): RuleExceptionRow => new RuleExceptionRow(
                key: $row->key,
                pair: $row->pair,
                label: $row->label,
                description: $row->description,
                lifts: $row->lifts,
                held: $row->held,
                current: $row->current,
                history: array_values(array_filter($history, static fn (GrantJustification $j): bool => $j->getPair() === $row->pair)),
            ),
            $this->card($position)->exceptions,
        );
    }

    /**
     * EVERYBODY STANDING IN A POSITION, by name — read-only wherever it is
     * drawn, because a position is given on the person's own record.
     *
     * @return list<HolderRow>
     */
    public function holders(Position $position): array
    {
        return array_map(
            static function (User $person): HolderRow {
                $first = mb_substr((string) $person->getFirstName(), 0, 1);
                $last = mb_substr((string) $person->getLastName(), 0, 1);

                return new HolderRow(
                    uuid: (string) $person->getUuidString(),
                    name: $person->getFullName(),
                    initials: mb_strtoupper($first.$last),
                    where: $person->getPlacement()?->groundLabel() ?? 'not placed',
                    since: $person->getPositionSince(),
                );
            },
            $this->users->findActiveHolders($position),
        );
    }

    /**
     * PAIRS NOTHING INSTALLED DECLARES ANY MORE, still held by this position.
     *
     * Pruned, not purged: removing them on the module's way out would
     * silently rewrite what an administrator granted, so they stay in the
     * JSON, stop resolving, and are drawn where they can still be revoked.
     *
     * @return list<string>
     */
    public function orphans(Position $position): array
    {
        $declared = $this->catalogue->pairs();

        return array_values(array_filter(
            $position->getGrantValues(),
            static fn (string $pair): bool => !\in_array($pair, $declared, true),
        ));
    }

    /**
     * ONE ROW OF THE MATRIX.
     *
     * A CELL EXISTS ONLY WHERE THE CONCERN DECLARES THE VERB, and a scope
     * chip only where the concern offers the kind: the position's allowed
     * kinds say which of those are lit, because a position placed only at an
     * area cannot reach a concern's organization scope.
     *
     * @param list<string> $held
     */
    private function row(ConcernInterface $concern, Position $position, array $held): GrantRow
    {
        $verbs = [];
        $cells = [];
        foreach (Verb::cases() as $verb) {
            if (!$concern->supports($verb)) {
                continue;
            }

            $verbs[] = $verb;
            $cells[$verb->value] = \in_array((string) Grant::of($concern->key(), $verb), $held, true);
        }

        $scopeKinds = [];
        foreach (ScopeKind::cases() as $kind) {
            if ($concern->offers($kind)) {
                $scopeKinds[$kind->value] = ScopeKind::Own === $kind || $position->allows($kind);
            }
        }

        return new GrantRow(
            key: $concern->key(),
            label: $concern->label(),
            description: $concern->description(),
            sensitive: $concern->isSensitive(),
            ownWords: $concern->ownWords(),
            verbs: $verbs,
            cells: $cells,
            scopeKinds: $scopeKinds,
        );
    }
}
