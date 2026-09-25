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
use Uhifadhi\Bundle\RegistryBundle\MessageHandler\RecomputeFactsHandler;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\TallyFactProvider;
use Uhifadhi\Contracts\Facts\RecomputeFacts;

#[CoversClass(RecomputeFactsHandler::class)]
final class RecomputeFactsTest extends FactsTestCase
{
    /**
     * A module names its months; that module's provider, and no other, is asked for
     * them and the longer periods they fall in. That the message rides the queue is
     * the marker's contract, proven once for every such message in QueuedFactsTest.
     */
    public function testItAsksOneModuleForTheMonthsNamed(): void
    {
        $this->handle(new RecomputeFacts('surveys', ['2026-08']));

        self::assertSame([
            '2026-08: surveys.metres, surveys.share',
            '2026-Q3: surveys.share',
            '2026: surveys.share',
        ], self::asked(SurveyFactProvider::$asked));
        self::assertSame([], self::asked(TallyFactProvider::$asked), 'another module is not asked');
        self::assertSame(800.0, $this->stored(SurveyFactProvider::NORTH, 'surveys.metres', '2026-08'), 'a closed month is computed when a module asks');
    }

    private function handle(RecomputeFacts $message): void
    {
        $handler = self::getContainer()->get('registry.facts.recompute_module_handler');
        self::assertInstanceOf(RecomputeFactsHandler::class, $handler);
        $handler($message);
    }
}
