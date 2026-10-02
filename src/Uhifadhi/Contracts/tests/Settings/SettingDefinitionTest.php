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

namespace Uhifadhi\Contracts\Tests\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * A SETTING, AS ITS OWNER DECLARES IT — the core or a module says what can be
 * set, what it means, what it accepts and how far down it may be customised.
 * Settings › Core and Settings › Modules › <module> draw from these, and the
 * store refuses anything a definition does not accept.
 *
 * Pinned here: a definition cannot be built in a state a Configure page would
 * have to guess about, a value is checked against the definition before it is
 * stored, and the depth says exactly which levels may hold a custom value.
 */
final class SettingDefinitionTest extends TestCase
{
    public function testANumberSettingCarriesItsUnitAndLimits(): void
    {
        $late = self::lateThreshold();

        self::assertSame('roster.late_threshold', $late->key);
        self::assertSame(15, $late->default);
        self::assertSame('1–120 min', $late->limits());
        self::assertTrue($late->accepts(20));
        self::assertFalse($late->accepts(0), 'Below the minimum.');
        self::assertFalse($late->accepts(121), 'Above the maximum.');
        self::assertFalse($late->accepts('20'), 'A number setting takes an integer, not text.');
        self::assertFalse($late->accepts(true), 'A number setting takes an integer, not a switch.');
    }

    public function testAToggleTakesOnlyOnOrOff(): void
    {
        $toggle = new SettingDefinition('roster.announce_vacancies', 'roster', 'Roster', 'Announce vacancies',
            'An unfilled watch is announced to the station.', SettingType::Toggle, true, SettingDepth::Area);

        self::assertTrue($toggle->accepts(false));
        self::assertFalse($toggle->accepts(1));
        self::assertNull($toggle->limits());
    }

    public function testAChoiceTakesOnlyOneOfItsChoices(): void
    {
        $currency = new SettingDefinition('incidents.currency', 'incidents', 'Incidents', 'Currency',
            'Fines and compensation are recorded in.', SettingType::Choice, 'TZS', SettingDepth::Organization,
            choices: ['TZS', 'KES', 'USD']);

        self::assertTrue($currency->accepts('KES'));
        self::assertFalse($currency->accepts('EUR'));
        self::assertSame('TZS · KES · USD', $currency->limits());
    }

    /**
     * A NUMBER MAY CARRY DECIMALS — the zone overlap tolerance is 2.5 % — and
     * then takes a decimal value to that many places; a whole-number setting
     * still refuses one.
     */
    public function testANumberWithDecimalsTakesThemToItsPlaces(): void
    {
        $tolerance = new SettingDefinition('core.zone_overlap_tolerance', 'core', 'Zones', 'Zone overlap tolerance',
            'Slivers of overlap below this share of the smaller zone are accepted.', SettingType::Number, 1.0, SettingDepth::Area,
            unit: '%', min: 0, max: 10, decimals: 1);

        self::assertTrue($tolerance->accepts(2.5));
        self::assertTrue($tolerance->accepts(3), 'A whole number is a number with no decimals.');
        self::assertFalse($tolerance->accepts(2.55), 'Two places where one is allowed.');
        self::assertFalse($tolerance->accepts(10.5));
        self::assertFalse(self::lateThreshold()->accepts(15.5), 'A whole-number setting takes no decimals.');
    }

    /**
     * A NUMBER MAY BE LEFT UNSET, when its owner computes the value from
     * something else until an Admin sets one — stale-after is two ping
     * intervals until it is set. The definition then says what unset means,
     * so Settings can print it, and the reader answers null for the owner to
     * compute.
     */
    public function testANumberMayBeLeftUnsetIfItSaysWhatUnsetMeans(): void
    {
        $stale = new SettingDefinition('core.stale_after', 'core', 'Presence', 'Stale after',
            'A position older than this shows as stale.', SettingType::Number, null, SettingDepth::Area,
            unit: 'min', min: 1, max: 2880, unsetMeans: 'two ping intervals');

        self::assertNull($stale->default);
        self::assertSame('two ping intervals', $stale->unsetMeans);
        self::assertTrue($stale->accepts(45));
        self::assertFalse($stale->accepts(null), 'Unset is reached by a reset, never stored.');
    }

    /**
     * THE DEPTH IS HOW FAR DOWN A SETTING MAY BE CUSTOMISED. One value for the
     * organization, or the organization's value with a custom value per area,
     * or per area and per department. The organization always holds a value.
     */
    public function testTheDepthSaysWhichLevelsMayHoldACustomValue(): void
    {
        self::assertTrue(SettingDepth::Organization->reaches(SettingDepth::Organization));
        self::assertFalse(SettingDepth::Organization->reaches(SettingDepth::Area));
        self::assertTrue(SettingDepth::Area->reaches(SettingDepth::Area));
        self::assertFalse(SettingDepth::Area->reaches(SettingDepth::Department));
        self::assertTrue(SettingDepth::Department->reaches(SettingDepth::Department));
        self::assertTrue(SettingDepth::Department->reaches(SettingDepth::Organization));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('refusals')]
    public function testADefinitionAConfigurePageWouldHaveToGuessAboutIsRefused(array $overrides, string $why): void
    {
        $arguments = array_replace([
            'key' => 'roster.late_threshold',
            'owner' => 'roster',
            'group' => 'Roster',
            'label' => 'Late threshold',
            'description' => 'A check-in later than this counts as late.',
            'type' => SettingType::Number,
            'default' => 15,
            'depth' => SettingDepth::Department,
            'unit' => 'min',
            'min' => 1,
            'max' => 120,
            'choices' => [],
            'unsetMeans' => null,
            'decimals' => 0,
        ], $overrides);

        $this->expectException(\InvalidArgumentException::class);

        new SettingDefinition(...$arguments); // @phpstan-ignore argument.type (the cases feed deliberately wrong shapes)

        self::fail($why);
    }

    /**
     * @return \Generator<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): \Generator
    {
        yield 'key without its owner' => [['key' => 'late_threshold'], 'A key is owner.name.'];
        yield 'key under another owner' => [['key' => 'patrols.late_threshold'], 'The key starts with its owner.'];
        yield 'key with capitals' => [['key' => 'roster.LateThreshold'], 'Keys are lower case.'];
        yield 'empty label' => [['label' => ' '], 'The row says nothing.'];
        yield 'empty description' => [['description' => ''], 'The row explains nothing.'];
        yield 'number default not an integer' => [['default' => '15'], 'A number defaults to an integer.'];
        yield 'number default below its minimum' => [['default' => 0], 'The default must be acceptable.'];
        yield 'minimum above maximum' => [['min' => 200], 'Limits that accept nothing.'];
        yield 'toggle with a number default' => [['type' => SettingType::Toggle, 'default' => 1, 'unit' => null, 'min' => null, 'max' => null], 'A toggle defaults to on or off.'];
        yield 'toggle with a unit' => [['type' => SettingType::Toggle, 'default' => true, 'min' => null, 'max' => null], 'A toggle has no unit.'];
        yield 'choice without choices' => [['type' => SettingType::Choice, 'default' => 'TZS', 'unit' => null, 'min' => null, 'max' => null], 'A choice needs its choices.'];
        yield 'decimals on a switch' => [['type' => SettingType::Toggle, 'default' => true, 'unit' => null, 'min' => null, 'max' => null, 'decimals' => 1], 'Only a number has decimals.'];
        yield 'a decimal default on a whole number' => [['default' => 15.5], 'The default must be acceptable.'];
        yield 'unset without saying what it means' => [['default' => null], 'Settings would print nothing for it.'];
        yield 'a switch left unset' => [['type' => SettingType::Toggle, 'default' => null, 'unit' => null, 'min' => null, 'max' => null, 'unsetMeans' => 'something'], 'Only a number may be left unset.'];
        yield 'what unset means, with a default' => [['unsetMeans' => 'two ping intervals'], 'A default and an unset meaning cannot both hold.'];
        yield 'choice default outside its choices' => [['type' => SettingType::Choice, 'default' => 'EUR', 'unit' => null, 'min' => null, 'max' => null, 'choices' => ['TZS']], 'The default must be one of the choices.'];
    }

    private static function lateThreshold(): SettingDefinition
    {
        return new SettingDefinition('roster.late_threshold', 'roster', 'Roster', 'Late threshold',
            'A check-in later than this counts as late.', SettingType::Number, 15, SettingDepth::Department,
            unit: 'min', min: 1, max: 120);
    }
}
