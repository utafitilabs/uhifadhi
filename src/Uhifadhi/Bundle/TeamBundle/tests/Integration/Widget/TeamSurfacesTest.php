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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceRegistry;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Bundle\TeamBundle\Widget\DepartmentWidgets;

/**
 * WHICH TEAM SCREENS ARE WIDGET SURFACES, AND WHICH ARE NOT.
 *
 * WIDGETS LIVE ON DATA SURFACES ONLY (owner, 2026-09-22). A register is app
 * mechanics — one table, one shape — and a configure page is a form; neither
 * is a canvas somebody arranges. The departments overview is a reading, and
 * it is the one surface this bundle contributes.
 *
 * A CATALOGUE IS CODE, NOT INPUT: a preset naming a widget the surface does
 * not ship, or a width it does not offer, refuses to boot. That is the
 * framework agreeing, and it is why constructing the catalogue is itself
 * worth a test.
 */
final class TeamSurfacesTest extends IntegrationTestCase
{
    public function testTheDepartmentsSurfaceIsRegisteredAndTheRegistersAreNot(): void
    {
        $registry = static::getContainer()->get('test_public.'.WidgetSurfaceRegistry::class);
        self::assertInstanceOf(WidgetSurfaceRegistry::class, $registry);

        self::assertTrue($registry->has(DepartmentWidgets::SURFACE));
        self::assertFalse($registry->has('team'), 'The people register is one table, not a canvas.');
        self::assertFalse($registry->has('team_positions'), 'The positions register is one table, not a canvas.');
    }

    public function testTheSurfaceKeyIsStable(): void
    {
        // A stored layout is keyed by this string; changing it orphans every
        // arrangement anybody has made.
        self::assertSame('departments', DepartmentWidgets::SURFACE);
    }

    /** The catalogue constructs, and every preset it ships names its trade-off. */
    public function testEveryPresetCarriesItsTradeOffLine(): void
    {
        $presets = new DepartmentWidgets()->catalog()->builtins();

        self::assertNotSame([], $presets);
        foreach ($presets as $preset) {
            self::assertInstanceOf(WidgetPreset::class, $preset);
            self::assertNotSame('', trim($preset->description));
        }
    }
}
