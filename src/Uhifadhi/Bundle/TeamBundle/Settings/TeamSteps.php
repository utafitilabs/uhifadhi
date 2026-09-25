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

namespace Uhifadhi\Bundle\TeamBundle\Settings;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamPostingsController;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Contracts\Settings\SettingsStep;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;

/**
 * THE TWO STEPS THAT ARE ABOUT PEOPLE — and where this installation is on
 * each.
 *
 * POSTING IS A FACT, COMPOSING IS A PERMISSION, and the rows say so: where
 * somebody works and what they may do are different questions with different
 * answers, and an installation can be finished with one and not the other.
 *
 * EVERGREEN. Neither row says whether the installation has begun; each states
 * where it has got to, so a team that grows by four next month has four to
 * post again and the page says so the same day.
 *
 * ROUTE-TOLERANT, because the addresses belong to the application.
 */
final readonly class TeamSteps implements SettingsStepSourceInterface
{
    /** After the ground: a person is posted somewhere, so somewhere comes first. */
    public const int POSITION = 30;

    public function __construct(
        private PeopleReading $people,
        private PositionRepository $positions,
        private UrlGeneratorInterface $urls,
        private Door $door,
    ) {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsSteps(): iterable
    {
        // EACH STEP ASKS WHAT THE PAGE IT LINKS TO ENFORCES.
        if ($this->door->opens(TeamController::READ)) {
            yield from $this->postPeople();
        }
        if ($this->door->opens(PositionController::READ)) {
            yield from $this->composeAPosition();
        }
    }

    /** @return iterable<SettingsStep> */
    private function postPeople(): iterable
    {
        $active = $this->people->active();
        $posted = $this->people->posted();

        yield new SettingsStep(
            'post-people',
            'Post people',
            'who works where — a fact, not a permission',
            match (true) {
                0 === $active => 'nobody to post yet',
                null === $posted => 'nothing here owns the ground to post anybody to',
                default => \sprintf('%d of %d posted', $posted, $active),
            },
            $this->address(TeamPostingsController::POSTINGS),
            // UNANSWERABLE IS NOT DONE. An installation with no area package
            // cannot post anybody, and a tick there would report a step
            // nobody could have taken.
            0 !== $active && null !== $posted && $posted === $active,
            null === $posted || $posted === $active ? null : \sprintf('%d to post', $active - $posted),
        );
    }

    /** @return iterable<SettingsStep> */
    private function composeAPosition(): iterable
    {
        $composed = \count($this->positions->findAllOrdered());

        yield new SettingsStep(
            'compose-a-position',
            'Compose a position',
            'what a person may do, and where',
            0 === $composed ? 'none composed' : \sprintf('%d composed', $composed),
            $this->address(PositionController::REGISTER),
            $composed > 0,
            // NOTHING IS GRANTED BY DEFAULT, READ INCLUDED, so an
            // installation with no position is one where nobody can do
            // anything — which is worth saying in the row rather than
            // leaving as an empty cell.
            0 === $composed ? 'nobody may do anything yet' : null,
        );
    }

    private function address(string $route): ?string
    {
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
