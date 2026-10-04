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
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * A DEPARTMENT'S RECORD IS READ ON NEED TO KNOW. `departments.read` opens the
 * record of a department the reader belongs to or supports; somebody placed
 * across the organization reads every one, and so does somebody who may
 * configure it. Anybody else is refused.
 */
final class DepartmentReadReachTest extends WebTestCaseWithSchema
{
    private Department $operations;
    private Department $ict;
    private Department $fieldPatrol;
    private Department $tourism;
    private HostArea $kilimani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kilimani = $kilimani = $this->area('Kilimani');
        $this->operations = $this->department('Operations');
        $this->ict = $this->department('ICT');
        $this->fieldPatrol = $this->areaDepartment('Field Patrol', $kilimani);
        $this->tourism = $this->areaDepartment('Visitor Services', $kilimani);
        $this->em->flush();
    }

    public function testAMemberReadsTheirOwnDepartmentAndNoOther(): void
    {
        $member = $this->reader('Grace', 'Ndosi', [$this->operations]);

        $this->client->loginUser($member);
        $this->client->request('GET', $this->recordPath($this->operations));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->recordPath($this->ict));
        self::assertResponseStatusCodeSame(403);
    }

    public function testASupporterReadsTheDepartmentTheySupport(): void
    {
        $supporter = $this->reader('Joseph', 'Mrema', [$this->operations, $this->ict]);

        $this->client->loginUser($supporter);
        $this->client->request('GET', $this->recordPath($this->ict));
        self::assertResponseIsSuccessful();
    }

    public function testSomebodyPlacedAcrossTheOrganizationReadsEveryDepartment(): void
    {
        $planner = $this->person('Rehema', 'Kimaro');
        $planner->setPosition($this->position('Planner', ['departments.read']));
        $this->place($planner, null, [$this->operations]);
        $this->em->flush();

        $this->client->loginUser($planner);
        foreach ([$this->ict, $this->fieldPatrol] as $department) {
            $this->client->request('GET', $this->recordPath($department));
            self::assertResponseIsSuccessful((string) $department->getName());
        }
    }

    public function testSomebodyWhoMayConfigureADepartmentReadsIt(): void
    {
        $warden = $this->person('Baraka', 'Laizer');
        $warden->setPosition($this->position('Area Warden', ['departments.read', 'departments.configure']));
        $this->place($warden, [$this->kilimani], [$this->fieldPatrol]);
        $this->em->flush();

        $this->client->loginUser($warden);
        $this->client->request('GET', $this->recordPath($this->tourism));
        self::assertResponseIsSuccessful('a department in their own area, which they may configure');

        $this->client->request('GET', $this->recordPath($this->ict));
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<Department> $departments their own first, then those they support */
    private function reader(string $first, string $last, array $departments): User
    {
        $person = $this->person($first, $last);
        $person->setPosition($this->position($first.' Reader', ['departments.read']));
        $this->place($person, [$this->kilimani], $departments);
        $this->em->flush();

        return $person;
    }

    private function recordPath(Department $department): string
    {
        return '/departments/'.$department->getUuidString();
    }
}
