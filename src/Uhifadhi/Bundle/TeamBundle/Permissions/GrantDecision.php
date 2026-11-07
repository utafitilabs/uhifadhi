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

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\GrantQuestionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\StepOutcomeEnum;
use Uhifadhi\Bundle\TeamBundle\Security\GrantVoter;
use Uhifadhi\Contracts\Access\WholeOrganization;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * ONE CHECK, AS THE GRANTVOTER TOOK IT: every question it asked in order,
 * stopping where it stopped, and the facts each answer was read from.
 *
 * THE VOTER WRITES IT AND THE WALK READS IT. {@see GrantVoter::decide()} records
 * each step as it is taken and votes from the result; the Check page prints
 * the same steps. There is no second account of the rules beside the voter
 * to drift away from it.
 */
final class GrantDecision
{
    /** @var list<GrantStep> */
    private array $steps = [];

    private bool $allowed = false;

    private ?Position $position = null;

    private ?GrantJustification $justification = null;

    private ?string $lifts = null;

    private ?string $module = null;

    /** @var list<Department> */
    private array $running = [];

    private ?Department $servedBy = null;

    public function __construct(
        public readonly User $person,
        public readonly string $pair,
        public readonly AreaInterface|WholeOrganization|null $subject,
    ) {
    }

    public function pass(GrantQuestionEnum $question): static
    {
        $this->steps[] = new GrantStep($question, StepOutcomeEnum::Passed);

        return $this;
    }

    public function skip(GrantQuestionEnum $question): static
    {
        $this->steps[] = new GrantStep($question, StepOutcomeEnum::NotAsked);

        return $this;
    }

    /** The voter stops here: the answer is no. */
    public function refuse(GrantQuestionEnum $question, ?string $reason = null): static
    {
        $this->steps[] = new GrantStep($question, StepOutcomeEnum::Refused, $reason);
        $this->allowed = false;

        return $this;
    }

    /** Every question that arose answered yes. */
    public function allow(): static
    {
        $this->allowed = true;

        return $this;
    }

    public function readPosition(?Position $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function readException(string $lifts, ?GrantJustification $justification): static
    {
        $this->lifts = $lifts;
        $this->justification = $justification;

        return $this;
    }

    /** @param list<Department> $running */
    public function readDepartments(string $module, array $running, ?Department $servedBy): static
    {
        $this->module = $module;
        $this->running = $running;
        $this->servedBy = $servedBy;

        return $this;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /** @return list<GrantStep> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** The step the voter stopped at, or null when it allowed. */
    public function getRefusal(): ?GrantStep
    {
        $last = $this->steps[\count($this->steps) - 1] ?? null;

        return null !== $last && StepOutcomeEnum::Refused === $last->outcome ? $last : null;
    }

    /** @return list<string> the reasons the voter hands the decision manager */
    public function getReasons(): array
    {
        $reasons = [];
        foreach ($this->steps as $step) {
            if (null !== $step->reason) {
                $reasons[] = $step->reason;
            }
        }

        return $reasons;
    }

    public function getPlacement(): ?Placement
    {
        return $this->person->getPlacement();
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function getJustification(): ?GrantJustification
    {
        return $this->justification;
    }

    /** The rule the pair lifts, when it lifts one. */
    public function getLifts(): ?string
    {
        return $this->lifts;
    }

    /** The module the concern belongs to, when the department question arose. */
    public function getModule(): ?string
    {
        return $this->module;
    }

    /** @return list<Department> the departments that run the module */
    public function getRunning(): array
    {
        return $this->running;
    }

    /** The department of theirs that runs it, when one does. */
    public function getServedBy(): ?Department
    {
        return $this->servedBy;
    }
}
