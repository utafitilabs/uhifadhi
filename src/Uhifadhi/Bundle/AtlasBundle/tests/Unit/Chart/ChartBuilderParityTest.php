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

namespace Uhifadhi\Bundle\AtlasBundle\Tests\Unit\Chart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder as UxChartBuilder;
use Uhifadhi\Bundle\AtlasBundle\Chart\ChartBuilder;

/**
 * THE BUILDER BUILDS WHAT THE DESIGN BUILDS, key for key.
 *
 * The design workspace draws its chart catalogue through one door whose
 * `build()` turns a statement into a Chart.js configuration exactly as this
 * builder must, so a design can never draw a chart the platform cannot.
 * Each fixture holds one statement — a card of the catalogue, or one that
 * exercises a field the catalogue leaves unstated — and the configuration
 * the door built for it. The statement is read into the PHP models, built
 * here, and the two configurations must be the same: the type, every
 * dataset, every scale, every plugin's options.
 *
 * The fixture also holds the packages the door loads for the statement (the
 * date adapter, an extension kind's package), which the builder must name
 * alike, since the plate loads what the builder names. What the door decides
 * at draw time is not in a fixture: the pixel ratio (the plate sets it, from
 * the screen). A number compares as a number: the door's 2 is the builder's
 * 2.0. The fixtures are written by the door itself, through the generator
 * kept with the private record of the work.
 */
#[CoversClass(ChartBuilder::class)]
final class ChartBuilderParityTest extends TestCase
{
    #[DataProvider('statements')]
    public function testTheBuilderBuildsWhatTheDoorBuildsForTheSameStatement(string $file): void
    {
        $fixture = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['statement'] ?? null);

        $builder = new ChartBuilder(new UxChartBuilder());
        $statement = DoorStatement::chart($fixture['statement']);
        $chart = $builder->chart($statement);
        $built = json_decode((string) json_encode(['type' => $chart->getType(), 'data' => $chart->getData(), 'options' => $chart->getOptions()], \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(self::normal($fixture['built'] ?? null), self::normal($built));
        self::assertSame($fixture['packages'] ?? null, $builder->packages($statement));
    }

    /** @return iterable<string, array{string}> */
    public static function statements(): iterable
    {
        foreach (glob(__DIR__.'/Fixtures/door/*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    /**
     * Keys in one order and every number a float, so the comparison is strict
     * about everything but how a whole number was written.
     */
    private static function normal(mixed $value): mixed
    {
        if (\is_int($value)) {
            return (float) $value;
        }
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::normal(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
