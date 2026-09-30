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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\DeletionRecord;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * A SUPER ADMIN DELETES WHAT WAS MADE BY MISTAKE OR IN TESTS (ruled 28 Sep,
 * #48): a station, an area, a position, a department - each on the one delete
 * page, what goes counted first, the name typed, one audit line kept. The
 * installed core, every bundle's contributor together.
 */
final class SuperAdminDeletesTest extends FieldApiTestCase
{
    private User $naomi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->naomi = $this->officeStaff('Naomi', 'Kileo')->setTeamRole(TeamRoleEnum::SuperAdmin);
        $this->em->flush();
    }

    public function testAStationGoesWithItsPostingsAndTheCheckInsStay(): void
    {
        $area = $this->area('Kilimani Game Reserve');
        $gate = $this->station($area, 'North Gate Post', -65.0, -3.2);
        $this->postTo($gate, $this->officeStaff('Grace', 'Ndosi'));

        $page = $this->pageFor('/areas/'.$area->getUuidString().'/stations/'.$gate->getUuidString().'/delete');
        self::assertStringContainsString('Delete station North Gate Post', $page->filter('h1')->text());
        self::assertStringContainsString('posting · standing and ended', $page->filter('.dcounts')->text());

        $this->confirm($page, 'North Gate Post');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Station::class)->findOneBy(['name' => 'North Gate Post']));
        self::assertSame([], $this->em->getRepository(Posting::class)->findAll());
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']), 'the person stays');
        self::assertSame('Station North Gate Post · Kilimani Game Reserve', $this->lastLine()->getTitle());
    }

    public function testAnAreaGoesWithItsStationsAndTheDepartmentsConfinedToIt(): void
    {
        $area = $this->area('Tambarare Game Reserve');
        $this->station($area, 'Dip Post', -65.0, -3.2);
        $this->areaDepartment('Tambarare Rangers', $area);

        $page = $this->pageFor('/areas/'.$area->getUuidString().'/delete');
        self::assertSame(['area · and its boundary', 'station · with their postings', 'department confined to it'], $page->filter('.dcounts .dcnt span')->each(static fn ($c): string => $c->text()), 'the area itself first');

        $this->confirm($page, 'Tambarare Game Reserve');

        $this->em->clear();
        self::assertNull($this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Tambarare Game Reserve']));
        self::assertSame([], $this->em->getRepository(Station::class)->findAll());
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Tambarare Rangers']));
    }

    public function testAPositionGoesAndItsHolderStaysHoldingNone(): void
    {
        $grace = $this->officeStaff('Grace', 'Ndosi');
        $seat = new Position()->setName('Sergeant');
        $seat->setGrantValues(['directory.read'], ['directory.read']);
        $this->em->persist($seat);
        $grace->setPosition($seat);
        $this->em->flush();

        $page = $this->pageFor('/team/positions/'.$seat->getUuidString().'/delete');
        self::assertStringContainsString('hold no position', $page->text());

        $this->confirm($page, 'Sergeant');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Position::class)->findOneBy(['name' => 'Sergeant']));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test'])?->getPosition());
    }

    public function testADepartmentGoesAndItsPeopleStay(): void
    {
        $area = $this->area('Kilimani Game Reserve');
        $department = $this->areaDepartment('Ecology', $area);

        $this->confirm($this->pageFor('/departments/'.$department->getUuidString().'/delete'), 'Ecology');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
        self::assertSame('Department Ecology', $this->lastLine()->getTitle());
    }

    public function testAnAdminIsRefusedEveryDeletePage(): void
    {
        $area = $this->area('Kilimani Game Reserve');
        $admin = $this->officeStaff('Desta', 'Haile')->setTeamRole(TeamRoleEnum::Admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/areas/'.$area->getUuidString().'/delete');

        self::assertResponseStatusCodeSame(403);
    }

    private function pageFor(string $url): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->naomi);
        $page = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function confirm(\Symfony\Component\DomCrawler\Crawler $page, string $reference): void
    {
        $this->client->submit($page->filter('form.dconfirm')->form(['reference' => $reference]));
        self::assertResponseRedirects();
    }

    private function lastLine(): DeletionRecord
    {
        $lines = $this->em->getRepository(DeletionRecord::class)->findBy([], ['id' => 'DESC'], 1);
        self::assertCount(1, $lines);

        return $lines[0];
    }
}
