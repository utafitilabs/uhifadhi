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
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;

/**
 * HOW MANY AREAS THIS INSTALLATION HAS, AND HOW MANY OF THEM REPORT.
 *
 * THE SPLIT IS THE WHOLE VALUE OF THE CARD. A count of areas alone says an
 * installation is large; "1 live · 3 awaiting setup" says it is largely
 * unfinished, which is the fact somebody opening this screen is looking for
 * and the one every other figure in the product is silently conditioned on.
 *
 * IT READS THE MATRIX RATHER THAN COUNTING AGAIN. The table below it on the
 * same screen answers exactly this, and two counts made a query apart is how
 * a card comes to disagree with the table under it.
 */
final readonly class AreaFigure implements SettingsFigureSourceInterface
{
    /** FIRST: the places come before what runs in them. */
    public const int POSITION = 10;

    /** Counting the areas is reading them. */
    public const string READ = 'areas.read';

    public function __construct(
        private ModuleMatrixSourceInterface $matrix,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsFigures(): iterable
    {
        if (!$this->authorization->isGranted(self::READ)) {
            return;
        }

        $matrix = $this->matrix->moduleMatrix();
        $areas = \count($matrix->rows);
        $waiting = $matrix->awaitingSetup();

        yield new SettingsFigure(
            'areas',
            'Areas',
            (string) $areas,
            caption: 0 === $areas
                ? 'none registered yet'
                : \sprintf('%d running', $matrix->liveAreas()),
            warning: 0 === $waiting ? null : \sprintf('%d registered, empty', $waiting),
        );
    }
}
