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

namespace Uhifadhi\Bundle\AreaBundle\Settings;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Contracts\Settings\DecisionUrgency;
use Uhifadhi\Contracts\Settings\SettingsDecision;
use Uhifadhi\Contracts\Settings\SettingsDecisionSourceInterface;

/**
 * "SOMEBODY HAS TO SET THOSE AREAS UP" — the section queue's reading of
 * {@see AreaSetup}, and the other half of {@see AreaSetupCheck}.
 *
 * IT IS A WATCH, NOT A NOW. Nobody is waiting and nothing is wrong: an
 * installation part-way through being set up is the ordinary state of a new
 * one, and a queue that shouted about it would train somebody to ignore the
 * queue.
 *
 * NO AGE, AND THAT IS HONEST. How long an area has been empty would be the
 * age of the area, which is not the same thing — nobody undertook to set it
 * up on the day it was registered — so the row states the fact and leaves the
 * clock out of it.
 */
final readonly class AreaSetupDecision implements SettingsDecisionSourceInterface
{
    public function __construct(
        private AreaSetup $setup,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function settingsDecisions(): iterable
    {
        // IT NAMES AREAS, so it asks what reading one asks.
        if (!$this->authorization->isGranted(AreaFigure::READ)) {
            return;
        }

        $waiting = \count($this->setup->waiting());
        if (0 === $waiting) {
            return;
        }

        yield new SettingsDecision(
            'areas-awaiting-setup',
            DecisionUrgency::Watch,
            \sprintf('%d area%s no module installed.', $waiting, 1 === $waiting ? ' has' : 's have'),
            \sprintf('%s registered and empty — nothing reports from %s.', $this->setup->namesWaiting(), 1 === $waiting ? 'it' : 'them'),
            'installation',
            \sprintf('%d of %d areas', $waiting, $this->setup->areas()),
            'since they were registered',
            '—',
        );
    }
}
