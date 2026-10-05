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

use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * A LIST OF DEPARTMENTS SHOWS THE ONES THE VIEWER MAY OPEN. A department's
 * record is read on need to know, so every page that lists departments lists
 * those records and no others: a row for a department the viewer may not read
 * would be a door to a refusal, and its name a fact they do not need.
 */
final class DepartmentListsReachTest extends WebTestCaseWithSchema
{
    private const string OWN = 'Operations';
    private const string OTHER = 'Wildlife Veterinary';

    private HostArea $kilimani;
    private Department $operations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kilimani = $this->area('Kilimani');
        $this->operations = $this->department(self::OWN);
        $this->department(self::OTHER);
        $this->em->flush();
    }

    /** @return iterable<string, array{string}> */
    public static function lists(): iterable
    {
        yield 'the register' => ['/departments'];
        yield 'the overview' => ['/departments/overview'];
        yield 'the modules matrix' => ['/departments/modules'];
        yield 'the performance page' => ['/departments/performance'];
        yield 'an area\'s departments' => ['/areas/{kilimani}/departments'];
    }

    #[DataProvider('lists')]
    public function testAMemberSeesTheirOwnDepartmentAndNotAnotherTheyMayNotOpen(string $path): void
    {
        $member = $this->person('Grace', 'Ndosi');
        $member->setPosition($this->position('Analyst', ['departments.read']));
        $this->place($member, [$this->kilimani], [$this->operations]);
        $this->em->flush();

        $main = $this->listed($member, $path);

        self::assertStringNotContainsString(self::OTHER, $main, 'a department this reader may not open is listed');
        self::assertStringContainsString(self::OWN, $main);
    }

    #[DataProvider('lists')]
    public function testAnAdminSeesEveryDepartment(string $path): void
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Admin);
        $this->em->flush();

        $main = $this->listed($admin, $path);

        self::assertStringContainsString(self::OWN, $main);
        self::assertStringContainsString(self::OTHER, $main);
    }

    private function listed(User $viewer, string $path): string
    {
        $this->client->loginUser($viewer);
        $crawler = $this->client->request('GET', str_replace('{kilimani}', (string) $this->kilimani->getUuidString(), $path));
        self::assertResponseIsSuccessful();

        return $crawler->filter('main')->text();
    }
}
