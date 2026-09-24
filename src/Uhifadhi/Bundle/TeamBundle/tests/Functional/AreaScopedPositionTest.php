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

use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * §5.6(b), THE POSITION SIDE — A POSITION HAS NO GROUND, SO WRITING ONE IS NOT
 * AN AREA-SCOPED ACT.
 *
 * A position is a job title and nothing else: one *Analyst* for the whole
 * organization, held by people placed wherever they work. Naming one therefore
 * confers no authority anywhere and reaches past nobody's boundary, so a
 * bounded (area-X) `team.manage` holder creates and renames positions exactly
 * as an unbounded administrator does. What IS fenced is the person — where
 * somebody is placed, and what a position may be made to grant — and those are
 * the assignment and escalation suites.
 *
 * THIS SUITE EXISTS TO STOP THE OLD FENCE COMING BACK. A position used to be
 * filed under a department, the department gave it an area, and creating or
 * renaming one was refused unless that area was the administrator's own; the
 * create card even had a department picker narrowed to their area. The ruling
 * deleted the department from the position, which deleted all of that with it —
 * a re-added `department` field on this form, or a 403 on either write, is the
 * old model reappearing.
 *
 * The one rule that remains is uniqueness, and the ruling WIDENED it: a
 * position's name is unique across the whole organization, because there is no
 * department left for it to be unique only inside.
 */
final class AreaScopedPositionTest extends WebTestCaseWithSchema
{
    // ---- creating a position ----------------------------------------------

    /** An area-X admin creates a position — it belongs to the organization, not to their area. */
    public function testAnAreaAdminCreatesAPosition(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions', [
            '_token' => $token, 'name' => 'Field Ranger',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Field Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame('Field Ranger', $stored->getName(), 'A bare name, because there is no department to qualify it with.');
    }

    /**
     * AND THE FORM HAS NO DEPARTMENT FIELD TO FILE IT UNDER. The picker that
     * used to narrow the choice to the administrator's own area has nothing
     * left to narrow: one name is the whole form.
     */
    public function testTheCreateCardAsksForANameAndNothingElse(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $this->department('Ecology');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/positions');
        $card = $crawler->filter('#add form.crcard');

        self::assertCount(1, $card->filter('input[name="name"]'));
        self::assertCount(0, $card->filter('select[name="department"]'), 'A position belongs to no department, so nothing here files it under one.');
    }

    /** A second position of the same name is refused, and the sentence says ORGANIZATION. */
    public function testASecondPositionOfTheSameNameIsRefusedAcrossTheWholeOrganization(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $this->position('Analyst');
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions', [
            '_token' => $token, 'name' => 'Analyst',
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-shell-flash="error"]', 'This organization already has a position called');
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Position::class)->findBy(['name' => 'Analyst']));
    }

    // ---- renaming a position ----------------------------------------------

    /** An area-X admin renames a position — any of them, because none of them is anybody's. */
    public function testAnAreaAdminRenamesAPosition(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $ranger = $this->position('Ranger');
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/rename', [
            '_token' => $token, 'name' => 'Senior Ranger',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Position::class)->findOneBy(['name' => 'Senior Ranger']));
    }

    /**
     * Including one whose holders all work somewhere else entirely. Where the
     * holders are placed is their fact, not the position's, so it cannot make
     * the title out of bounds.
     */
    public function testAnAreaAdminRenamesAPositionHeldOnlyByPeopleInAnotherArea(): void
    {
        $this->areaAdminIn($this->area('Northern Reserve'));
        $analyst = $this->position('Analyst');
        $grace = $this->person('Grace', 'Ndosi')->setPosition($analyst);
        $this->place($grace, [$this->area('Western Reserve')]);
        $this->em->flush();

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions/'.$analyst->getUuidString().'/rename', [
            '_token' => $token, 'name' => 'Senior Analyst',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Position::class)->findOneBy(['name' => 'Senior Analyst']));
    }

    // ---- the unbounded do the same thing ----------------------------------

    /** An administrator placed across the organization creates positions the same way. */
    public function testAnOrganizationWideAdminCreatesAPositionTheSameWay(): void
    {
        $orgAdmin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $orgAdmin->setPosition($this->administratorPosition('Coordinator'));
        $this->place($orgAdmin);
        $this->em->flush();
        $this->client->loginUser($orgAdmin);

        $token = $this->tokenFrom('/team/configure/positions');
        $this->client->request('POST', '/team/positions', [
            '_token' => $token, 'name' => 'Field Ranger',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Position::class)->findOneBy(['name' => 'Field Ranger']));
    }

    /** And a tier sees the same one-field card, because there is only one card. */
    public function testTheCreateCardIsTheSameForATier(): void
    {
        $this->administrator();
        $this->department('Ecology');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/configure/positions');
        $card = $crawler->filter('#add form.crcard');

        self::assertCount(1, $card->filter('input[name="name"]'));
        self::assertCount(0, $card->filter('select[name="department"]'));
    }

    // ---- the cast ---------------------------------------------------------

    /**
     * Sign in as an AREA-X administrator — a Staff member holding team.manage
     * through their position and PLACED at $area, the bounded kind whose
     * refusals this suite is about not getting.
     */
    private function areaAdminIn(HostArea $area): User
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Staff);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->place($admin, [$area]);
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
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
