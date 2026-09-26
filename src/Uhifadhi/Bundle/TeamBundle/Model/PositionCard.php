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

namespace Uhifadhi\Bundle\TeamBundle\Model;

use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * ONE POSITION, AS EVERY SURFACE READS IT — the register's card, the
 * record's fact band, and the configure page's preview are three renderings
 * of this one derivation rather than three counts of the same rows.
 *
 * THE FIGURES ARE COUNTED ONCE. "6 of 8 seats · 2 free · 12 of 21 concerns ·
 * 2 sensitive" appears on three screens, and a template that totalled it
 * itself would total it differently on the third.
 */
final readonly class PositionCard
{
    /**
     * @param list<GrantGroup>       $groups     one per declarer, in declaration order
     * @param list<HolderRow>        $holders    everybody standing in it, by name
     * @param array<string,int>      $verbTotals verb value => how many concerns hold it
     * @param list<RuleExceptionRow> $exceptions the grants that lift a rule, drawn apart from the matrix
     */
    public function __construct(
        public Position $position,
        public array $groups,
        public array $holders,
        public int $concernsGranted,
        public int $concernsDeclared,
        public int $sensitiveGranted,
        public array $verbTotals,
        public array $exceptions = [],
    ) {
    }

    public function name(): string
    {
        return (string) $this->position->getName();
    }

    public function uuid(): string
    {
        return (string) $this->position->getUuidString();
    }

    /**
     * THE TWO-LETTER MARK a card and a record header wear: the initials of
     * the first two words, or the first two letters of a one-word name —
     * "Head of Department" is HD and "Sergeant" is SE.
     */
    public function mark(): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($this->name())) ?: []));
        if ([] === $words) {
            return '?';
        }

        $mark = 1 === \count($words)
            ? mb_substr($words[0], 0, 2)
            : mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1);

        return mb_strtoupper($mark);
    }

    public function seatsFilled(): int
    {
        return \count($this->holders);
    }

    /** Null is unlimited. */
    public function seats(): ?int
    {
        return $this->position->getSeatCount();
    }

    /** Null is unlimited — a position with unlimited seats has no number of free ones. */
    public function seatsFree(): ?int
    {
        $seats = $this->seats();

        return null === $seats ? null : max(0, $seats - $this->seatsFilled());
    }

    public function isFull(): bool
    {
        return 0 === $this->seatsFree();
    }

    public function grantsNothing(): bool
    {
        return 0 === $this->concernsGranted;
    }

    /** @return list<ScopeKind> the kinds a holder of this position may be placed at */
    public function allowedKinds(): array
    {
        return $this->position->getAllowedKinds();
    }

    /**
     * WHERE A HOLDER OF THIS POSITION MAY BE PLACED, as the record's subline
     * says it — a phrase, not a definition.
     *
     * The kinds themselves publish {@see ScopeKind::reach()}, which explains
     * what a scope MEANS when a concern is granted at it ("Everything.") and
     * is the right sentence in the grants matrix and the wrong one here: a
     * subline saying "placed Everything." is the reach of a scope printed
     * where the placement belongs. So the phrase is composed here, in the
     * reading the three position screens share, and the enum keeps its own
     * words for its own job.
     */
    public function placedWhere(): string
    {
        $kinds = $this->allowedKinds();
        $organization = \in_array(ScopeKind::Organization, $kinds, true);
        $area = \in_array(ScopeKind::Area, $kinds, true);

        return match (true) {
            $organization && $area => 'placed in one area or across the organization',
            $organization => 'placed across the organization',
            $area => 'placed in one area',
            // A POSITION THAT ALLOWS NEITHER CANNOT BE GIVEN TO ANYBODY, and
            // saying so is more use than an empty fragment.
            default => 'placed nowhere — it allows no kind of placement',
        };
    }

    /** @return list<GrantGroup> the groups this position holds something in */
    public function grantedGroups(): array
    {
        return array_values(array_filter($this->groups, static fn (GrantGroup $g): bool => $g->granted() > 0));
    }

    /**
     * THE FOOT'S SENTENCE, as pairs a template writes in order: "reads 12 ·
     * records 4 · manages 7 · exports 1". A verb nothing is held on is
     * absent rather than printed as a zero.
     *
     * @return array<string, int> the verb's third-person word => the count
     */
    public function summary(): array
    {
        $words = [
            Verb::Read->value => 'reads',
            Verb::Record->value => 'records',
            Verb::Manage->value => 'manages',
            Verb::Configure->value => 'configures',
            Verb::Delete->value => 'deletes',
            Verb::Export->value => 'exports',
        ];

        $summary = [];
        foreach ($words as $verb => $word) {
            $count = $this->verbTotals[$verb] ?? 0;
            if ($count > 0) {
                $summary[$word] = $count;
            }
        }

        return $summary;
    }
}
