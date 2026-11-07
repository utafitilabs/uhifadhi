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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Bundle\TeamBundle\DependencyInjection\TeamConfiguration;

/** The nightly permissions run is at two in the morning unless the deployment says otherwise, and a schedule must be a cron expression. */
#[CoversClass(TeamConfiguration::class)]
final class TeamConfigurationTest extends TestCase
{
    public function testTheNightlyRunDefaultsToTwoInTheMorning(): void
    {
        $config = $this->process([]);

        self::assertSame(['schedule' => '0 2 * * *', 'timezone' => null], $config['permissions']);
    }

    public function testADeploymentSaysWhenAndInWhichZone(): void
    {
        $config = $this->process(['permissions' => ['schedule' => '30 3 * * *', 'timezone' => 'Africa/Dar_es_Salaam']]);

        self::assertSame(['schedule' => '30 3 * * *', 'timezone' => 'Africa/Dar_es_Salaam'], $config['permissions']);
    }

    public function testAScheduleThatIsNotACronExpressionIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['permissions' => ['schedule' => 'every night']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $tree = new TreeBuilder('team');
        TeamConfiguration::define($tree->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($tree->buildTree(), [$config]);

        return $processed;
    }
}
