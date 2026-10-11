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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentVisibilityEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionChangeEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * WHO OUTSIDE A DEPARTMENT SEES IT (ruled 12 Oct 2026, #74, design B). A
 * department is Private — read by who belongs to it, who supports it, and
 * whoever reaches the whole organization, as before — or Listed, its row in
 * the register naming it, its area and its head to everybody who reads
 * departments while its record stays closed to them, or Open, its record read
 * by everybody who reads departments. It is set with the scope, in one card,
 * with one reason, and the change is a line in the permission history.
 */
final class DepartmentVisibilityTest extends WebTestCaseWithSchema
{
    private Department $operations;
    private Department $ict;
    private HostArea $kilimani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kilimani = $this->area('Kilimani');
        $this->operations = $this->department('Operations');
        $this->ict = $this->department('ICT');
        $this->em->flush();
    }

    public function testANewDepartmentIsPrivate(): void
    {
        self::assertSame(DepartmentVisibilityEnum::Private, $this->ict->getVisibility());
    }

    public function testAPrivateDepartmentIsNeitherListedNorReadBySomebodyOutsideIt(): void
    {
        $this->client->loginUser($this->outsider());

        $register = $this->client->request('GET', '/departments');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ICT', $register->filter('table')->text());

        $this->client->request('GET', '/departments/'.$this->ict->getUuidString());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAListedDepartmentIsInTheRegisterButItsRecordStaysClosed(): void
    {
        $this->ict->setVisibility(DepartmentVisibilityEnum::Listed);
        $this->em->flush();
        $this->client->loginUser($this->outsider());

        $register = $this->client->request('GET', '/departments');
        $row = $register->filter('tr[data-outside]');
        self::assertCount(1, $row, 'one listed department the reader does not belong to');
        self::assertStringContainsString('ICT', $row->text());
        self::assertStringContainsString('not yours to open', $row->text());
        self::assertCount(0, $row->filter('a[href="/departments/'.$this->ict->getUuidString().'"]'), 'and no door to the record');

        $this->client->request('GET', '/departments/'.$this->ict->getUuidString());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOpenDepartmentIsReadByEverybodyWhoReadsDepartments(): void
    {
        $this->ict->setVisibility(DepartmentVisibilityEnum::Open);
        $this->em->flush();
        $this->client->loginUser($this->outsider());

        $register = $this->client->request('GET', '/departments');
        self::assertCount(0, $register->filter('tr[data-outside]'), 'an open department is an ordinary row');
        self::assertStringContainsString('ICT', $register->filter('table')->text());

        $this->client->request('GET', '/departments/'.$this->ict->getUuidString());
        self::assertResponseIsSuccessful();
    }

    public function testTheScopeCardChangesWhoSeesItWithAReasonAndLeavesALine(): void
    {
        $this->administrator();
        $form = $this->configure($this->ict)->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['visibility'] = 'listed';
        $form['reason'] = 'Others coordinate with ICT and should know it exists.';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments/'.$this->ict->getUuidString().'/configure');
        $this->em->clear();
        $ict = $this->em->getRepository(Department::class)->find($this->ict->getId());
        self::assertInstanceOf(Department::class, $ict);
        self::assertSame(DepartmentVisibilityEnum::Listed, $ict->getVisibility());
        self::assertTrue($ict->isOrgLevel(), 'the scope it already had is no change');

        $lines = $this->em->getRepository(PermissionChange::class)->findBy(['action' => PermissionChangeEnum::VisibilityChanged]);
        self::assertCount(1, $lines);
        self::assertSame(['Private', 'Listed'], [$lines[0]->getBefore(), $lines[0]->getAfter()]);
        self::assertSame('Others coordinate with ICT and should know it exists.', $lines[0]->getReason());
    }

    public function testAChangeOfWhoSeesItWithNoReasonIsRefused(): void
    {
        $this->administrator();
        $form = $this->configure($this->ict)->filter('form[action$="/scope"]')->selectButton('Save scope and visibility')->form();
        $form['visibility'] = 'open';
        $form['reason'] = ' ';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a reason', $crawler->filter('[data-shell-flash]')->text());
        $this->em->clear();
        self::assertSame(DepartmentVisibilityEnum::Private, $this->em->getRepository(Department::class)->find($this->ict->getId())?->getVisibility());
    }

    /** Somebody in Operations at Kilimani who reads departments, and belongs to no other. */
    private function outsider(): User
    {
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($this->position('Reader', ['departments.read']));
        $this->place($grace, [$this->kilimani], [$this->operations]);
        $this->em->flush();

        return $grace;
    }

    private function configure(Department $department): Crawler
    {
        $crawler = $this->client->request('GET', '/departments/'.$department->getUuidString().'/configure');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
