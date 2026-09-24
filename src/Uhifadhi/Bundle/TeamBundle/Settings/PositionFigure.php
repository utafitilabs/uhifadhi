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

use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;

/**
 * HOW MANY POSITIONS ARE COMPOSED, AND HOW MANY OF THEM GRANT ANYTHING.
 *
 * THE SPLIT IS THE POINT OF THE CARD. Nothing is granted by default, read
 * included, so a position that grants nothing is a title somebody holds and
 * cannot act under — which is a real state (a seat being prepared, a title
 * kept for the record) and an easy mistake to leave standing. A bare count
 * would report the mistake and the intention identically.
 */
final readonly class PositionFigure implements SettingsFigureSourceInterface
{
    /** Last of the four: what a person may do, after who they are. */
    public const int POSITION = 40;

    public function __construct(private PositionRepository $positions)
    {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsFigures(): iterable
    {
        $composed = 0;
        $granting = 0;
        foreach ($this->positions->findAllOrdered() as $position) {
            ++$composed;
            if ([] !== $position->getGrantValues()) {
                ++$granting;
            }
        }

        yield new SettingsFigure(
            'positions',
            'Positions',
            (string) $composed,
            caption: 0 === $composed
                ? 'none composed yet'
                : \sprintf('%d grant something', $granting),
            warning: $composed > $granting ? \sprintf('%d grant nothing', $composed - $granting) : null,
        );
    }
}
