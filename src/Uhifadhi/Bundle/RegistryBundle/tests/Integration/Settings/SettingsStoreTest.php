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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Settings;

use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingChange;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsRefused;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsWriter;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * THE VALUE IN FORCE, AND WHO MAY CHANGE IT HOW. Only Super Admins and Admins
 * write, in Settings › Configure, and nothing is kept until the save bar's
 * Save — so the writer takes a reviewed batch and keeps all of it or none.
 * The reader answers department, else area, else organization, else default.
 */
final class SettingsStoreTest extends InstallationTestCase
{
    private const string NORTH = '0199a000-0000-7000-8000-000000000001';
    private const string SOUTH = '0199a000-0000-7000-8000-000000000002';
    private const string TOURISM = '0199a000-0000-7000-8000-0000000000a1';
    private const string ECOLOGY = '0199a000-0000-7000-8000-0000000000a2';

    protected function setUp(): void
    {
        $this->install([]);
    }

    public function testNothingStoredReadsTheDefault(): void
    {
        self::assertSame(15, $this->reader()->value('field.late_threshold', self::NORTH, self::TOURISM));
        self::assertTrue($this->reader()->value('field.announce'));
        self::assertSame('TZS', $this->reader()->value('field.currency', self::NORTH));
    }

    /**
     * THE ORGANIZATION'S VALUE MOVES EVERY PLACE WITHOUT ITS OWN; a custom
     * value holds for its place only, and a department without one follows its
     * area's.
     */
    public function testTheValueInForceIsTheNearestOneSet(): void
    {
        $this->writer()->apply([
            new SettingChange('field.late_threshold', SettingDepth::Organization, null, 20),
            new SettingChange('field.late_threshold', SettingDepth::Area, self::SOUTH, 25),
            new SettingChange('field.late_threshold', SettingDepth::Department, self::TOURISM, 30),
        ], 'admin@example.test');

        self::assertSame(20, $this->reader()->value('field.late_threshold', self::NORTH), 'North has no custom value.');
        self::assertSame(25, $this->reader()->value('field.late_threshold', self::SOUTH), 'South has its own.');
        self::assertSame(25, $this->reader()->value('field.late_threshold', self::SOUTH, self::ECOLOGY), 'A department without its own follows its area.');
        self::assertSame(30, $this->reader()->value('field.late_threshold', self::SOUTH, self::TOURISM), 'Its own, wherever it works.');
        self::assertSame(20, $this->reader()->value('field.late_threshold'), 'No place: the organization’s.');
    }

    public function testResettingACustomValueFallsBackToTheLevelAbove(): void
    {
        $this->writer()->apply([new SettingChange('field.late_threshold', SettingDepth::Area, self::SOUTH, 25)], 'admin@example.test');
        $this->writer()->apply([new SettingChange('field.late_threshold', SettingDepth::Area, self::SOUTH, null)], 'admin@example.test');

        self::assertSame(15, $this->reader()->value('field.late_threshold', self::SOUTH));
        self::assertSame(0, $this->em()->getRepository(SettingValue::class)->count([]));
    }

    public function testSettingAValueAgainKeepsOneRowAndRecordsWhoAndWhen(): void
    {
        $this->writer()->apply([new SettingChange('field.late_threshold', SettingDepth::Organization, null, 20)], 'first@example.test');
        $this->writer()->apply([new SettingChange('field.late_threshold', SettingDepth::Organization, null, 22)], 'second@example.test');

        $rows = $this->em()->getRepository(SettingValue::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(22, $rows[0]->getValue());
        self::assertSame('second@example.test', $rows[0]->getSetBy());
        self::assertLessThan(60, time() - $rows[0]->getSetAt()->getTimestamp(), 'Set just now.');
    }

    /**
     * @param list<SettingChange> $batch
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBatches')]
    public function testABatchWithOneBadChangeIsRefusedWhole(array $batch, string $why): void
    {
        try {
            $this->writer()->apply($batch, 'admin@example.test');
            self::fail($why);
        } catch (SettingsRefused $refused) {
            self::assertNotSame('', $refused->getMessage());
        }

        self::assertSame(0, $this->em()->getRepository(SettingValue::class)->count([]), 'Nothing of the batch is kept.');
    }

    /**
     * @return \Generator<string, array{list<SettingChange>, string}>
     */
    public static function refusedBatches(): \Generator
    {
        $good = new SettingChange('field.late_threshold', SettingDepth::Organization, null, 20);

        yield 'unknown key' => [[$good, new SettingChange('field.nothing', SettingDepth::Organization, null, 1)], 'No definition, no value.'];
        yield 'outside the limits' => [[$good, new SettingChange('field.late_threshold', SettingDepth::Area, self::NORTH, 500)], '1–120 min.'];
        yield 'wrong shape' => [[$good, new SettingChange('field.announce', SettingDepth::Organization, null, 'yes')], 'A switch takes on or off.'];
        yield 'below its depth' => [[$good, new SettingChange('field.announce', SettingDepth::Department, self::TOURISM, false)], 'Announce goes no deeper than an area.'];
        yield 'one value for the organization' => [[$good, new SettingChange('field.currency', SettingDepth::Area, self::NORTH, 'KES')], 'Currency is one value.'];
        yield 'a place without its uuid' => [[$good, new SettingChange('field.late_threshold', SettingDepth::Area, null, 20)], 'An area value names its area.'];
        yield 'the organization with a place' => [[$good, new SettingChange('field.late_threshold', SettingDepth::Organization, self::NORTH, 20)], 'The organization is not a place.'];
    }

    public function testTheCustomValuesOfASettingReadAsOneTable(): void
    {
        $this->writer()->apply([
            new SettingChange('field.late_threshold', SettingDepth::Area, self::SOUTH, 25),
            new SettingChange('field.late_threshold', SettingDepth::Department, self::TOURISM, 30),
        ], 'admin@example.test');

        $table = $this->writer()->valuesOf('field.late_threshold');

        self::assertNull($table->organization, 'The organization holds the default until someone sets it.');
        self::assertSame([self::SOUTH => 25], $table->areas);
        self::assertSame([self::TOURISM => 30], $table->departments);
    }

    private function writer(): SettingsWriter
    {
        $writer = $this->service('registry.settings.writer');
        \assert($writer instanceof SettingsWriter);

        return $writer;
    }

    private function reader(): SettingsReaderInterface
    {
        $reader = $this->service('registry.settings.reader');
        \assert($reader instanceof SettingsReaderInterface);

        return $reader;
    }
}
