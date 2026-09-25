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

use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;

/**
 * HOW MANY PEOPLE THIS INSTALLATION IS FOR, AND HOW MANY OF THEM ARE POSTED.
 *
 * ACTIVE, BECAUSE THAT IS THE NUMBER EVERY OTHER FIGURE IS ABOUT. A closed
 * account still has records attached to it and still appears in a history; it
 * does not appear on a watch, in a patrol or in a queue, so a headline count
 * that included it would over-state the organization the rest of the product
 * is reporting on.
 *
 * AND THE CAPTION IS WHERE THEY WORK, because that is what somebody setting
 * an installation up is actually short of: an account nobody posted is a
 * person the ground does not know about. Where nothing in this installation
 * owns the ground the question has no answer, and the caption says so rather
 * than reading nought posted.
 *
 * NOT SPLIT BY DEPARTMENT, though that is what somebody eventually wants. A
 * caption naming two departments is right for an installation with two and
 * wrong for one with nine, and which two would be this card choosing on
 * somebody's behalf.
 */
final readonly class PeopleFigure implements SettingsFigureSourceInterface
{
    /** After the areas and what runs in them: the places, then the people. */
    public const int POSITION = 30;

    public function __construct(
        private PeopleReading $reading,
        private Door $door,
    ) {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsFigures(): iterable
    {
        // COUNTING THE PEOPLE IS READING THE DIRECTORY.
        if (!$this->door->opens(TeamController::READ)) {
            return;
        }

        $active = $this->reading->active();
        $posted = $this->reading->posted();

        yield new SettingsFigure(
            'people',
            'People',
            (string) $active,
            caption: match (true) {
                0 === $active => 'nobody has an account here yet',
                null === $posted => 'nothing here owns the ground, so none of them is posted anywhere',
                default => \sprintf('%d posted', $posted),
            },
            warning: null !== $posted && $active > $posted ? \sprintf('%d not posted', $active - $posted) : null,
        );
    }
}
