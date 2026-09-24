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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * THE DEPARTMENTS SECTION OF AN AREA'S CONFIGURE PAGE — where this area's own
 * departments are added and edited, and the only place any of that happens.
 *
 * THE SCOPE IS LOCKED, not chosen: a department created here belongs to this
 * area, and an organization-wide one is created on the organization's
 * register. An org-wide department is LISTED here and carries no control — a
 * shared object is not edited from one area's context.
 *
 * THE WRITES ARE THE ONES THE REGISTER ALREADY MAKES, through the same routes,
 * so the trail reads the same whichever door was used; what this section adds
 * is where they return to.
 */
#[CoversClass(AreaDepartmentController::class)]
final class AreaDepartmentsConfigureTest extends WebTestCaseWithSchema
{
    /** The add card asks for a name and nothing else: the scope is this area. */
    public function testTheAddCardLocksTheScopeToThisArea(): void
    {
        [$crawler, $area] = $this->section();

        $form = $crawler->filter('form.crcard');

        self::assertCount(1, $form);
        self::assertSame('area', $form->filter('input[name="scope"]')->attr('value'));
        self::assertSame($area->getUuidString(), $form->filter('input[name="area"]')->attr('value'));
        self::assertStringContainsString($area->getName(), $form->filter('.dplock')->text());
        // No scope chooser: there is nothing to choose.
        self::assertCount(0, $form->filter('.seg'));
    }

    /** This area's own are editable; the org-wide ones are rows, not cards. */
    public function testOnlyTheAreasOwnAreEditable(): void
    {
        [$crawler] = $this->section();

        self::assertSame(
            ['Wetland Management'],
            $crawler->filter('.dcard .ov-nm')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame(
            ['Ecology'],
            $crawler->filter('.dprow .dprn')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertCount(0, $crawler->filter('.dprow form'));
    }

    /** An org-wide row says where it is configured, and links there. */
    public function testAnOrgWideRowPointsAtTheOrganizationsRegister(): void
    {
        [$crawler] = $this->section();
        $row = $crawler->filter('.dprow')->first();

        self::assertStringContainsString('Configured at the organization', $row->text());
        self::assertStringContainsString('/departments', (string) $row->filter('a.softbtn')->attr('href'));
    }

    /** Created here, it belongs to this area — and the section is where it lands. */
    public function testCreatingFromHereConfinesTheDepartmentToThisArea(): void
    {
        [$crawler, $area] = $this->section();

        $this->client->submit($crawler->filter('form.crcard')->form(['name' => 'Wildlife Vet']));

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/departments/settings');

        $made = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Wildlife Vet']);
        self::assertInstanceOf(Department::class, $made);
        self::assertSame($area->getUuidString(), $made->getArea()?->getUuidString());
    }

    /** Renaming from here returns here, not to the organization's register. */
    public function testAWriteReturnsToTheSection(): void
    {
        [$crawler, $area] = $this->section();

        $this->client->submit($crawler->filter('.dcard form.dc-renameform')->form(['name' => 'Wetlands']));

        self::assertResponseRedirects('/areas/'.$area->getUuidString().'/departments/settings');
    }

    /** @return array{0: Crawler, 1: HostArea} */
    private function section(): array
    {
        $this->administrator();
        $north = $this->area('Northern Reserve');
        $south = $this->area('Southern Reserve');

        $this->areaDepartment('Wetland Management', $north);
        $this->areaDepartment('Coastal Watch', $south);
        $this->department('Ecology');
        $this->em->flush();

        return [
            $this->client->request('GET', '/areas/'.$north->getUuidString().'/departments/settings'),
            $north,
        ];
    }
}
