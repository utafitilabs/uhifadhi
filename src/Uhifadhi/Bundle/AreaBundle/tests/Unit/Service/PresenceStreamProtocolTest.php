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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceStreamService;

/**
 * THE PLATE SUBSCRIBES IN THE PROTOCOL ITS HUB SPEAKS. A hub on Mercure 1.0
 * takes the topics to hold open as `match` parameters, a 0.x hub as
 * `topic`; the installation says which in its `mercure.yaml`
 * (`protocol_version`), so a core release never has to wait for every
 * installation's hub to move.
 *
 * @see https://mercure.rocks/spec — "the bare `match` parameter selects the default `exact` matcher type"
 */
final class PresenceStreamProtocolTest extends TestCase
{
    public function testAZeroXHubIsSubscribedWithTopic(): void
    {
        self::assertSame('topic', $this->streamParameter(ProtocolVersion::Legacy));
    }

    public function testAOnePointZeroHubIsSubscribedWithMatch(): void
    {
        self::assertSame('match', $this->streamParameter(ProtocolVersion::V1));
    }

    private function streamParameter(ProtocolVersion $version): string
    {
        $hub = new MockHub(
            'https://parks.example.test/.well-known/mercure',
            new StaticTokenProvider('publisher'),
            static fn (): string => 'id',
            new LcobucciFactory('a-secret-of-at-least-two-hundred-and-fifty-six-bits!!'),
            protocolVersion: $version,
        );
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);
        $area = new AreaOfInterest()->setName('Kilimani')->setUuid(Uuid::v7());

        $service = new PresenceStreamService(
            $hub,
            new Authorization(new HubRegistry($hub)),
            $checker,
            $this->createStub(AreaOfInterestRepository::class),
        );
        $subscription = $service->forArea(Request::create('https://parks.example.test/areas'), $area);
        self::assertNotNull($subscription);

        return $subscription->stream->toArray()['parameter'];
    }
}
