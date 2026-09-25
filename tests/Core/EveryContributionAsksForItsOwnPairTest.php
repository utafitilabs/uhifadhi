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

namespace Uhifadhi\Core\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * A PAGE IS ASSEMBLED FROM CONTRIBUTIONS, AND EACH ONE ASKS BEFORE IT DRAWS.
 *
 * The shell holds no authorization service, so what reaches the browser is
 * decided contribution by contribution: a sidebar row, a tab, a configure
 * section, a plate on somebody's record. These are asked through the real
 * grant voter, with composed positions and real placements, because the
 * questions that matter here are about the ground — an area the viewer is not
 * placed at — and only the installation's own voter asks that one.
 *
 * Every assertion is about markup a browser received: absent, never hidden.
 */
#[CoversNothing]
final class EveryContributionAsksForItsOwnPairTest extends WebTestCase
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

    /**
     * AN AREA IN THE SIDEBAR IS A DOOR TO THAT AREA. Somebody placed at one
     * area holds `areas.read` there and nowhere else, so the tree names the
     * one and not the other.
     */
    public function testTheSidebarNamesOnlyTheAreasTheViewerIsPlacedAt(): void
    {
        $here = $this->area('Kilimani Crater');
        $this->area('Mbuyu Plains');
        $this->signIn($this->composed(['areas.read'], new Placement()->inAreas([$here])->acrossAllDepartments()));

        $nav = $this->nav($this->client->request('GET', '/areas/'.$here->getUuidString()));

        self::assertStringContainsString('Kilimani Crater', $nav);
        self::assertStringNotContainsString('Mbuyu Plains', $nav);
    }

    /** The dashboard's row asks what the dashboard enforces. */
    public function testTheDashboardRowIsWithheldFromSomebodyWhoCannotReadTheAreas(): void
    {
        $this->signIn($this->composed(['directory.read']));

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertNotContains('/', $crawler->filter('nav.nav a.nav-item')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertStringNotContainsString('Dashboard', $this->nav($crawler));
    }

    /**
     * AN AREA'S TABS EACH ASK THEIR ROUTE'S PAIR. Somebody who may reach the
     * area and nothing in it is offered its Overview and no other screen —
     * neither in the strip nor under the area in the tree.
     */
    public function testAnAreasTabsAreOnlyTheOnesTheViewerHolds(): void
    {
        $area = $this->area('Kilimani Crater');
        $this->signIn($this->composed(['areas.read']));

        $crawler = $this->client->request('GET', '/areas/'.$area->getUuidString());

        self::assertResponseIsSuccessful();
        // ONE TAB IS NO STRIP AT ALL, so what is asserted is that nothing but
        // the Overview could be in it.
        self::assertSame([], array_diff($crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())), ['Overview']));
        foreach (['/zones', '/stations', '/departments', '/modules'] as $screen) {
            self::assertStringNotContainsString('/areas/'.$area->getUuidString().$screen.'"', $this->nav($crawler), $screen);
        }
    }

    /** And the Departments tab comes with its own pair, asked without the area as its route asks it. */
    public function testTheDepartmentsTabComesWithItsOwnPair(): void
    {
        $area = $this->area('Kilimani Crater');
        $this->signIn($this->composed(['areas.read', 'departments.read']));

        $crawler = $this->client->request('GET', '/areas/'.$area->getUuidString());

        self::assertSame(['Overview', 'Departments'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    /**
     * A CONFIGURE SECTION IS OFFERED ONLY TO WHOEVER HOLDS ITS SCREEN'S PAIR:
     * the area's own sections, and the one the departments contribute.
     */
    public function testTheAreasConfigureStripCarriesOnlyTheSectionsTheViewerHolds(): void
    {
        $area = $this->area('Kilimani Crater');
        $this->signIn($this->composed(['areas.read', 'zones.read']));

        $crawler = $this->client->request('GET', '/areas/'.$area->getUuidString().'/configure/widgets');

        self::assertResponseIsSuccessful();
        self::assertSame(['Widget library', 'Zones'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    /** With the departments' pair, their section joins the strip. */
    public function testTheDepartmentsSectionJoinsTheStripWithItsPair(): void
    {
        $area = $this->area('Kilimani Crater');
        $this->signIn($this->composed(['areas.read', 'departments.read']));

        $crawler = $this->client->request('GET', '/areas/'.$area->getUuidString().'/configure/widgets');

        self::assertSame(['Widget library', 'Departments'], $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    /**
     * THE GROUND AROUND A STATION ON A PERSON'S RECORD IS THE AREA'S TO DRAW,
     * and it asks `stations.read` of that area: a directory reader sees where
     * somebody is stationed by name, gets no plate, and no door to the
     * station's record.
     */
    public function testAPersonsRecordCarriesNoStationPlateOrDoorWithoutStationsRead(): void
    {
        [$person, $station, $area] = $this->aStationedPerson();
        $this->signIn($this->composed(['directory.read']));

        $this->client->request('GET', '/team/'.$person->getUuidString());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringNotContainsString('Eastgate Post and the ground around it', $body);
        self::assertStringNotContainsString('/areas/'.$area->getUuidString().'/stations/'.$station->getUuidString(), $body);
    }

    /** With the pair, the plate and the door are both drawn. */
    public function testAPersonsRecordCarriesTheStationPlateAndDoorWithStationsRead(): void
    {
        [$person, $station, $area] = $this->aStationedPerson();
        $this->signIn($this->composed(['directory.read', 'stations.read']));

        $this->client->request('GET', '/team/'.$person->getUuidString());

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Eastgate Post and the ground around it', $body);
        self::assertStringContainsString('/areas/'.$area->getUuidString().'/stations/'.$station->getUuidString(), $body);
    }

    /** @return array{User, Station, AreaOfInterest} */
    private function aStationedPerson(): array
    {
        $area = $this->area('Kilimani Crater');

        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');

        $person = new User()
            ->setEmail('amani.stationed@example.test')
            ->setFirstName('Amani')->setLastName('Stationed')
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true);
        $this->em->persist($person);
        $this->em->flush();

        /** @var PostingService $postings */
        $postings = static::getContainer()->get('test_public.area.postings');
        $postings->post($station, $person, PostingSource::WrittenHere);

        return [$person, $station, $area];
    }

    /**
     * A position granting exactly these pairs, held by somebody placed — by
     * default across the whole organization and every department, so that a
     * refusal can only have come from the position.
     *
     * @param list<string> $pairs
     */
    private function composed(array $pairs, ?Placement $placement = null): User
    {
        $placement ??= new Placement()->acrossTheOrganization()->acrossAllDepartments();

        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);

        $position = new Position()
            ->setName('Composed '.substr(md5(implode('|', $pairs)), 0, 10))
            ->setGrantValues($pairs, $catalogue->pairs());
        $this->em->persist($position);
        $this->em->persist($placement);

        $person = new User()
            ->setEmail(bin2hex(random_bytes(8)).'@example.test')
            ->setFirstName('Composed')->setLastName('Holder')
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true)
            ->setPosition($position)
            ->setPlacement($placement);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    private function signIn(User $person): void
    {
        $this->client->loginUser($person);
    }

    private function area(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName($name)->setGeom(self::BOUNDARY)->setSource('WDPA');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    private function nav(Crawler $crawler): string
    {
        self::assertResponseIsSuccessful();

        return implode("\n", $crawler->filter('nav.nav')->each(static fn (Crawler $nav): string => (string) $nav->html()));
    }
}
