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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Devkit;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Devkit\TeamContentProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\DevkitContentCollector;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE DEMO ORGANIZATION, SEEDED THROUGH THE COLLECTOR THAT WILL SEED IT.
 *
 * The bundle offers content; devkit collects it in a dev install and runs it.
 * The collector standing in here does the same much, so what is proved is the
 * arrangement rather than a method call: the provider is reachable through the
 * tag, its dependencies are satisfiable, and running it leaves an organization
 * somebody could have built from the screens.
 */
#[CoversClass(TeamContentProvider::class)]
final class DemoOrganizationTest extends IntegrationTestCase
{
    public function testTheBundleOffersItsContentThroughTheDevkitContracts(): void
    {
        self::assertContains('team', $this->collector()->keys());
    }

    /** It hangs on nothing, because this bundle knows about people and not about areas. */
    public function testTheContentStandsAlone(): void
    {
        self::assertSame([], $this->collector()->get('team')->dependsOn());
    }

    public function testSeedingLeavesAnOrganizationWithDepartmentsPositionsAndPeople(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        self::assertCount(3, $this->service(DepartmentRepository::class)->findAllOrdered());
        // FIVE POSITIONS, AND THE FIFTH IS HELD BY NOBODY on purpose:
        // retiring is refused while anybody holds a position, so a ground
        // where every position is held never draws the offer at all.
        self::assertCount(5, $this->service(PositionRepository::class)->findAllOrdered());
        // THE SIX NAMED ROLES AND THE FIELD STAFF BEHIND THEM. The six are
        // the roles a reader has to see; the rest are the body of the
        // organization, and without them the demo GROUND seeds with three
        // posts staffed and thirteen empty — see TeamContentProvider's own
        // note on why the number is what it is.
        self::assertCount(6 + TeamContentProvider::FIELD_STAFF, $this->service(UserRepository::class)->findAllByName());
    }

    /**
     * SEEDING TWICE IS SEEDING ONCE. A developer running the demo seeder again
     * is repeating a command, not asking for a second organization — and the
     * slices seeded after this one only run if this one does not refuse.
     */
    public function testSeedingTwiceLeavesTheOrganizationTheFirstRunLeft(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $this->collector()->seed('team');
        $this->em->clear();

        self::assertCount(3, $this->service(DepartmentRepository::class)->findAllOrdered());
        self::assertCount(5, $this->service(PositionRepository::class)->findAllOrdered());
        self::assertCount(6 + TeamContentProvider::FIELD_STAFF, $this->service(UserRepository::class)->findAllByName());
    }

    /**
     * A SECOND SEED TOPS UP A DEMO POSITION THAT GRANTS NOTHING — one seeded
     * before it had anything to grant — and leaves one somebody has since
     * composed by hand exactly as it is.
     */
    public function testASecondSeedGrantsToADemoPositionThatGrantsNothing(): void
    {
        $this->collector()->seed('team');

        $positions = $this->em->getRepository(Position::class);
        $ranger = $positions->findOneBy(['name' => 'Ranger']);
        $analyst = $positions->findOneBy(['name' => 'Analyst']);
        self::assertInstanceOf(Position::class, $ranger);
        self::assertInstanceOf(Position::class, $analyst);

        // The old seed: nothing granted. And a hand-composed one beside it.
        $writes = $this->service(PositionService::class);
        $writes->setGrants($ranger, []);
        $writes->setGrants($analyst, [TeamConcerns::DIRECTORY.'.read']);
        $this->em->flush();
        $this->em->clear();

        $this->collector()->seed('team');
        $this->em->clear();

        $ranger = $positions->findOneBy(['name' => 'Ranger']);
        $analyst = $positions->findOneBy(['name' => 'Analyst']);
        self::assertNotSame([], $ranger?->getGrantValues(), 'The empty demo position was granted.');
        self::assertSame([TeamConcerns::DIRECTORY.'.read'], $analyst?->getGrantValues(), 'And the hand-composed one was left alone.');
    }

    /**
     * SOMEBODY CAN ADMINISTER IT. A demo organization whose every account is
     * Staff is a demo nobody can open the team screens from.
     */
    public function testSomebodySeededCanAdministerTheTeam(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        self::assertGreaterThan(0, $this->service(UserRepository::class)->countActiveSuperAdmins());
    }

    /**
     * THE GRANT IS A REAL ONE. A permission is only real if the catalogue
     * provides it, so a position holding one proves the content went through
     * the validated write path rather than around it.
     */
    public function testAPositionHoldsAPermissionTheCatalogueProvides(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $granted = [];
        foreach ($this->service(PositionRepository::class)->findAllOrdered() as $position) {
            $granted = [...$granted, ...$position->getGrantValues()];
        }

        // THE DEMO MAKES SOMEBODY WHO CAN ADMINISTER THE TEAM, because an
        // installation whose seeded organization has nobody able to write a
        // position is one a reader cannot get started in.
        self::assertContains('positions.configure', $granted);
        self::assertContains('departments.configure', $granted);
    }

    /**
     * A PERSON WITH NO POSITION IS A STATE THE ROSTER HAS TO DRAW, so the demo
     * organization has one.
     */
    public function testSomebodyIsSeededWithNoPositionAtAll(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $unseated = array_filter(
            $this->service(UserRepository::class)->findAllByName(),
            static fn ($user): bool => null === $user->getPosition(),
        );

        self::assertCount(1, $unseated);
    }

    /**
     * EVERY SEEDED ACCOUNT IS ONE THE FIREWALL WOULD ACCEPT — verified, active,
     * and carrying a credential nobody typed and nothing printed.
     */
    public function testEverySeededAccountIsUsableAndItsCredentialIsNobodysToKnow(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        foreach ($this->service(UserRepository::class)->findAllByName() as $user) {
            self::assertTrue($user->isVerified(), (string) $user->getEmail());
            self::assertTrue($user->isActive(), (string) $user->getEmail());
            self::assertNotSame('', (string) $user->getPassword());
        }
    }

    public function testTheSeededTiersAreTheOnesTheInstallationHas(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        foreach ($this->service(UserRepository::class)->findAllByName() as $user) {
            self::assertContains($user->getTeamRole(), TeamRoleEnum::cases());
        }
    }

    /**
     * THE CASE THE RULING EXISTS FOR IS IN THE DEMO DATA. An analyst
     * supporting Ecology and Protection is ONE position placed against two
     * departments — under the old shape, where a position belonged to a
     * department, the demo could only show them as two Analysts or as one
     * filed under a department of convenience. Seeding it means every screen
     * that draws a department meets the case on the first dev install rather
     * than in production.
     */
    public function testTheAnalystIsPlacedInTwoDepartmentsAtOnce(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $analyst = $this->service(UserRepository::class)->findOneByEmail('thabo.ndlovu@example.test');
        self::assertInstanceOf(User::class, $analyst);
        self::assertSame('Analyst', $analyst->getPosition()?->getName());

        self::assertSame(
            ['Ecology', 'Protection Service'],
            array_map(static fn (Department $d): ?string => $d->getName(), $analyst->getDepartments() ?? []),
        );
        self::assertSame('Ecology +1', $analyst->getDepartmentLabel());
    }

    /**
     * AND SO IS THE OTHER END OF IT. The person holding nothing is placed
     * nowhere either, which is the state the model refuses everything in —
     * an empty list of departments rather than the null that means "all".
     */
    public function testTheUnseatedPersonIsPlacedNowhere(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $yara = $this->service(UserRepository::class)->findOneByEmail('yara.benali@example.test');
        self::assertInstanceOf(User::class, $yara);

        self::assertNull($yara->getPosition());
        self::assertNull($yara->getPlacement());
        self::assertSame([], $yara->getDepartments(), 'no placement is no departments, never all of them');
    }

    /**
     * EVERY POSITION IS NAMED ONCE ACROSS THE ORGANIZATION, which is what
     * makes four positions enough for an organization with three departments
     * — the rangers of Protection Service and the analyst who also serves it
     * share the names the register prints.
     */
    public function testEveryPositionNameIsDistinct(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $names = array_map(
            static fn (Position $p): ?string => $p->getName(),
            $this->service(PositionRepository::class)->findAllOrdered(),
        );

        self::assertSame($names, array_values(array_unique($names)));
    }

    private function collector(): DevkitContentCollector
    {
        $collector = static::getContainer()->get('test_public.devkit_content');
        self::assertInstanceOf(DevkitContentCollector::class, $collector);

        return $collector;
    }

    /**
     * AND A YEAR OF CLOSED PERIODS BEHIND IT, so the performance page has
     * something to compare against the first time it is opened. An
     * installation that has not run the devkit has no history, and its
     * pages say so rather than drawing a flat line at nought.
     */
    public function testItLeavesTwelveMonthsOfHistoryBehindEveryDepartment(): void
    {
        $this->collector()->seed('team');
        $this->em->clear();

        $periods = $this->em->getConnection()->fetchOne(
            "SELECT COUNT(DISTINCT period_key) FROM team_department_period_figure WHERE figure_key = 'staffing.filled'",
        );
        self::assertIsNumeric($periods);

        self::assertSame(12, (int) (string) $periods);
    }
}
