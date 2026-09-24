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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\TeamBundle\Performance\AcrossTopicsMatrix;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\DepartmentDirectory;
use Uhifadhi\Contracts\Performance\DepartmentEntry;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;

/**
 * THE OVERVIEW'S OWN MATRIX: one cell a department in a TOPIC.
 *
 * A TOPIC'S MATRIX ANSWERS "how is this department doing at patrols";
 * this one answers "where is this department reading at all", which is
 * the question the organization's page opens with. It is built from the
 * topics rather than from a query of its own, so a module that publishes
 * a topic appears here the same day and the host writes no column.
 *
 * A TOPIC'S FIRST COLUMN IS ITS HEADLINE. The topic decided which of its
 * figures leads its own matrix; the overview does not get a second
 * opinion about somebody else's figures, and a host picking by label
 * would be matching words a module chose.
 */
#[CoversClass(AcrossTopicsMatrix::class)]
final class AcrossTopicsMatrixTest extends TestCase
{
    /** Every topic is one column, in the order the topics arrived. */
    public function testEveryTopicIsAColumnNamedAsTheTopicNamesItself(): void
    {
        $matrix = self::build([
            self::topic('staffing', 'Staffing', ['a' => 9.0, 'b' => 3.0]),
            self::topic('patrols', 'Patrols', ['a' => 61.0]),
        ]);

        self::assertSame(['staffing', 'patrols'], array_map(
            static fn (MatrixColumn $column): string => $column->key,
            $matrix->columns,
        ));
        self::assertSame(['Staffing', 'Patrols'], array_map(
            static fn (MatrixColumn $column): string => $column->label,
            $matrix->columns,
        ));
    }

    /**
     * THE COLUMN CARRIES THE TOPIC'S HEADLINE FIGURE, and says under the
     * topic's name what that figure is — the reader is looking at
     * "Patrols" and has to be told the number under it is coverage.
     */
    public function testAColumnSaysWhichOfTheTopicsFiguresItIsShowing(): void
    {
        $matrix = self::build([self::topic('patrols', 'Patrols', ['a' => 61.0])]);

        self::assertSame('Covered', $matrix->columns[0]->caption);
        self::assertSame('%', $matrix->columns[0]->unit);
        self::assertSame(ColumnPolarity::Up, $matrix->columns[0]->polarity);
    }

    /**
     * A TOPIC THAT IS NOT THIS DEPARTMENT'S IS THE HONEST DASH, not an
     * empty figure: the department never attached the module, so it was
     * never asked.
     */
    public function testADepartmentATopicDoesNotCoverGetsTheHonestDash(): void
    {
        $matrix = self::build([self::topic('patrols', 'Patrols', ['a' => 61.0])]);

        [$ecology, $tourism] = $matrix->rows;

        self::assertSame(61.0, $ecology->cells['patrols']->value);
        self::assertTrue($tourism->cells['patrols']->notMine);
    }

    /**
     * EVERY DEPARTMENT IS A ROW, in the directory's order and its own
     * band — the overview is the organization's list, not the list of
     * departments that happen to measure something.
     */
    public function testEveryDepartmentIsARowInItsOwnBand(): void
    {
        $matrix = self::build([self::topic('staffing', 'Staffing', ['a' => 1.0])]);

        self::assertSame(['Ecology', 'Tourism'], array_map(
            static fn (MatrixRow $row): string => $row->departmentName,
            $matrix->rows,
        ));
        self::assertSame(['Org-wide', 'Northreach'], array_map(
            static fn (MatrixRow $row): string => $row->band,
            $matrix->rows,
        ));
        self::assertSame('EC', $matrix->rows[0]->mark);
    }

    /**
     * A ROW SAYS WHAT IT ATTACHES, because that is what makes the dashes
     * beside it readable: a department with no module cannot have a
     * module figure, and the row says so rather than leaving the reader
     * to infer it from a line of dashes.
     */
    public function testARowSaysWhatItAttaches(): void
    {
        $matrix = self::build([self::topic('staffing', 'Staffing', ['a' => 1.0])]);

        self::assertSame('1 module', $matrix->rows[0]->note);
        self::assertSame('no module attached', $matrix->rows[1]->note);
    }

    /** And each band says what it was measured across. */
    public function testEachBandSaysWhatItReads(): void
    {
        $matrix = self::build([self::topic('staffing', 'Staffing', ['a' => 1.0])]);

        self::assertSame('each reads every area', $matrix->bandNotes['Org-wide'] ?? null);
        self::assertSame('each reads one area only', $matrix->bandNotes['Northreach'] ?? null);
    }

    /** The way into a department is the caller's to name, and it rides on the row. */
    public function testTheWayIntoADepartmentIsTheCallersToName(): void
    {
        $matrix = self::build([self::topic('staffing', 'Staffing', ['a' => 1.0])]);

        self::assertSame('/departments/a', $matrix->rows[0]->url);
    }

    /** A topic with no columns has no headline to lend, so it is no column. */
    public function testATopicWithNothingToShowIsNotAColumn(): void
    {
        $matrix = self::build([self::topic('empty', 'Empty', [], columns: false)]);

        self::assertSame([], $matrix->columns);
    }

    /**
     * @param list<PerformanceTopicProviderInterface> $topics
     */
    private static function build(array $topics): TopicMatrix
    {
        $directory = new DepartmentDirectory([
            new DepartmentEntry('a', 'Ecology', null, 'Org-wide', ['patrols'], ['patrols' => new \DateTimeImmutable()], 'EC'),
            new DepartmentEntry('b', 'Tourism', 'north', 'Northreach', mark: 'TO'),
        ]);

        return new AcrossTopicsMatrix()->build(
            $topics,
            $directory,
            PerformanceScope::organization(),
            FigurePeriod::month(new \DateTimeImmutable('2026-08-14')),
            static fn (string $uuid): string => '/departments/'.$uuid,
        );
    }

    /** @param array<string, float> $values keyed by department uuid */
    private static function topic(string $key, string $title, array $values, bool $columns = true): PerformanceTopicProviderInterface
    {
        return new class($key, $title, $values, $columns) implements PerformanceTopicProviderInterface {
            /** @param array<string, float> $values */
            public function __construct(
                private readonly string $key,
                private readonly string $title,
                private readonly array $values,
                private readonly bool $columns,
            ) {
            }

            public function moduleSlug(): string
            {
                return self::HOST;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function title(): string
            {
                return $this->title;
            }

            /** @return list<TopicKpi> */
            public function kpis(PerformanceScope $scope, FigurePeriod $period): array
            {
                return [];
            }

            /** @return list<TopicChart> */
            public function charts(PerformanceScope $scope, FigurePeriod $period): array
            {
                return [];
            }

            public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
            {
                if (!$this->columns) {
                    return new TopicMatrix([], []);
                }

                $rows = [];
                foreach ($this->values as $uuid => $value) {
                    $rows[] = new MatrixRow($uuid, $uuid, ['lead' => new MatrixCell($value)], 'Org-wide');
                }

                return new TopicMatrix(
                    [new MatrixColumn('lead', 'Covered', unit: '%', polarity: ColumnPolarity::Up)],
                    $rows,
                );
            }
        };
    }
}
