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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Contracts\Kpi\DepartmentRef;

/**
 * A MODULE THAT REPORTS A FIGURE, played by a fixture — the surveys module of
 * {@see DeclaringModuleProvider}, wearing the KPI seam as an installed module
 * bundle does.
 *
 * It answers one known figure and one unknown one, because both have to reach
 * the strip: the second is the dashed slot that proves an unmeasured figure is
 * not drawn as a zero.
 */
final class SurveyKpiProvider implements DepartmentKpiProviderInterface
{
    public function moduleSlug(): string
    {
        return 'surveys';
    }

    public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
    {
        return [
            new DepartmentKpi(
                key: 'surveys',
                label: 'Surveys logged',
                moduleSlug: 'surveys',
                moduleName: 'Surveys',
                value: 88.0,
                previous: 79.0,
                spark: [64.0, 71.0, 79.0, 88.0],
                caption: 'Surveys module',
            ),
            new DepartmentKpi(
                key: 'coverage',
                label: 'Coverage',
                moduleSlug: 'surveys',
                moduleName: 'Surveys',
                value: null,
                unit: DepartmentKpi::SHARE,
            ),
        ];
    }
}
