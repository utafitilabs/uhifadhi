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

namespace Uhifadhi\Core\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * THE STATUS DROPDOWN ON THE PEOPLE REGISTER, END TO END: the area records
 * the day, the team draws the register, and the two meet only through the
 * `uhifadhi.people_facets` seam — read here over HTTP, against real
 * check-ins, in the core as one installation.
 */
#[CoversNothing]
final class PeopleRegisterStatusFacetTest extends WebTestCase
{
    private const string BOUNDARY = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testTheStatusDropdownDrawsTodaysCheckInsAndFiltersTheRegisterAndItsExport(): void
    {
        $area = $this->area('Sample Area');
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $asha = $this->person('Asha', 'Mollel');
        $baraka = $this->person('Baraka', 'Sanka');
        $this->claim($area, $asha, 'at_post');
        $this->claim($area, $baraka, 'unfit');
        $this->client->loginUser($naomi);

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['position', 'department', 'station', 'rank', 'status', 'account'], $crawler->filter('.tm-tools details.i-dd')->each(static fn (Crawler $d): string => (string) $d->attr('data-facet')));
        $facet = $crawler->filter('details.i-dd[data-facet="status"]');
        self::assertSame('any status', trim($facet->filter('.i-ddval')->text()));
        self::assertSame(
            ['Any 3', 'At post 1', 'Unfit for duty 1', 'Outside the park 0', 'Special assignment 0', 'No check-in today 1'],
            $facet->filter('a.i-ddopt')->each(static fn (Crawler $a): string => trim($a->filter('.i-ddopt-l')->text()).' '.trim($a->filter('.i-ddopt-n')->text())),
        );

        $atPost = $this->client->request('GET', '/team?status=at_post');
        self::assertSame(['Asha Mollel'], $atPost->filter('table.tbl tbody .who b')->each(static fn (Crawler $b): string => $b->text()));
        self::assertSame('At post', trim($atPost->filter('details[data-facet="status"] .i-ddval')->text()));

        $none = $this->client->request('GET', '/team?status=none');
        self::assertSame(['Naomi Kileo'], $none->filter('table.tbl tbody .who b')->each(static fn (Crawler $b): string => $b->text()));

        $this->client->request('GET', '/team/people.csv?status=unfit');
        self::assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent())));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('"Baraka Sanka"', $lines[1]);
    }

    public function testWithoutAnAreaTheRegisterOffersNoStatusDropdown(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        // TWO PEOPLE, so the register is past its first run and draws its bar.
        $this->person('Asha', 'Mollel');
        $this->client->loginUser($naomi);

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['position', 'department', 'station', 'rank', 'account'], $crawler->filter('.tm-tools details.i-dd')->each(static fn (Crawler $d): string => (string) $d->attr('data-facet')));
    }

    private function area(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName($name)->setGeom(self::BOUNDARY)->setSource('WDPA');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $person = new User()
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole($tier)->setVerified(true);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    /** A CLAIM MADE TODAY — the installation's clock is the wall clock, so the day is today's. */
    private function claim(AreaOfInterest $area, User $person, string $statusKey): void
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $status = null;
        foreach ($statuses->offeredBy($area) as $one) {
            if ($statusKey === $one->getKey()) {
                $status = $one;
            }
        }
        self::assertInstanceOf(CheckInStatus::class, $status);

        $now = new \DateTimeImmutable();
        $checkIn = new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef(bin2hex(random_bytes(8)))
            ->setLocalDate($now->setTime(0, 0))
            ->setStatus($status)
            ->setOccurredAt($now)
            ->setDeviceId('0f9ca41e')
            ->setAppVersion('0.1.0');
        $this->em->persist($checkIn);
        $this->em->flush();
    }
}
