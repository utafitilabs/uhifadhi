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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsCatalogue;
use Uhifadhi\Contracts\Settings\SettingDefinition;
use Uhifadhi\Contracts\Settings\SettingDefinitionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingType;

/**
 * EVERY SETTING THE INSTALLATION KNOWS, by owner. Settings draws one page per
 * owner, so the catalogue answers per owner in the owners' own order, and two
 * sources declaring one key is a mistake surfaced at once.
 */
final class SettingsCatalogueTest extends TestCase
{
    public function testDefinitionsAreFoundByKeyAndListedPerOwnerInOrder(): void
    {
        $catalogue = new SettingsCatalogue([self::source(
            self::number('roster.late_threshold', 'roster', 2),
            self::number('roster.catchment', 'roster', 1),
            self::number('core.ping_interval', 'core', 1),
        )]);

        self::assertSame('roster', $catalogue->get('roster.late_threshold')->owner);
        self::assertSame(['roster.catchment', 'roster.late_threshold'], array_map(
            static fn (SettingDefinition $d): string => $d->key, $catalogue->forOwner('roster'),
        ));
        self::assertSame([], $catalogue->forOwner('patrols'), 'A module without settings has none.');
        self::assertTrue($catalogue->has('core.ping_interval'));
        self::assertFalse($catalogue->has('core.nothing'));
    }

    public function testTwoSourcesDeclaringOneKeyIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new SettingsCatalogue([
            self::source(self::number('roster.late_threshold', 'roster', 1)),
            self::source(self::number('roster.late_threshold', 'roster', 2)),
        ])->get('roster.late_threshold');
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SettingsCatalogue([])->get('roster.nothing');
    }

    private static function number(string $key, string $owner, int $position): SettingDefinition
    {
        return new SettingDefinition($key, $owner, 'Group', 'Label', 'What it means.', SettingType::Number, 1,
            SettingDepth::Area, min: 0, position: $position);
    }

    private static function source(SettingDefinition ...$definitions): SettingDefinitionSourceInterface
    {
        return new readonly class(array_values($definitions)) implements SettingDefinitionSourceInterface {
            /** @param list<SettingDefinition> $definitions */
            public function __construct(private array $definitions)
            {
            }

            public function settingDefinitions(): iterable
            {
                return $this->definitions;
            }
        };
    }
}
