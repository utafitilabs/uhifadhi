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

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Contracts\Settings\AreaRun;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsStep;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;

/**
 * THE THREE STEPS THAT ARE ABOUT THE GROUND — and where this installation is
 * on each.
 *
 * EVERGREEN, NOT A FIRST RUN. Not one of these says whether the installation
 * has begun: each states where it has got to, so the same three rows are
 * worth reading on day one thousand. An installation that registers a fifth
 * area is not finished with zones again until that area has some, and the
 * row says so the same day.
 *
 * THEY COME OFF THE MATRIX, which is the one place the areas, their zones and
 * what runs in each are read together — so the checklist and the table on the
 * Installation tab cannot disagree about how much is set up.
 *
 * ROUTE-TOLERANT, because the addresses belong to the application: a step
 * whose page is not mounted still states where the installation is, and just
 * does not link to it.
 */
final readonly class AreaSteps implements SettingsStepSourceInterface
{
    /** The ground comes first: everything else resolves to a place. */
    public const int POSITION = 10;

    public function __construct(
        private ModuleMatrixSourceInterface $matrix,
        private UrlGeneratorInterface $urls,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function position(): int
    {
        return self::POSITION;
    }

    public function settingsSteps(): iterable
    {
        // ALL THREE ARE READINGS OF THE AREAS, AND ALL THREE LINK TO THEIR
        // REGISTER — so they ask what the register enforces.
        if (!$this->authorization->isGranted(AreaFigure::READ)) {
            return;
        }

        $matrix = $this->matrix->moduleMatrix();
        $areas = \count($matrix->rows);
        $register = $this->address('area_index');

        yield new SettingsStep(
            'add-an-area',
            'Add an area',
            'a place every record resolves to',
            0 === $areas ? 'none registered' : \sprintf('%d registered', $areas),
            $register,
            $areas > 0,
            0 === $areas ? 'start here' : null,
        );

        // AN AREA WITH NO ZONES IS NOT BROKEN, and the row says how many are
        // in that state rather than calling any of them wrong: zones are the
        // ground a patrol, an incident and a station sit in, and an area
        // reports without them.
        $unzoned = \count(array_filter($matrix->rows, static fn (AreaRun $row): bool => 0 === ($row->zones ?? 0)));

        yield new SettingsStep(
            'import-zones',
            'Import zones',
            'the ground a patrol, an incident and a station sit in',
            0 === $areas
                ? 'no area to divide yet'
                : \sprintf('%d of %d %s divided', $areas - $unzoned, $areas, 1 === $areas ? 'area' : 'areas'),
            $register,
            0 !== $areas && 0 === $unzoned,
            0 === $unzoned ? null : \sprintf('%d %s to go', $unzoned, 1 === $unzoned ? 'area' : 'areas'),
        );

        $waiting = $matrix->awaitingSetup();

        yield new SettingsStep(
            'switch-modules-on',
            'Switch modules on',
            'what an area can record and report',
            0 === $areas
                ? 'no area to switch one on in'
                : \sprintf('%d of %d %s running', $matrix->liveAreas(), $areas, 1 === $areas ? 'area' : 'areas'),
            $register,
            0 !== $areas && 0 === $waiting,
            0 === $waiting ? null : \sprintf('%d %s to go', $waiting, 1 === $waiting ? 'area' : 'areas'),
        );
    }

    /** A page the application has not mounted is a step with no link, never a 404. */
    private function address(string $route): ?string
    {
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
