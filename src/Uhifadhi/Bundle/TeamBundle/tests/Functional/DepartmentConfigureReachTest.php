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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\User;

/**
 * A DEPARTMENT'S CONFIGURE PAGE ASKS WHAT ITS SAVES ASK: `departments.configure`,
 * and a reach that covers the department — an area-bound holder reaches the
 * departments of their own area and no organization-wide one (§5.6). The
 * record draws its Configure door on the same answer, so it never offers a
 * page that refuses.
 */
final class DepartmentConfigureReachTest extends WebTestCaseWithSchema
{
    private Department $operations;
    private Department $kilimaniRangers;
    private User $warden;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $kilimani = $this->area('Kilimani');
        $this->operations = $this->department('Operations');
        $this->kilimaniRangers = $this->areaDepartment('Field Patrol', $kilimani);

        $this->warden = $this->person('Joseph', 'Mrema');
        $this->warden->setPosition($this->position('Area Warden', ['departments.read', 'departments.configure']));
        $this->place($this->warden, [$kilimani], [$this->kilimaniRangers]);

        $this->reader = $this->person('Grace', 'Ndosi');
        $this->reader->setPosition($this->position('Analyst', ['departments.read']));
        $this->place($this->reader, null, [$this->operations]);

        $this->em->flush();
    }

    public function testAnAreaBoundHolderConfiguresTheirOwnAreasDepartment(): void
    {
        $this->client->loginUser($this->warden);

        $this->client->request('GET', $this->configurePath($this->kilimaniRangers));
        self::assertResponseIsSuccessful();

        $record = $this->client->request('GET', '/departments/'.$this->kilimaniRangers->getUuidString());
        self::assertCount(1, $record->filter('a[href="'.$this->configurePath($this->kilimaniRangers).'"]'), 'the record offers the door that opens');
    }

    public function testAnAreaBoundHolderDoesNotConfigureAnOrganizationWideDepartment(): void
    {
        $this->client->loginUser($this->warden);

        $this->client->request('GET', $this->configurePath($this->operations));
        self::assertResponseStatusCodeSame(403);

        $record = $this->client->request('GET', '/departments/'.$this->operations->getUuidString());
        self::assertCount(0, $record->filter('a[href="'.$this->configurePath($this->operations).'"]'), 'and its record offers no door to it');
    }

    public function testAReaderDoesNotConfigure(): void
    {
        $this->client->loginUser($this->reader);

        $this->client->request('GET', $this->configurePath($this->operations));
        self::assertResponseStatusCodeSame(403);

        $record = $this->client->request('GET', '/departments/'.$this->operations->getUuidString());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $record->filter('a[href="'.$this->configurePath($this->operations).'"]'));
    }

    private function configurePath(Department $department): string
    {
        return '/departments/'.$department->getUuidString().'/configure';
    }
}
