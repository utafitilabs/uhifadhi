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

use ApiPlatform\Metadata\Get;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Bundle\AreaBundle\Api\FieldRoster;
use Uhifadhi\Bundle\AreaBundle\Api\State\AreasMineProvider;
use Uhifadhi\Bundle\AreaBundle\ApiResource\AreasMine;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\DutyStationService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE AREAS DOCUMENT NAMES THE POSTED STATION (ruled 2026-09-27): check-in
 * and patrol start ask no station — the handset uses the person's standing
 * posting, so the document it caches at sign-in must name it.
 */
#[CoversClass(AreasMineProvider::class)]
final class AreasMinePostedStationTest extends WebTestCase
{
    public function testAPostedPersonIsToldTheirStationBesideTheirArea(): void
    {
        $this->boot();
        $area = $this->aLiveArea('Sample Reserve');
        $this->stations()->add($area, 'Northgate Post', -29.70, -3.10, 'ST-01');
        $post = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.20, 'ST-02');
        $this->em->flush();
        $person = $this->aPersonSignedIn();
        $this->postings()->post($post, $person, PostingSource::WrittenHere);
        $this->em->flush();

        $mine = $this->areasMine();

        self::assertSame($area->getUuidString(), $mine->postedAreaId);
        self::assertSame($post->getUuidString(), $mine->postedStationId, 'the station of the standing posting');
    }

    public function testSomebodyPostedNowhereIsToldNoStation(): void
    {
        $this->boot();
        $this->aLiveArea('Sample Reserve');
        $this->aPersonSignedIn();

        $mine = $this->areasMine();

        self::assertNull($mine->postedAreaId);
        self::assertNull($mine->postedStationId);
    }

    public function testAPostingInAnAreaTheyMayNotReadNamesNoStation(): void
    {
        $this->boot(['zones.read']);
        $area = $this->aLiveArea('Sample Reserve');
        $post = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.20, 'ST-02');
        $this->em->flush();
        $person = $this->aPersonSignedIn();
        $this->postings()->post($post, $person, PostingSource::WrittenHere);
        $this->em->flush();

        $mine = $this->areasMine();

        self::assertNull($mine->postedStationId, 'the pointer only ever points into what this account may open');
    }

    private function aPersonSignedIn(): HostUser
    {
        $person = new HostUser()->named('Naserian', 'Example');
        $this->em->persist($person);
        $this->em->flush();
        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken($person, 'main', ['ROLE_USER']));

        return $person;
    }

    private function areasMine(): AreasMine
    {
        $container = static::getContainer();
        /** @var AreaOfInterestRepository $areas */
        $areas = $container->get(AreaOfInterestRepository::class);
        /** @var StationRepository $stationRows */
        $stationRows = $container->get(StationRepository::class);
        /** @var PostingRepository $postings */
        $postings = $container->get(PostingRepository::class);
        /** @var AuthorizationCheckerInterface $authorization */
        $authorization = $container->get('security.authorization_checker');
        /** @var TokenStorageInterface $tokens */
        $tokens = $container->get('security.token_storage');
        $provider = new AreasMineProvider($areas, new FieldRoster($this->em), $authorization, new DutyStationService($stationRows), $postings, $tokens);

        return $provider->provide(new Get());
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }

    private function postings(): PostingService
    {
        /** @var PostingService $service */
        $service = static::getContainer()->get('test_public.area.postings');

        return $service;
    }
}
