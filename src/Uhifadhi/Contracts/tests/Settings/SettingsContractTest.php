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
use Uhifadhi\Contracts\Settings\AreaRun;
use Uhifadhi\Contracts\Settings\CheckVerdict;
use Uhifadhi\Contracts\Settings\DecisionUrgency;
use Uhifadhi\Contracts\Settings\ModuleColumn;
use Uhifadhi\Contracts\Settings\ModuleMatrix;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Settings\SettingsCheck;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsStep;
use Uhifadhi\Contracts\Settings\SettingsTab;

/**
 * THE SETTINGS CONTRACTS — the shapes a bundle publishes an installation-wide
 * fact in.
 *
 * WHAT IS WORTH PINNING HERE is the behaviour, not the fields: that a figure
 * knows the difference between zero and unmeasured, that an area that runs
 * nothing is still a row, that the tab set is an ORDER two different renderers
 * read, and that every value object refuses to be constructed in a state a
 * screen would have to guess about.
 */
final class SettingsContractTest extends TestCase
{
    /**
     * ZERO IS A MEASUREMENT AND NULL IS THE ABSENCE OF ONE. A row that drew
     * them the same would report a quiet installation and an unconfigured one
     * identically, which is the failure the whole null branch exists for.
     */
    public function testAFigureKnowsWhetherItWasMeasuredAtAll(): void
    {
        self::assertTrue(new SettingsFigure('a', 'Areas', '0')->isMeasured(), 'Zero areas is a reading.');
        self::assertFalse(new SettingsFigure('a', 'Areas', null)->isMeasured(), 'No answer is not a reading.');
    }

    #[DataProvider('emptyNames')]
    public function testAFigureWithoutAKeyOrALabelIsRefused(string $key, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SettingsFigure($key, $label, '1');
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function emptyNames(): \Generator
    {
        yield 'no key' => ['', 'Areas'];
        yield 'blank key' => ['  ', 'Areas'];
        yield 'no label' => ['areas', ''];
        yield 'blank label' => ['areas', '  '];
    }

    /** A check is two states, and the row reads the verdict rather than the string. */
    public function testACheckReportsItsVerdict(): void
    {
        self::assertTrue(new SettingsCheck('m', CheckVerdict::Pass, 'Migrations are up to date')->passed());
        self::assertFalse(new SettingsCheck('m', CheckVerdict::Check, 'One is behind')->passed());
    }

    /**
     * AN AREA THAT RUNS NOTHING IS STILL A ROW, and it knows it is empty: it
     * is absent from every figure and every queue in the product precisely
     * because it has no module to contribute one, so this table is the only
     * place it can be seen at all.
     */
    public function testAnAreaThatRunsNothingIsAwaitingSetupRatherThanMissing(): void
    {
        $matrix = new ModuleMatrix(
            [new ModuleColumn('one', 'One'), new ModuleColumn('two', 'Two')],
            [
                new AreaRun('Running', ['one' => true, 'two' => false], 11),
                new AreaRun('Empty', ['one' => false, 'two' => false], 0),
            ],
        );

        self::assertSame(1, $matrix->liveAreas());
        self::assertSame(1, $matrix->awaitingSetup());
        self::assertSame(1, $matrix->rows[0]->moduleCount());
        self::assertFalse($matrix->rows[1]->isLive());
        self::assertFalse($matrix->isEmpty(), 'A matrix with rows is not an empty one.');
    }

    /** An installation with nothing in it has an empty matrix, and says so. */
    public function testAnInstallationWithNoAreasHasAnEmptyMatrix(): void
    {
        $matrix = new ModuleMatrix();

        self::assertTrue($matrix->isEmpty());
        self::assertSame(0, $matrix->liveAreas());
        self::assertSame(0, $matrix->awaitingSetup());
    }

    /**
     * THE OFFSET IS DRAWN BESIDE THE ZONE, so it is computed from the zone at
     * an instant rather than stored: a zone that observes daylight saving has
     * two of them, and a stored one is wrong for half the year.
     */
    public function testTheOffsetIsReadFromTheZoneAtAnInstant(): void
    {
        $at = new \DateTimeImmutable('2026-09-20 11:42:00', new \DateTimeZone('UTC'));

        self::assertSame('UTC+3', new OrganizationIdentity('Anywhere', timeZone: 'Africa/Dar_es_Salaam')->utcOffset($at));
        self::assertSame('UTC+0', new OrganizationIdentity('Anywhere', timeZone: 'UTC')->utcOffset($at));
        self::assertSame('UTC+5:45', new OrganizationIdentity('Anywhere', timeZone: 'Asia/Kathmandu')->utcOffset($at));
    }

    /**
     * A WRONG OFFSET IS WORSE THAN NONE, so a zone PHP does not know and a
     * zone nobody set both answer null and the screen draws the absence.
     */
    public function testAZoneNobodyCanReadHasNoOffsetRatherThanAWrongOne(): void
    {
        $at = new \DateTimeImmutable('2026-09-20 11:42:00', new \DateTimeZone('UTC'));

        self::assertNull(new OrganizationIdentity('Anywhere')->utcOffset($at), 'No zone set.');
        self::assertNull(new OrganizationIdentity('Anywhere', timeZone: 'Nowhere/Nothing')->utcOffset($at), 'A zone PHP does not know.');
    }

    /** An organization is known by its name; there is no nameless one to draw. */
    public function testAnOrganizationWithoutANameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrganizationIdentity('  ');
    }

    /**
     * THE TAB SET IS AN ORDER, and it is published because TWO renderers read
     * it — the page's strip and the sidebar's subtree. The overview is first
     * because the section wears the area idiom, and the screen that EDITS the
     * organization is last.
     */
    public function testTheScreensAreDeclaredInTheOrderTheSectionIsRead(): void
    {
        self::assertSame(
            ['overview', 'installation', 'modules', 'organization'],
            array_column(SettingsTab::cases(), 'value'),
        );
    }

    /**
     * THE FIRST SCREEN IS THE BARE ADDRESS and every other hangs one segment
     * below it — the same shape a configure page's sections wear, so a reader
     * who has learnt one URL has learnt the other.
     */
    public function testOnlyTheFirstScreenIsTheBareAddress(): void
    {
        $first = SettingsTab::first();

        self::assertTrue($first->isFirst());
        self::assertNull($first->segment(), 'The first screen takes no segment of its own.');

        foreach (SettingsTab::cases() as $tab) {
            if ($tab === $first) {
                continue;
            }

            self::assertFalse($tab->isFirst());
            self::assertSame($tab->value, $tab->segment());
        }
    }

    /** Every screen says something in the strip, and something under the head. */
    public function testEveryScreenHasAWordAndASubline(): void
    {
        foreach (SettingsTab::cases() as $tab) {
            self::assertNotSame('', trim($tab->label()), $tab->value.' says nothing in the strip.');
            self::assertNotSame('', trim($tab->subtitle()), $tab->value.' says nothing under the head.');
        }
    }

    /**
     * A STEP SAYS WHERE THE INSTALLATION IS, NOT WHETHER IT HAS BEGUN — the
     * whole of what makes the checklist worth opening in year three. The
     * value object carries `standing` and not a boolean "started", so there
     * is no shape in which a step could say the other thing.
     */
    public function testAStepStatesWhereTheInstallationIsAndWhatIsLeft(): void
    {
        $step = new SettingsStep('import-zones', 'Import zones', 'the ground records sit in', '1 of 4 areas divided', '/areas', false, '3 areas to go');

        self::assertSame('1 of 4 areas divided', $step->standing);
        self::assertFalse($step->done);
        self::assertSame('3 areas to go', $step->remaining);
    }

    /** Done is done, and then it carries no remainder to read. */
    public function testAStepWithNothingOutstandingCarriesNoRemainder(): void
    {
        $step = new SettingsStep('add-an-area', 'Add an area', 'a place every record resolves to', '4 registered', done: true);

        self::assertTrue($step->done);
        self::assertNull($step->remaining);
    }

    /** A step nobody can name and a step that says nothing are both refused. */
    public function testAStepWithoutAKeyOrALabelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SettingsStep('', 'Add an area', 'a place', 'none');
    }

    /**
     * HOW MANY AREAS RUN ONE MODULE is the number that separates a module
     * somebody is USING from a module somebody installed, which is what the
     * "installed once, switched on per area" reading is about.
     */
    public function testTheMatrixCountsTheAreasRunningOneModule(): void
    {
        $matrix = new ModuleMatrix(
            [new ModuleColumn('one', 'One'), new ModuleColumn('two', 'Two')],
            [
                new AreaRun('North', ['one' => true, 'two' => false]),
                new AreaRun('South', ['one' => true, 'two' => false]),
                new AreaRun('East', ['one' => false, 'two' => false]),
            ],
        );

        self::assertSame(2, $matrix->areasRunning('one'));
        self::assertSame(0, $matrix->areasRunning('two'), 'Installed everywhere and switched on nowhere is a real state.');
        self::assertSame(0, $matrix->areasRunning('never-heard-of-it'));
    }

    /**
     * THE THREE URGENCIES ARE THE AREA QUEUE'S THREE. A second vocabulary for
     * the same judgement would be two scales on one screen, and a reader who
     * had learnt one queue would have to learn the other.
     */
    public function testTheQueueUsesTheSameThreeUrgenciesAsTheAreasOwn(): void
    {
        self::assertSame(['now', 'soon', 'watch'], array_column(DecisionUrgency::cases(), 'value'));
    }
}
