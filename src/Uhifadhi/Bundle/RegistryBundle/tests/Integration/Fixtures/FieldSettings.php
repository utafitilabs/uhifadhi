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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * Three settings of three depths, from a module the test calls "field" — what a
 * real module tags, made small enough to read in the test that uses it.
 */
final readonly class FieldSettings implements SettingDefinitionSourceInterface
{
    public function settingDefinitions(): iterable
    {
        yield new SettingDefinition('field.late_threshold', 'field', 'Field', 'Late threshold',
            'A check-in later than this counts as late.', SettingType::Number, 15, SettingDepth::Department,
            unit: 'min', min: 1, max: 120);
        yield new SettingDefinition('field.announce', 'field', 'Field', 'Announce vacancies',
            'An unfilled watch is announced to the station.', SettingType::Toggle, true, SettingDepth::Area);
        yield new SettingDefinition('field.currency', 'field', 'Field', 'Currency',
            'Fines are recorded in.', SettingType::Choice, 'TZS', SettingDepth::Organization, choices: ['TZS', 'KES']);
    }
}
