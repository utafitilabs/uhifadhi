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
use Symfony\Component\DomCrawler\Form;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * ATTACHING A MODULE TO A DEPARTMENT — the lens control, and what reading it
 * changes.
 *
 * THE ATTACHMENT IS THE WHOLE OF THE LENS. A department computes no figures of
 * its own: the modules it attaches are what leads its overview, and every number
 * on its performance tab is one of those modules' KPIs. So this suite asks three
 * things of one act — the Settings control offers every module the installation
 * has and lights the attached ones, the overview and the performance strip read
 * that set, and the write is gated exactly as every other department write is.
 *
 * IT DECIDES WHO MAY USE THE MODULE. The access check asks whether the person
 * is placed in a department that runs the module whose permission is asked, so
 * once any department runs a module, everybody outside every department that
 * runs it is refused. The control's own copy says so, and this suite asserts it,
 * because an administrator switching a module on is changing who gets in.
 */
final class DepartmentModuleAttachmentTest extends WebTestCaseWithSchema
{
    // ---- the control lists what the installation has -----------------------

    /**
     * EVERY INSTALLED MODULE IS OFFERED. The control reads the registry's
     * catalogue — the intersection of the rows in the table and the providers
     * this container has — so a module nobody installed is not on the page and a
     * module that is installed cannot be missing from it.
     */
    public function testTheControlOffersEveryInstalledModule(): void
    {
        $crawler = $this->lens($this->seed());

        self::assertSame(
            ['Roster', 'Surveys'],
            $this->sorted($crawler->filter('tr[data-dept-attach] td b')->each(static fn (Crawler $c): string => trim($c->text()))),
        );
    }

    /** NOTHING ATTACHED reads as the drawn empty line, not as an absent card. */
    public function testADepartmentWithNothingAttachedSaysSo(): void
    {
        $crawler = $this->lens($this->seed());

        self::assertCount(0, $crawler->filter('tr[data-dept-attached]'));
        self::assertSame(['off', 'off'], $crawler->filter('tr[data-dept-attach] td .chip')->each(static fn (Crawler $c): string => trim($c->text())));
    }

    /** An ATTACHED module is a row of its own, with the detach control on it. */
    public function testAnAttachedModuleIsARowWithItsDetachControl(): void
    {
        $department = $this->seed();
        $this->attach($department, 'surveys');

        $crawler = $this->lens($department);

        $row = $crawler->filter('tr[data-dept-attached]');
        self::assertCount(1, $row);
        self::assertSame('Surveys', $row->filter('td b')->text());
        self::assertSame('Switch off', trim($row->filter('td.door button')->text()));

        // And it is no longer one of the modules left to attach.
        self::assertSame(
            ['Roster'],
            $crawler->filter('tr[data-dept-attach] td b')->each(static fn (Crawler $c): string => trim($c->text())),
        );
    }

    /**
     * THE COPY IS THE MODEL, VERBATIM: switching a module on lets this
     * department's people use it as their position grants, and refuses
     * everybody outside every department that runs it.
     */
    public function testTheControlSaysWhoMayUseAModuleOnceItIsSwitchedOn(): void
    {
        $crawler = $this->lens($this->seed());

        $copy = $crawler->filter('[data-dp-configure]')->text();
        self::assertStringNotContainsString('grants nothing', $copy);
        self::assertStringContainsString('people placed in this department, or supporting it, may use the module as far as their position grants', $copy);
        self::assertStringContainsString('people outside every department that runs it are refused', $copy);
        self::assertStringContainsString('While no department runs a module, the position alone decides', $copy);
        self::assertStringContainsString('lead', $copy);
    }

    // ---- the write ---------------------------------------------------------

    /** ATTACHING through the drawn control files the module under the department. */
    public function testAttachingAModuleFilesItUnderTheDepartment(): void
    {
        $department = $this->seed();

        $crawler = $this->lens($department);
        $this->client->submit($this->attachForm($crawler, 'Surveys'));

        self::assertResponseRedirects('/departments/'.$department->getUuidString());

        $this->em->clear();
        self::assertSame(['surveys'], $this->attachedSlugs($department));
    }

    /** AND DETACHING takes it back off, leaving the department where it started. */
    public function testDetachingAModuleTakesItBackOff(): void
    {
        $department = $this->seed();
        $this->attach($department, 'surveys');

        $crawler = $this->lens($department);
        $this->client->submit($crawler->filter('tr[data-dept-attached]')->selectButton('Switch off')->form());

        self::assertResponseRedirects('/departments/'.$department->getUuidString());

        $this->em->clear();
        self::assertSame([], $this->attachedSlugs($department));
    }

    /** THE FLASH SAYS WHICH WAY IT WENT, and who may use the module now. */
    public function testTheFlashNamesTheModuleAndWhoMayUseItNow(): void
    {
        $department = $this->seed();

        $crawler = $this->lens($department);
        $this->client->submit($this->attachForm($crawler, 'Surveys'));
        $crawler = $this->client->followRedirect();

        $flash = $crawler->filter('[data-shell-flash]')->text();
        self::assertStringContainsString('Surveys', $flash);
        self::assertStringNotContainsString('grants nobody anything', $flash);
        self::assertStringContainsString('people outside every department that runs it are refused', $flash);
    }

    /** SWITCHING OFF says that who may use the module follows the departments still running it. */
    public function testTheFlashOnSwitchingOffSaysWhoMayUseItNow(): void
    {
        $department = $this->seed();
        $this->attach($department, 'surveys');

        $crawler = $this->lens($department);
        $this->client->submit($crawler->filter('tr[data-dept-attached]')->selectButton('Switch off')->form());
        $crawler = $this->client->followRedirect();

        $flash = $crawler->filter('[data-shell-flash]')->text();
        self::assertStringContainsString('Surveys', $flash);
        self::assertStringNotContainsString('not access', $flash);
        self::assertStringContainsString('who may use it now follows the departments that still run it', $flash);
    }

    /** A SLUG THE INSTALLATION DOES NOT HAVE is not a module to attach. */
    public function testASlugTheInstallationDoesNotHaveIsRefused(): void
    {
        $department = $this->seed();
        $token = $this->tokenFrom('/departments/'.$department->getUuidString().'/configure');

        $this->client->request('POST', '/departments/'.$department->getUuidString().'/modules/nowhere/toggle', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    // ---- who may write it --------------------------------------------------

    public function testAColleagueWithoutTeamManageIsRefused(): void
    {
        $department = $this->seed();
        $token = $this->tokenFrom('/departments/'.$department->getUuidString().'/configure');

        $ranger = $this->person('Juma', 'Mwakalinga', TeamRoleEnum::Staff);
        $ranger->setPosition($this->position('Ranger', ['surveys.read']));
        $this->em->flush();
        $this->client->loginUser($ranger);

        $this->client->request('POST', $this->toggleUrl($department, 'surveys'), ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame([], $this->attachedSlugs($department));
    }

    public function testAnAnonymousVisitorIsSentToSignIn(): void
    {
        $department = $this->department('Ecology');
        $this->em->flush();

        $this->client->request('POST', $this->toggleUrl($department, 'surveys'));

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * AN AREA ADMINISTRATOR REACHES THEIR OWN AREA'S DEPARTMENTS AND NO OTHERS —
     * the same boundary every other write on this controller keeps.
     */
    public function testAnAreaAdminCannotAttachToAnOrgLevelDepartment(): void
    {
        $north = $this->area('Northern Reserve');
        $this->modules();
        $ecology = $this->department('Ecology');
        $office = $this->areaDepartment('Warden Office', $north);
        $admin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $admin->setPosition($this->administratorPosition('Warden'));
        // THE BOUNDARY IS THE PLACEMENT, not the department of their position:
        // this administrator is placed in the north and nowhere else.
        $this->place($admin, [$north]);
        $this->em->flush();
        $this->client->loginUser($admin);

        $token = $this->tokenFrom('/departments/'.$office->getUuidString().'/configure');

        // Their own area department: allowed.
        $this->client->request('POST', $this->toggleUrl($office, 'surveys'), ['_token' => $token]);
        self::assertResponseRedirects('/departments/'.$office->getUuidString());

        // An org-level one: refused.
        $this->client->request('POST', $this->toggleUrl($ecology, 'surveys'), ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertSame([], $this->attachedSlugs($ecology));
    }

    // ---- the cast ----------------------------------------------------------

    /**
     * An installation with the two modules this kernel's providers declare, and
     * one org-level department to attach them to.
     */
    private function seed(): Department
    {
        $this->administrator();
        $this->modules();
        $department = $this->department('Ecology');
        $this->em->flush();

        return $department;
    }

    /**
     * The catalogue rows for the modules this kernel's providers declare. A row
     * plus a registered provider is what makes a module installed; either alone
     * is not.
     */
    private function modules(): void
    {
        foreach (['surveys' => 'Surveys', 'roster' => 'Roster'] as $slug => $name) {
            $this->em->persist((new Module())->setSlug($slug)->setName($name));
        }
    }

    /** The configure page, where the modules a department runs are switched. */
    private function lens(Department $department): Crawler
    {
        return $this->client->request('GET', '/departments/'.$department->getUuidString().'/configure');
    }

    private function toggleUrl(Department $department, string $slug): string
    {
        return '/departments/'.$department->getUuidString().'/modules/'.$slug.'/toggle';
    }

    /** Attach through the real write, so no test seeds a state the screen cannot make. */
    private function attach(Department $department, string $slug): void
    {
        $this->client->request('POST', $this->toggleUrl($department, $slug), [
            '_token' => $this->tokenFrom('/departments/'.$department->getUuidString().'/configure'),
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
    }

    private function attachForm(Crawler $crawler, string $module): Form
    {
        return $crawler->filter('tr[data-dept-attach]')
            ->reduce(static fn (Crawler $c): bool => $module === trim($c->filter('td b')->text()))
            ->filter('form')
            ->first()
            ->form();
    }

    /** @return list<string> */
    private function attachedSlugs(Department $department): array
    {
        $fresh = $this->em->getRepository(Department::class)->findOneBy(['name' => $department->getName()]);
        self::assertInstanceOf(Department::class, $fresh);

        $slugs = [];
        foreach ($fresh->getModules() as $module) {
            $slugs[] = (string) $module->getSlug();
        }

        return $this->sorted($slugs);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * WHAT ADMINISTERING THE TEAM IS, WRITTEN AS PAIRS. `team.manage` was one
     * flat value; it is eight (concern, verb) pairs now, and these are the
     * eight the upgrade backfills it into, so a fixture that used to say
     * "this person administers the team" still says exactly that.
     */
    private function administratorPosition(string $name): Position
    {
        return $this->position($name, [
            'directory.read',
            'directory.manage',
            'personal-details.read',
            'personal-details.manage',
            'positions.read',
            'positions.configure',
            'departments.read',
            'departments.configure',
        ]);
    }
}
