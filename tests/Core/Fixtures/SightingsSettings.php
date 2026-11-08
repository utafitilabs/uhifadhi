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

namespace Uhifadhi\Core\Tests\Core\Fixtures;

use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * THE SIGHTINGS MODULE'S SETTINGS: a choice with a meaning per choice and a
 * note, and a figure — the two kinds of value a module's settings page draws.
 */
final class SightingsSettings implements SettingDefinitionSourceInterface
{
    public const string UNSURE = 'sightings.unsure_species';
    public const string MERGE_WITHIN = 'sightings.merge_within';

    public function settingDefinitions(): iterable
    {
        yield new SettingDefinition(self::UNSURE, SightingsModuleProvider::SLUG, 'Recording',
            'A sighting of an unsure species', 'What happens when the species is not known.',
            SettingType::Choice, 'ask', SettingDepth::Area,
            choices: ['keep', 'ask', 'refuse'], position: 1,
            choiceLabels: ['keep' => 'Keep', 'ask' => 'Ask', 'refuse' => 'Refuse'],
            choiceMeanings: ['keep' => 'kept as unknown', 'ask' => 'kept once the recorder confirms it', 'refuse' => 'not kept until a species is named'],
            note: 'A sighting from a phone is always kept.');
        yield new SettingDefinition(self::MERGE_WITHIN, SightingsModuleProvider::SLUG, 'Recording',
            'Merge if within', 'Two sightings this close are one.',
            SettingType::Number, 200, SettingDepth::Area,
            unit: 'm', min: 0, max: 5000, position: 2);
    }
}
