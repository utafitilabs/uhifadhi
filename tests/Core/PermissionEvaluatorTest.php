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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\Cell;
use Uhifadhi\Bundle\TeamBundle\Permissions\CellAnswer;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionEvaluator;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Core\Tests\Application\Kernel;
use Uhifadhi\Test\Authority\World;

/**
 * THE PERMISSIONS PAGE'S ANSWERS, for real accounts: one holder of each
 * position and one of each tier above the matrix, each power asked about a
 * real target found on the installation. A Super Admin is signed in
 * throughout, as on the page, and no answer may follow them.
 */
#[CoversNothing]
final class PermissionEvaluatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private World $world;
    private User $head;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $catalogue = self::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);
        $this->world = World::seed($this->em, $catalogue->exceptionPairs()[0] ?? '');

        $kilimani = ($this->user($this->world->member)->getPlacement()?->getAreas() ?? [])[0] ?? null;
        self::assertNotNull($kilimani);
        $seat = new Position()->setName('Head of station')->setGrantValues(['directory.read', 'sign-in-help.manage'], $catalogue->pairs());
        $this->head = new User()->setEmail('hamisi.juma@unr.example')->setFirstName('Hamisi')->setLastName('Juma')
            ->setPassword('a hash, never a password')->setTeamRole(TeamRoleEnum::Staff)->setPosition($seat)->setVerified(true)
            ->setPlacement(new Placement()->inAreas([$kilimani])->inDepartment($this->user($this->world->member)->getPlacement()?->getDepartment() ?? throw new \LogicException()));
        $this->em->persist($seat);
        $this->em->persist($this->head);
        $this->em->flush();

        $chief = $this->user($this->world->superAdmin);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($chief, 'main', $chief->getRoles()));
    }

    protected function tearDown(): void
    {
        new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->close();
        parent::tearDown();
    }

    public function testOneHolderOfEachPositionAndOneOfEachTierAct(): void
    {
        $actors = array_map(static fn (User $u): string => $u->getFullName(), $this->evaluator()->actors());

        self::assertContains('Hamisi Juma', $actors, 'the head of station, for their position');
        self::assertContains('Upendo Massawe', $actors, 'an Admin');
        self::assertContains('Asha Kweka', $actors, 'a Super Admin');
        self::assertCount(1, array_intersect(['Naserian Lekishon', 'Lomayani Laizer'], $actors), 'one holder speaks for the Rangers');
    }

    public function testAHeadOfStationHelpsTheirOwnAreaBackInAndNobodyBeyondIt(): void
    {
        $cells = $this->evaluator()->forPerson($this->head);

        self::assertSame(CellAnswer::Allowed, $this->cell($cells, 'send-a-reset-link', PowerTarget::AColleague)->answer);
        $beyond = $this->cell($cells, 'send-a-reset-link', PowerTarget::SomebodyBeyondTheirArea);
        self::assertSame(CellAnswer::Refused, $beyond->answer, 'although the Super Admin reading reaches them');
        self::assertContains('this person is placed beyond their area.', $beyond->reasons);
    }

    public function testTheTiersAnswerAsTheRulesSay(): void
    {
        $admin = $this->evaluator()->forPerson($this->user($this->world->admin));
        $makeSuper = $this->cell($admin, 'make-super-admin', PowerTarget::AColleague);
        self::assertSame(CellAnswer::Refused, $makeSuper->answer);
        self::assertNotSame([], $makeSuper->reasons, 'a refusal says why');
        self::assertSame(CellAnswer::Allowed, $this->cell($admin, 'make-admin', PowerTarget::AColleague)->answer);

        $chief = $this->evaluator()->forPerson($this->user($this->world->superAdmin));
        self::assertSame(CellAnswer::Allowed, $this->cell($chief, 'switch-user', PowerTarget::AColleague)->answer);

        $ranger = $this->evaluator()->forPerson($this->user($this->world->member));
        self::assertSame(CellAnswer::Refused, $this->cell($ranger, 'raise-their-own-tier', PowerTarget::Themselves)->answer);
        self::assertSame([], array_filter($ranger, static fn (Cell $c): bool => CellAnswer::Allowed === $c->answer && 'change-another-rangers-check-in' !== $c->power->key), 'a ranger gains and gives nothing');
    }

    public function testATargetTheInstallationDoesNotHaveIsSaidSoAndNotGuessed(): void
    {
        $chief = $this->evaluator()->forPerson($this->user($this->world->superAdmin));

        self::assertSame(CellAnswer::NoTarget, $this->cell($chief, 'demote-to-staff', PowerTarget::ASuperAdmin)->answer, 'the only Super Admin here is the one acting');
    }

    public function testAskingChangesNothing(): void
    {
        $before = $this->user($this->world->admin)->getTeamRole();
        $this->evaluator()->ledger();
        $this->em->clear();

        self::assertSame($before, $this->user($this->world->admin)->getTeamRole());
    }

    /** @param list<Cell> $cells */
    private function cell(array $cells, string $power, PowerTarget $kind): Cell
    {
        foreach ($cells as $cell) {
            if ($cell->power->key === $power && $cell->kind === $kind) {
                return $cell;
            }
        }

        self::fail(\sprintf('No cell for %s about %s.', $power, $kind->value));
    }

    private function user(string $uuid): User
    {
        $users = $this->em->getRepository(User::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneByUuid(Uuid::fromString($uuid));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function evaluator(): PermissionEvaluator
    {
        $evaluator = self::getContainer()->get('test_public.team.permissions.evaluator');
        self::assertInstanceOf(PermissionEvaluator::class, $evaluator);

        return $evaluator;
    }
}
