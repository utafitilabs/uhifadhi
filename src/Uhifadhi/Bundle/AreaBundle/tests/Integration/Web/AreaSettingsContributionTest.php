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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaFigure;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaModuleMatrix;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetup;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetupCheck;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetupDecision;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSteps;
use Uhifadhi\Contracts\Settings\CheckVerdict;
use Uhifadhi\Contracts\Settings\SettingsStep;

/**
 * WHAT THIS BUNDLE TELLS THE SETTINGS SECTION, read against a real database.
 *
 * THE MATRIX IS THE ONE READING WITH ACTUAL QUERIES IN IT — the areas, their
 * zones and the ledger of what is switched on in each — and every other
 * contribution here is derived from it. So this suite proves the queries and
 * takes the derivations with them: a figure, a health check, a queue item and
 * three steps that all agree with the same table.
 *
 * AN AREA THAT RUNS NOTHING IS THE CASE WORTH FIXTURING. It contributes to no
 * figure and appears in no queue anywhere else in the product, because it has
 * no module to contribute one — so these are the readings that make it
 * visible at all.
 */
#[CoversClass(AreaModuleMatrix::class)]
#[CoversClass(AreaFigure::class)]
#[CoversClass(AreaSetupCheck::class)]
#[CoversClass(AreaSetupDecision::class)]
#[CoversClass(AreaSteps::class)]
final class AreaSettingsContributionTest extends WebTestCase
{
    /** One area running a module, one registered and empty. */
    public function testTheMatrixIsEveryAreaAgainstEveryModule(): void
    {
        $this->boot();
        $live = $this->aLiveArea('Northern Conservation Reserve');
        $this->aZone($live, 'West', self::A_WEST_HALF);
        $this->anArea('Southern Reserve');

        $matrix = $this->matrix()->moduleMatrix();

        self::assertCount(2, $matrix->rows);
        self::assertSame('Patrols', $matrix->columns[0]->label);
        self::assertNotNull($matrix->columns[0]->description, 'A module says what it is about in its own words.');
        self::assertSame(1, $matrix->liveAreas());
        self::assertSame(1, $matrix->awaitingSetup());
        self::assertSame(1, $matrix->rows[0]->zones, 'The row carries the area\'s own zone count.');
        self::assertTrue($matrix->rows[0]->isLive());
        self::assertFalse($matrix->rows[1]->isLive(), 'Registered and empty is a row, not an absence.');
    }

    /** The figure is the matrix read as one card, never counted a second time. */
    public function testTheFigureAgreesWithTheMatrix(): void
    {
        $this->boot();
        $this->aLiveArea('Northern Conservation Reserve');
        $this->anArea('Southern Reserve');

        $figures = iterator_to_array(new AreaFigure($this->matrix(), $this->checker())->settingsFigures());

        self::assertCount(1, $figures);
        self::assertSame('2', $figures[0]->value);
        self::assertSame('1 running', $figures[0]->caption);
        self::assertSame('1 registered, empty', $figures[0]->warning);
    }

    /**
     * ONE FACT, TWO READINGS: the health row and the queue item come off the
     * same shared reading, so a check that passes while the queue complains
     * is not a state this pair can be in.
     */
    public function testTheEmptyAreaIsBothAFindingAndAQueueItem(): void
    {
        $this->boot();
        $this->aLiveArea('Northern Conservation Reserve');
        $this->anArea('Southern Reserve');
        $setup = $this->emptyAreas();

        $checks = iterator_to_array(new AreaSetupCheck($setup, $this->checker())->settingsChecks());
        $decisions = iterator_to_array(new AreaSetupDecision($setup, $this->checker())->settingsDecisions());

        self::assertCount(1, $checks);
        self::assertSame(CheckVerdict::Check, $checks[0]->verdict);
        self::assertStringContainsString('Southern Reserve', $checks[0]->detail);

        self::assertCount(1, $decisions);
        self::assertStringContainsString('Southern Reserve', $decisions[0]->detail);
    }

    /** And with every area running something, the check passes and the queue is empty. */
    public function testWithEveryAreaRunningSomethingNothingIsRaised(): void
    {
        $this->boot();
        $this->aLiveArea('Northern Conservation Reserve');
        $setup = $this->emptyAreas();

        self::assertTrue(iterator_to_array(new AreaSetupCheck($setup, $this->checker())->settingsChecks())[0]->passed());
        self::assertSame([], iterator_to_array(new AreaSetupDecision($setup, $this->checker())->settingsDecisions()));
    }

    /**
     * AN INSTALLATION WITH NO AREAS HAS NOT GOT THIS WRONG, it has not got
     * here yet — so there is no failing check, and the first step says to
     * start.
     */
    public function testWithNoAreasThereIsNoFailingCheckAndAStepToStart(): void
    {
        $this->boot();

        self::assertSame([], iterator_to_array(new AreaSetupCheck($this->emptyAreas(), $this->checker())->settingsChecks()));

        $steps = $this->steps();
        self::assertSame('add-an-area', $steps[0]->key);
        self::assertFalse($steps[0]->done);
        self::assertSame('none registered', $steps[0]->standing);
    }

    /**
     * EVERY STEP SAYS WHERE THIS INSTALLATION IS, never whether it has begun
     * — the rule the whole checklist exists for.
     */
    public function testTheStepsStateWhereThisInstallationIs(): void
    {
        $this->boot();
        $live = $this->aLiveArea('Northern Conservation Reserve');
        $this->aZone($live, 'West', self::A_WEST_HALF);
        $this->anArea('Southern Reserve');

        $steps = [];
        foreach ($this->steps() as $step) {
            $steps[$step->key] = $step;
        }

        self::assertSame(['add-an-area', 'import-zones', 'switch-modules-on'], array_keys($steps));

        self::assertTrue($steps['add-an-area']->done);
        self::assertSame('2 registered', $steps['add-an-area']->standing);

        self::assertFalse($steps['import-zones']->done);
        self::assertSame('1 of 2 areas divided', $steps['import-zones']->standing);
        self::assertSame('1 area to go', $steps['import-zones']->remaining);

        self::assertFalse($steps['switch-modules-on']->done);
        self::assertSame('1 of 2 areas running', $steps['switch-modules-on']->standing);
    }

    // ---------------------------------------------------------------- fixtures

    private function matrix(): AreaModuleMatrix
    {
        $matrix = static::getContainer()->get('test_public.area.settings.module_matrix');
        \assert($matrix instanceof AreaModuleMatrix);

        return $matrix;
    }

    /**
     * SOMEBODY WHO MAY NOT READ THE AREAS IS TOLD NOTHING ABOUT THEM — no
     * count, no area named in a check or a queue item, no step linking to
     * the register. The section holds no authorization service, so each
     * source is what withholds itself.
     */
    public function testAViewerWithoutAreasReadGetsNoFigureCheckDecisionOrStep(): void
    {
        $this->boot([]);
        $this->aLiveArea('Northern Conservation Reserve');
        $this->anArea('Southern Reserve');
        $setup = $this->emptyAreas();

        self::assertSame([], iterator_to_array(new AreaFigure($this->matrix(), $this->checker())->settingsFigures()));
        self::assertSame([], iterator_to_array(new AreaSetupCheck($setup, $this->checker())->settingsChecks()));
        self::assertSame([], iterator_to_array(new AreaSetupDecision($setup, $this->checker())->settingsDecisions()));
        self::assertSame([], $this->steps());
    }

    private function checker(): AuthorizationCheckerInterface
    {
        $checker = static::getContainer()->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);

        return $checker;
    }

    /** PHP method names are case-insensitive, so this cannot be called `setup`. */
    private function emptyAreas(): AreaSetup
    {
        return new AreaSetup($this->matrix());
    }

    /** @return list<SettingsStep> */
    private function steps(): array
    {
        $urls = static::getContainer()->get('router');
        \assert($urls instanceof UrlGeneratorInterface);

        return array_values(iterator_to_array(new AreaSteps($this->matrix(), $urls, $this->checker())->settingsSteps()));
    }
}
