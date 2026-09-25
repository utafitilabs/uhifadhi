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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Command\FactsRebuildCommand;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\TallyFactProvider;

/**
 * `uhifadhi:facts:rebuild` — WHAT AN OPERATOR RUNS AFTER A RULE CHANGES.
 *
 * It asks every provider for every month of a range, and for the quarters
 * and years the range touches for the figures a quarter does not add up.
 * It is idempotent: a second run replaces the rows the first wrote.
 */
#[CoversClass(FactsRebuildCommand::class)]
#[CoversClass(FactRebuildService::class)]
final class FactsRebuildCommandTest extends FactsTestCase
{
    public function testItAsksEveryProviderForEveryMonthOfTheRange(): void
    {
        [$status, $output] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-07', '--until' => '2026-09']);

        self::assertSame(0, $status, $output);
        self::assertSame([
            '2026-07: surveys.metres, surveys.share',
            '2026-08: surveys.metres, surveys.share',
            '2026-09: surveys.metres, surveys.share',
            '2026-Q3: surveys.share',
            '2026: surveys.share',
        ], self::asked(SurveyFactProvider::$asked));
        self::assertSame([
            '2026-07: tallies.count',
            '2026-08: tallies.count',
            '2026-09: tallies.count',
        ], self::asked(TallyFactProvider::$asked), 'an additive figure is never asked for a quarter: the ledger sums its months');

        self::assertSame(700.0, $this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-07'));
        self::assertSame(1800.0, $this->stored(SurveyFactProvider::SOUTH, 'surveys.metres', '2026-09'));
        self::assertSame(0.5, $this->stored(SurveyFactProvider::SOUTH, 'surveys.share', '2026-Q3'));
        self::assertStringContainsString('2026-07', $output);
    }

    public function testRunningItTwiceLeavesTheSameRows(): void
    {
        $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-07', '--until' => '2026-09']);
        $rows = $this->rows();

        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-07', '--until' => '2026-09']);

        self::assertSame(0, $status);
        self::assertSame($rows, $this->rows());
        self::assertSame(700.0, $this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-07'));
    }

    /** After a rule changes, the rebuild replaces what the old rule computed. */
    public function testARebuildAfterARuleChangeReplacesTheFigures(): void
    {
        $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-07', '--until' => '2026-07']);

        SurveyFactProvider::$scale = 2.0;
        $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-07', '--until' => '2026-07']);

        self::assertSame(1400.0, $this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-07'));
    }

    public function testOneModuleIsRebuiltAlone(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'tallies', '--from' => '2026-09', '--until' => '2026-09']);

        self::assertSame(0, $status);
        self::assertSame([], SurveyFactProvider::$asked);
        self::assertSame(['2026-09: tallies.count'], self::asked(TallyFactProvider::$asked));
    }

    public function testOneSubjectIsRebuiltAlone(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'surveys', '--subject' => SurveyFactProvider::NORTH, '--from' => '2026-09', '--until' => '2026-09']);

        self::assertSame(0, $status);
        self::assertSame('2026-09: surveys.metres, surveys.share ['.SurveyFactProvider::NORTH.']', self::asked(SurveyFactProvider::$asked)[0]);
        self::assertNotNull($this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-09'));
        self::assertNull($this->stored(SurveyFactProvider::SOUTH, 'surveys.metres', '2026-09'));
    }

    /** With no range it rebuilds the month open now. */
    public function testWithNoRangeItRebuildsTheOpenMonth(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'surveys']);

        self::assertSame(0, $status);
        self::assertSame([
            '2026-09: surveys.metres, surveys.share',
            '2026-Q3: surveys.share',
            '2026: surveys.share',
        ], self::asked(SurveyFactProvider::$asked));
    }

    /** A day names the month it falls in. */
    public function testADayNamesItsMonth(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'tallies', '--from' => '2026-08-15', '--until' => '2026-09-02']);

        self::assertSame(0, $status);
        self::assertSame(['2026-08: tallies.count', '2026-09: tallies.count'], self::asked(TallyFactProvider::$asked));
    }

    public function testAModuleThatComputesNothingIsAnError(): void
    {
        [$status, $output] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'nobody']);

        self::assertSame(1, $status);
        self::assertStringContainsString('nobody', $output);
    }

    public function testARangeThatEndsBeforeItStartsIsAnError(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => '2026-09', '--until' => '2026-07']);

        self::assertSame(1, $status);
        self::assertSame([], SurveyFactProvider::$asked);
    }

    public function testADateItCannotReadIsAnError(): void
    {
        [$status] = $this->console(['command' => 'uhifadhi:facts:rebuild', '--from' => 'last tuesday-ish']);

        self::assertSame(1, $status);
    }

    /** A provider's answer outside what it was asked is not filed. */
    public function testOnlyWhatWasAskedIsFiled(): void
    {
        $this->console(['command' => 'uhifadhi:facts:rebuild', '--module' => 'surveys', '--subject' => SurveyFactProvider::SOUTH, '--from' => '2026-09', '--until' => '2026-09']);

        self::assertNull($this->stored(SurveyFactProvider::NORTH, 'surveys.share', '2026-09'));
    }
}
