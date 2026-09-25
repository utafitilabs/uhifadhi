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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Unit\Facts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactProviders;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\TallyFactProvider;

/**
 * THE COLLECTING END OF THE FACTS SEAM: every tagged provider, the figures
 * they declared, and one owner per figure.
 */
#[CoversClass(FactProviders::class)]
final class FactProvidersTest extends TestCase
{
    public function testEveryProviderIsCollectedInRegistrationOrder(): void
    {
        $providers = new FactProviders([new SurveyFactProvider(), new TallyFactProvider()]);

        self::assertSame(['surveys', 'tallies'], array_map(
            static fn ($provider): string => $provider->moduleSlug(),
            $providers->all(),
        ));
    }

    public function testOneModuleIsSelectedBySlug(): void
    {
        $providers = new FactProviders([new SurveyFactProvider(), new TallyFactProvider()]);

        self::assertCount(1, $providers->all('tallies'));
        self::assertSame([], $providers->all('nobody'));
    }

    public function testAFigureIsLookedUpByItsKey(): void
    {
        $providers = new FactProviders([new SurveyFactProvider(), new TallyFactProvider()]);

        self::assertTrue($providers->definition('surveys.metres')?->additive);
        self::assertFalse($providers->definition('surveys.share')?->additive);
        self::assertNull($providers->definition('surveys.unknown'));
        self::assertSame(['surveys.metres', 'surveys.share', 'tallies.count'], array_keys($providers->definitions()));
    }

    public function testAFigureDeclaredTwiceIsRefused(): void
    {
        $providers = new FactProviders([new SurveyFactProvider(), new SurveyFactProvider()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('surveys.metres');

        $providers->definitions();
    }

    public function testNoProvidersIsAnEmptySeam(): void
    {
        $providers = new FactProviders();

        self::assertSame([], $providers->all());
        self::assertSame([], $providers->definitions());
    }
}
