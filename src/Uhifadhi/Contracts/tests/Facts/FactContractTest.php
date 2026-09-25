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

namespace Uhifadhi\Contracts\Tests\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Facts\Fact;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Facts\FigureDefinition;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

/**
 * WHAT A MODULE HANDS THE LEDGER AND WHAT A PAGE READS BACK.
 */
#[CoversClass(Fact::class)]
#[CoversClass(FactValue::class)]
#[CoversClass(FigureDefinition::class)]
#[CoversClass(FactRequest::class)]
final class FactContractTest extends TestCase
{
    public function testTheSeamIsTaggedUnderOneSpelling(): void
    {
        self::assertSame('uhifadhi.facts', FactProviderInterface::TAG);
    }

    public function testTheAsyncMarkerIsAnInterface(): void
    {
        self::assertTrue(interface_exists(AsyncMessageInterface::class));
        self::assertSame([], new \ReflectionClass(AsyncMessageInterface::class)->getMethods());
    }

    /** A fact computed before its period ended is true as of when it was computed. */
    public function testAFactOfAnOpenPeriodIsTrueAsOfItsComputation(): void
    {
        $fact = new Fact(
            FactSubject::DEPARTMENT,
            '0199a1a0-0000-7000-8000-000000000001',
            'surveys.coverage',
            '2026-09',
            0.54,
            new \DateTimeImmutable('2026-09-25 13:00:00'),
        );

        self::assertSame('2026-09-25 13:00:00', $fact->asOf->format('Y-m-d H:i:s'));
        self::assertFalse($fact->isFinal());
    }

    /** A fact computed after its period ended is the period's final figure. */
    public function testAFactComputedAfterItsPeriodIsFinal(): void
    {
        $fact = new Fact(
            FactSubject::DEPARTMENT,
            '0199a1a0-0000-7000-8000-000000000001',
            'surveys.coverage',
            '2026-08',
            0.61,
            new \DateTimeImmutable('2026-09-01 02:00:00'),
        );

        self::assertTrue($fact->isFinal());
        self::assertSame('2026-09-01 00:00:00', $fact->asOf->format('Y-m-d H:i:s'), 'the figure is true to its period’s end, not to the hour it was written');
    }

    /** Null is unknown, and unknown is not zero. */
    public function testANullValueIsAnUnknownFigure(): void
    {
        $fact = new Fact(FactSubject::AREA, '0199a1a0-0000-7000-8000-000000000001', 'surveys.coverage', '2026-09', null, new \DateTimeImmutable('2026-09-25 13:00:00'));

        self::assertFalse($fact->isKnown());
    }

    public function testAFactPeriodIsReadFromItsKey(): void
    {
        $fact = new Fact(FactSubject::AREA, '0199a1a0-0000-7000-8000-000000000001', 'surveys.coverage', '2026-Q3', 1.0, new \DateTimeImmutable('2026-09-25 13:00:00'));

        self::assertSame('2026-07-01', $fact->period()->from->format('Y-m-d'));
    }

    public function testAValueMustNameItsSubjectAndFigure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FactValue(FactSubject::AREA, '', 'surveys.coverage', 1.0);
    }

    public function testAFigureKeyNamesItsModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FigureDefinition('coverage', FactSubject::AREA, additive: false);
    }

    public function testAFigureIsAdditiveOnlyWhenItSaysSo(): void
    {
        $sum = new FigureDefinition('surveys.distance', FactSubject::AREA, additive: true);
        $share = new FigureDefinition('surveys.coverage', FactSubject::AREA, additive: false);

        self::assertTrue($sum->additive);
        self::assertFalse($share->additive);
    }

    public function testARequestStatesItsPeriodAndFigures(): void
    {
        $request = new FactRequest(FactPeriod::fromKey('2026-09'), ['surveys.coverage'], null);

        self::assertSame('2026-09', $request->period->key);
        self::assertTrue($request->asks('surveys.coverage'));
        self::assertFalse($request->asks('surveys.distance'));
        self::assertTrue($request->covers('0199a1a0-0000-7000-8000-000000000001'));
    }

    public function testARequestForOneSubjectCoversThatSubjectOnly(): void
    {
        $request = new FactRequest(FactPeriod::fromKey('2026-09'), ['surveys.coverage'], '0199a1a0-0000-7000-8000-000000000001');

        self::assertTrue($request->covers('0199a1a0-0000-7000-8000-000000000001'));
        self::assertFalse($request->covers('0199a1a0-0000-7000-8000-000000000002'));
    }
}
