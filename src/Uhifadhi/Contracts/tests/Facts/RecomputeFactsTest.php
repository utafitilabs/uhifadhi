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
use Uhifadhi\Contracts\Facts\RecomputeFacts;
use Uhifadhi\Contracts\Queue\AsyncMessageInterface;

#[CoversClass(RecomputeFacts::class)]
final class RecomputeFactsTest extends TestCase
{
    /** It rides the queue: the worker files the figures, never the request that sent it. */
    public function testItIsQueued(): void
    {
        self::assertInstanceOf(AsyncMessageInterface::class, new RecomputeFacts('patrols', ['2026-09']));
    }

    public function testItNamesTheModuleTheMonthsAndOptionallyOneSubject(): void
    {
        $message = new RecomputeFacts('patrols', ['2026-09', '2026-08', '2026-09'], 'zone-1');

        self::assertSame('patrols', $message->moduleSlug);
        self::assertSame(['2026-09', '2026-08'], $message->monthKeys, 'a month asked twice is asked once');
        self::assertSame('zone-1', $message->subjectUuid);
        self::assertNull(new RecomputeFacts('patrols', ['2026-09'])->subjectUuid);
    }

    public function testTheMonthOfAnInstantIsOneKey(): void
    {
        $message = RecomputeFacts::forMonthOf('patrols', new \DateTimeImmutable('2026-08-31 23:50:00+00:00'));

        self::assertSame(['2026-08'], $message->monthKeys);
    }

    public function testItRefusesAnEmptyModuleNoMonthsOrAKeyThatIsNotAMonth(): void
    {
        $refused = 0;
        foreach ([
            static fn () => new RecomputeFacts('', ['2026-09']),
            static fn () => new RecomputeFacts('patrols', []),
            static fn () => new RecomputeFacts('patrols', ['2026-Q3']),
            static fn () => new RecomputeFacts('patrols', ['2026-13']),
        ] as $make) {
            try {
                $make();
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }

        self::assertSame(4, $refused);
    }
}
