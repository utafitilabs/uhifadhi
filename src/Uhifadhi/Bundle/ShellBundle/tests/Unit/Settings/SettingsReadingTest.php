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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Uhifadhi\Bundle\ShellBundle\Service\Installation;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsReading;
use Uhifadhi\Contracts\Settings\AreaRun;
use Uhifadhi\Contracts\Settings\CheckVerdict;
use Uhifadhi\Contracts\Settings\DecisionUrgency;
use Uhifadhi\Contracts\Settings\ModuleMatrix;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\OrganizationIdentity;
use Uhifadhi\Contracts\Settings\OrganizationIdentitySourceInterface;
use Uhifadhi\Contracts\Settings\SettingsChange;
use Uhifadhi\Contracts\Settings\SettingsChangeSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsCheck;
use Uhifadhi\Contracts\Settings\SettingsCheckSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsDecision;
use Uhifadhi\Contracts\Settings\SettingsDecisionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsStep;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;

/**
 * THE SETTINGS SECTION'S READING, COMPOSED FROM WHOEVER OWNS EACH FACT.
 *
 * What is worth pinning is the composition, not the numbers: that the figure
 * row is assembled in the order the owners declared rather than in whatever
 * order the container registered them, that the queue is sorted by urgency
 * ACROSS its sources rather than grouped by the bundle that noticed, that the
 * changes card is bounded, and — the one that matters most — that a source
 * which throws cannot take down the one screen somebody opens to find out
 * whether anything is wrong.
 */
final class SettingsReadingTest extends TestCase
{
    /**
     * DECLARED ORDER, NOT REGISTRATION ORDER. A contributing bundle says
     * where its card sits with a number, because the alternative is a hope
     * about container compilation order — and the row is read left to right
     * as "how big is this, and how much of it is set up".
     */
    public function testTheFigureRowIsAssembledInTheOrderItsOwnersDeclared(): void
    {
        $reading = $this->reading(figures: [
            $this->figures(30, new SettingsFigure('people', 'People', '22')),
            $this->figures(10, new SettingsFigure('modules', 'Modules installed', '3', hot: true)),
            $this->figures(20, new SettingsFigure('areas', 'Areas', '4')),
        ]);

        self::assertSame(['modules', 'areas', 'people'], array_column($reading->figures(), 'key'));
    }

    /**
     * THE INSTALLATION SCREEN'S ROW IS THE SECTION'S OWN, and two of its four
     * cards state their own absence on an ordinary installation: whether a
     * package is behind needs a release feed and when it last deployed needs
     * whatever deployed it to have said so. Neither is a thing a running
     * application can read about itself.
     */
    public function testTheInstallationRowStatesWhatItCannotAnswer(): void
    {
        $figures = $this->reading()->installationFigures();

        self::assertSame(['modules', 'core', 'health', 'deploy'], array_column($figures, 'key'));
        self::assertFalse($figures[3]->isMeasured(), 'Nothing here records a deploy.');
        self::assertNotSame('', (string) $figures[3]->caption, 'And the card says why rather than leaving a gap.');
    }

    /**
     * THE FIRST CARD IS THE CATALOGUE'S ANSWER WHEREVER THERE IS ONE. The
     * section can read the vendor directory and see packages; what a MODULE
     * is, is the registry's definition, so the card is taken from the
     * contributed row rather than counted here.
     */
    public function testTheInstallationRowLeadsWithTheContributedModuleCount(): void
    {
        $reading = $this->reading(figures: [
            $this->figures(10, new SettingsFigure('modules', 'Modules installed', '7', hot: true)),
        ]);

        self::assertSame('7', $reading->installationFigures()[0]->value);
    }

    /** And where nothing keeps a catalogue, it says so instead of reading zero. */
    public function testWithNoCatalogueTheModuleCardStatesItsOwnAbsence(): void
    {
        $card = $this->reading()->installationFigures()[0];

        self::assertSame('modules', $card->key);
        self::assertFalse($card->isMeasured());
        self::assertTrue($card->hot, 'It is still the card the screen is about.');
    }

    /**
     * THE HEALTH FIGURE COUNTS THE VERDICTS, which is the only thing that can
     * be said about a list nobody grades on a common scale.
     */
    public function testTheHealthCardCountsTheVerdicts(): void
    {
        $reading = $this->reading(checks: [
            $this->checks(10,
                new SettingsCheck('a', CheckVerdict::Pass, 'One'),
                new SettingsCheck('b', CheckVerdict::Check, 'Two'),
                new SettingsCheck('c', CheckVerdict::Pass, 'Three'),
            ),
        ]);

        self::assertSame(3, \count($reading->checks()));
        self::assertSame(2, $reading->checksPassed());

        $card = $reading->installationFigures()[2];
        self::assertSame('3', $card->value);
        self::assertSame('2 pass', $card->caption);
        self::assertSame('1 to check', $card->warning);
    }

    /**
     * A CHECK THAT CANNOT BE RUN IS A FINDING, NOT AN OUTAGE. This is the one
     * screen somebody opens to find out whether anything is wrong, and a
     * source that threw would take down the report of itself. Every other
     * check still answers.
     */
    public function testASourceThatThrowsBecomesARowRatherThanA500(): void
    {
        $reading = $this->reading(checks: [
            $this->checks(10, new SettingsCheck('first', CheckVerdict::Pass, 'One')),
            new class implements SettingsCheckSourceInterface {
                public function position(): int
                {
                    return 20;
                }

                public function settingsChecks(): iterable
                {
                    // A REAL GENERATOR THAT FAILS PART WAY THROUGH, which is
                    // the harder shape: the rows it managed to yield are
                    // already on the page when it gives up.
                    yield new SettingsCheck('read-this-far', CheckVerdict::Pass, 'Read this far');

                    throw new \RuntimeException('the storage did not answer');
                }
            },
            $this->checks(30, new SettingsCheck('third', CheckVerdict::Pass, 'Three')),
        ]);

        $checks = $reading->checks();

        self::assertSame(
            ['first', 'read-this-far', 'unavailable-2', 'third'],
            array_column($checks, 'key'),
            'What it managed to yield is kept, the failure becomes a row, and the source after it still answers.',
        );
        self::assertFalse($checks[2]->passed());
        self::assertSame('the storage did not answer', $checks[2]->detail, 'The row says what went wrong.');
    }

    /**
     * SORTED BY URGENCY ACROSS THE SOURCES, never grouped by the bundle that
     * noticed: somebody reading the queue is asking "what do I have to do",
     * and a list arranged by who raised it makes them read all of it to find
     * out.
     */
    public function testTheQueueIsSortedByUrgencyAcrossItsSources(): void
    {
        $reading = $this->reading(decisions: [
            $this->decisions($this->decision('w', DecisionUrgency::Watch), $this->decision('n1', DecisionUrgency::Now)),
            $this->decisions($this->decision('s', DecisionUrgency::Soon), $this->decision('n2', DecisionUrgency::Now)),
        ]);

        self::assertSame(['n1', 'n2', 's', 'w'], array_column($reading->decisions(), 'key'));
    }

    /**
     * THE CHANGES CARD IS BOUNDED AND NEWEST FIRST — a card whose height grew
     * with its data is the overflow ruling being broken, and the merge is
     * across sources, so no one of them can crowd the others out.
     */
    public function testTheChangesCardIsMergedNewestFirstAndBounded(): void
    {
        $reading = $this->reading(changes: [
            $this->changes(
                new SettingsChange('a', new \DateTimeImmutable('2026-09-01 09:00'), 'Oldest'),
                new SettingsChange('b', new \DateTimeImmutable('2026-09-18 22:14'), 'Newest'),
            ),
            $this->changes(
                new SettingsChange('c', new \DateTimeImmutable('2026-09-12 09:30'), 'Middle'),
                new SettingsChange('d', new \DateTimeImmutable('2026-09-04 11:00'), 'Older'),
                new SettingsChange('e', new \DateTimeImmutable('2026-09-02 11:00'), 'Oldest but one'),
            ),
        ]);

        self::assertSame(
            ['b', 'c', 'd', 'e'],
            array_column($reading->changes(), 'key'),
            'Newest first, cut to what the card holds.',
        );
        self::assertCount(SettingsReading::CHANGES, $reading->changes());
    }

    /**
     * THE CHECKLIST IS ASSEMBLED IN THE ORDER SOMEBODY WOULD DO IT, across
     * its owners: the ground first, then the people in it. A list grouped by
     * the bundle that published each step would read as an accident of
     * installation order.
     */
    public function testTheChecklistIsAssembledInTheOrderItsOwnersDeclared(): void
    {
        $reading = $this->reading(steps: [
            $this->steps(30, $this->step('post-people'), $this->step('compose-a-position')),
            $this->steps(10, $this->step('add-an-area'), $this->step('import-zones')),
        ]);

        self::assertSame(
            ['add-an-area', 'import-zones', 'post-people', 'compose-a-position'],
            array_column($reading->steps(), 'key'),
        );
    }

    /**
     * DONE IS COUNTED, NOT ASSERTED. The caption says how many have nothing
     * outstanding today, which is a number that goes back down when an
     * installation grows.
     */
    public function testTheChecklistCountsWhatHasNothingOutstanding(): void
    {
        $reading = $this->reading(steps: [
            $this->steps(10, $this->step('a', done: true), $this->step('b'), $this->step('c', done: true)),
        ]);

        self::assertCount(3, $reading->steps());
        self::assertSame(2, $reading->stepsDone());
    }

    /**
     * AN INSTALLATION WHERE NOBODY ANSWERS IS A READING, NOT A FAILURE. No
     * areas bundle means no matrix and a screen that says so; no identity
     * source means the wordmark the installation was shipped with, and every
     * other field stating that it is not set — which is the page telling
     * somebody exactly what there is to do.
     */
    public function testWithNobodyAnsweringTheSinglesTheScreenStillReads(): void
    {
        $reading = $this->reading();

        self::assertTrue($reading->matrix()->isEmpty());
        self::assertSame('Uhifadhi', $reading->identity()->name);
        self::assertNull($reading->identity()->logo);
    }

    /** And where somebody does answer, the answer is theirs. */
    public function testTheSinglesComeFromWhoeverAnswersThem(): void
    {
        $matrix = new ModuleMatrix([], [new AreaRun('Somewhere', [], 0)]);
        $identity = new OrganizationIdentity('An Authority', shortName: 'AA');

        $reading = $this->reading(singles: [
            ModuleMatrixSourceInterface::SERVICE => new class($matrix) implements ModuleMatrixSourceInterface {
                public function __construct(private readonly ModuleMatrix $matrix)
                {
                }

                public function moduleMatrix(): ModuleMatrix
                {
                    return $this->matrix;
                }
            },
            OrganizationIdentitySourceInterface::SERVICE => new class($identity) implements OrganizationIdentitySourceInterface {
                public function __construct(private readonly OrganizationIdentity $identity)
                {
                }

                public function organizationIdentity(): OrganizationIdentity
                {
                    return $this->identity;
                }
            },
        ]);

        self::assertSame('Somewhere', $reading->matrix()->rows[0]->name);
        self::assertSame('AA', $reading->identity()->shortName);
    }

    /**
     * ONE MEASUREMENT PER REQUEST. Four screens pull different subsets of
     * this and two cards on one screen read the same figure; asking twice is
     * how two cards come to disagree by a second.
     */
    public function testEachAnswerIsTakenOnceAndKeptForTheRequest(): void
    {
        $counter = new class implements SettingsFigureSourceInterface {
            public int $asked = 0;

            public function position(): int
            {
                return 10;
            }

            public function settingsFigures(): iterable
            {
                ++$this->asked;

                yield new SettingsFigure('modules', 'Modules installed', '1', hot: true);
            }
        };

        $reading = $this->reading(figures: [$counter]);
        $reading->figures();
        $reading->figures();
        $reading->installationFigures();

        self::assertSame(1, $counter->asked);
    }

    /**
     * @param list<SettingsFigureSourceInterface>   $figures
     * @param list<SettingsCheckSourceInterface>    $checks
     * @param list<SettingsDecisionSourceInterface> $decisions
     * @param list<SettingsChangeSourceInterface>   $changes
     * @param list<SettingsStepSourceInterface>     $steps
     * @param array<string, object>                 $singles
     */
    private function reading(
        array $figures = [],
        array $checks = [],
        array $decisions = [],
        array $changes = [],
        array $steps = [],
        array $singles = [],
    ): SettingsReading {
        return new SettingsReading(
            new Installation(),
            $figures,
            $checks,
            $decisions,
            $changes,
            $steps,
            new class($singles) implements ContainerInterface {
                /** @param array<string, object> $services */
                public function __construct(private readonly array $services)
                {
                }

                public function get(string $id): object
                {
                    return $this->services[$id];
                }

                public function has(string $id): bool
                {
                    return isset($this->services[$id]);
                }
            },
            'Uhifadhi',
        );
    }

    private function figures(int $position, SettingsFigure ...$figures): SettingsFigureSourceInterface
    {
        return new class($position, array_values($figures)) implements SettingsFigureSourceInterface {
            /** @param list<SettingsFigure> $figures */
            public function __construct(private readonly int $at, private readonly array $figures)
            {
            }

            public function position(): int
            {
                return $this->at;
            }

            public function settingsFigures(): iterable
            {
                yield from $this->figures;
            }
        };
    }

    private function checks(int $position, SettingsCheck ...$checks): SettingsCheckSourceInterface
    {
        return new class($position, array_values($checks)) implements SettingsCheckSourceInterface {
            /** @param list<SettingsCheck> $checks */
            public function __construct(private readonly int $at, private readonly array $checks)
            {
            }

            public function position(): int
            {
                return $this->at;
            }

            public function settingsChecks(): iterable
            {
                yield from $this->checks;
            }
        };
    }

    private function decisions(SettingsDecision ...$decisions): SettingsDecisionSourceInterface
    {
        return new class(array_values($decisions)) implements SettingsDecisionSourceInterface {
            /** @param list<SettingsDecision> $decisions */
            public function __construct(private readonly array $decisions)
            {
            }

            public function settingsDecisions(): iterable
            {
                yield from $this->decisions;
            }
        };
    }

    private function changes(SettingsChange ...$changes): SettingsChangeSourceInterface
    {
        return new class(array_values($changes)) implements SettingsChangeSourceInterface {
            /** @param list<SettingsChange> $changes */
            public function __construct(private readonly array $changes)
            {
            }

            public function settingsChanges(int $limit): iterable
            {
                yield from \array_slice($this->changes, 0, $limit);
            }
        };
    }

    private function steps(int $position, SettingsStep ...$steps): SettingsStepSourceInterface
    {
        return new class($position, array_values($steps)) implements SettingsStepSourceInterface {
            /** @param list<SettingsStep> $steps */
            public function __construct(private readonly int $at, private readonly array $steps)
            {
            }

            public function position(): int
            {
                return $this->at;
            }

            public function settingsSteps(): iterable
            {
                yield from $this->steps;
            }
        };
    }

    private function step(string $key, bool $done = false): SettingsStep
    {
        return new SettingsStep($key, 'Do the thing', 'what it gives', 'where this one is', done: $done);
    }

    private function decision(string $key, DecisionUrgency $urgency): SettingsDecision
    {
        return new SettingsDecision($key, $urgency, 'Something is true.', 'And this follows.', 'installation', 'a subject', 'today', '—');
    }
}
