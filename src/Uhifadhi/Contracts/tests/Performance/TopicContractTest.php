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

namespace Uhifadhi\Contracts\Tests\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\DepartmentDirectory;
use Uhifadhi\Contracts\Performance\DepartmentEntry;
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Contracts\Performance\KpiRole;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\TopicKpi;

/**
 * WHAT THE PERFORMANCE CONTRACT PROMISES, ASSERTED WHERE IT IS PUBLISHED.
 *
 * These are the values a module builds and the host reads, and every one
 * of them is about the same thing: keeping an absence from being read as
 * a nought. A figure nobody published, a period nobody wrote down, a
 * column that is not a department's to answer and a module nobody runs
 * are four different silences, and this is where the difference is
 * pinned.
 */
#[CoversClass(TopicKpi::class)]
#[CoversClass(MatrixCell::class)]
#[CoversClass(DepartmentEntry::class)]
#[CoversClass(DepartmentDirectory::class)]
#[CoversClass(ColumnPolarity::class)]
final class TopicContractTest extends TestCase
{
    /** A figure nobody published is not known, and is not nought. */
    public function testAFigureNobodyPublishedIsNotKnown(): void
    {
        self::assertFalse(new TopicKpi('x', 'X', null)->isKnown());
        self::assertTrue(new TopicKpi('x', 'X', 0.0)->isKnown());
    }

    /** And a run of nothing but holes is no history at all. */
    public function testARunOfHolesIsNoHistory(): void
    {
        self::assertFalse(new TopicKpi('x', 'X', 1.0, history: [null, null])->hasHistory());
        self::assertTrue(new TopicKpi('x', 'X', 1.0, history: [null, 2.0])->hasHistory());
    }

    /**
     * A ROLE IS THE EXCEPTION. The host adds up records across topics and
     * cannot tell which of five figures that is — the label is the
     * module's own word — so the one it must find says so.
     */
    public function testOnlyAFigureWithARoleIsOneTheHostMayAddUp(): void
    {
        self::assertNull(new TopicKpi('x', 'Cases filed', 561.0)->role);
        self::assertSame(KpiRole::Records, new TopicKpi('x', 'Cases filed', 561.0, role: KpiRole::Records)->role);
    }

    /** A cell that is not a department's to answer is not an empty figure. */
    public function testACellThatIsNotADepartmentsIsItsOwnKindOfEmpty(): void
    {
        $notMine = MatrixCell::notMine();
        self::assertTrue($notMine->notMine);
        self::assertFalse($notMine->isKnown());

        $published = new MatrixCell(value: 0.0);
        self::assertFalse($published->notMine);
        self::assertTrue($published->isKnown(), 'a real nought is a measurement');
    }

    /**
     * ATTACHING A MODULE IS NOT THE SAME AS BEING ASKABLE ABOUT IT, which
     * is the whole reason the directory carries `runningSince`.
     */
    public function testADepartmentCanAnswerOnlyWhereTheModuleIsRunning(): void
    {
        $attachedOnly = new DepartmentEntry('u', 'Wetlands', null, 'Org-wide', ['patrols'], ['patrols' => null]);
        self::assertTrue($attachedOnly->attaches('patrols'));
        self::assertFalse($attachedOnly->canAnswerFor('patrols'));

        $running = new DepartmentEntry('u', 'Wetlands', null, 'Org-wide', ['patrols'], ['patrols' => new \DateTimeImmutable('2026-03-01')]);
        self::assertTrue($running->canAnswerFor('patrols'));
    }

    /**
     * ATTACHING IS WHAT MAKES A ROW; being askable is what makes a cell.
     *
     * A department that attached the module said this is work it leads
     * for, so it is a row even where no area it reads runs the module
     * yet — its cells are `notMine` until one does. A department that
     * attaches nothing of the kind is not a row at all.
     */
    public function testAttachingMakesARowAndAnsweringMakesACell(): void
    {
        $directory = self::threeDepartments();

        self::assertSame(
            ['Ecology', 'Wetlands'],
            array_map(static fn (DepartmentEntry $e): string => $e->name, $directory->attaching('patrols')),
            'both attach it; one cannot answer yet, and is still a row',
        );
        self::assertSame(
            ['Ecology'],
            array_map(static fn (DepartmentEntry $e): string => $e->name, $directory->answeringFor('patrols')),
            'and only one of them has cells to fill',
        );
    }

    /** The bands keep the order the surfaces read them in. */
    public function testTheBandsKeepTheirOrder(): void
    {
        self::assertSame(['Org-wide', 'Northern Reserve'], self::threeDepartments()->bands());
    }

    private static function threeDepartments(): DepartmentDirectory
    {
        return new DepartmentDirectory([
            new DepartmentEntry('a', 'Ecology', null, 'Org-wide', ['patrols'], ['patrols' => new \DateTimeImmutable()]),
            new DepartmentEntry('b', 'Wetlands', 'area', 'Northern Reserve', ['patrols'], ['patrols' => null]),
            new DepartmentEntry('c', 'Tourism', null, 'Org-wide'),
        ]);
    }

    /** A column with no polarity makes no claim, and colours nothing. */
    public function testAColumnWithNoPolarityJudgesNothing(): void
    {
        self::assertFalse(ColumnPolarity::None->judges());
        self::assertNull(ColumnPolarity::None->isGood(5.0));
        self::assertTrue(ColumnPolarity::Up->isGood(5.0));
        self::assertTrue(ColumnPolarity::Down->isGood(-5.0));
    }

    /** A ground series nobody published a figure in is not drawn. */
    public function testAGroundSeriesOfNothingIsNotDrawn(): void
    {
        self::assertTrue(new GeoSeries('k', 'T', [new GeoFigure('a', 'Kilimani Crater', null)])->isEmpty());
        self::assertFalse(new GeoSeries('k', 'T', [new GeoFigure('a', 'Kilimani Crater', 0.0)])->isEmpty());
    }
}
