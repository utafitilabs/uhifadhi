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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\TeamPowers;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Enum\FindingListEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\GrantQuestionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\Cell;
use Uhifadhi\Bundle\TeamBundle\Permissions\CellAnswer;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionEvaluator;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionFindingRepository;
use Uhifadhi\Bundle\TeamBundle\Security\GrantVoter;
use Uhifadhi\Bundle\TeamBundle\Security\MemberVoter;
use Uhifadhi\Bundle\TeamBundle\Security\OwnStandingRaised;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\BuildsAnOrganization;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\RunsPermissions;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\WholeOrganization;

/**
 * BELOW A SUPER ADMIN, NOBODY GAINS POWER OVER THEIR OWN STANDING (ruled
 * 8 Nov 2026). Every gaining power is asked as an Admin and as a Staff member
 * about themselves, through the same voters the routes ask, and every one is
 * refused with the reason in words. A Super Admin manages the installation,
 * their own rank and seat included, and keeps today's answers; the same
 * powers asked about somebody else answer as they did.
 */
#[CoversClass(MemberVoter::class)]
#[CoversClass(GrantVoter::class)]
#[CoversClass(TeamPowers::class)]
#[CoversClass(OwnStandingRaised::class)]
final class NobodyRaisesTheirOwnStandingTest extends IntegrationTestCase
{
    use BuildsAnOrganization;
    use RunsPermissions;

    private const string REASON = 'nobody raises their own standing.';

    private const string CLERK = 'Kesi Mbwambo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);

        // A STAFF MEMBER WHOSE SEAT STORES THE DIRECTORY'S MANAGE PAIR, which
        // only the tiers hold: the strongest Staff actor a position can make.
        $this->position($this->em, 'Personnel clerk', [ScopeKind::Organization], ['directory.read', 'directory.manage', 'ranks.read']);
        $this->person($this->em, 'Kesi', 'Mbwambo', 'Personnel clerk', new Placement()->acrossTheOrganization()->inDepartment($this->departments['ICT']));
        $this->em->flush();
    }

    /** @return iterable<string, array{string, string}> */
    public static function actorsAndGainingPowers(): iterable
    {
        $powers = [
            'raise-their-own-rank',
            'move-their-own-rank-up-the-scale',
            'seat-themselves-in-a-stronger-position',
            'create-an-account-in-a-stronger-position',
        ];
        foreach (['Admin' => 'Desta Haile', 'Staff' => self::CLERK] as $tier => $actor) {
            foreach ($powers as $power) {
                yield "$tier · $power" => [$actor, $power];
            }
        }
        yield 'Admin · raise-their-own-tier' => ['Desta Haile', 'raise-their-own-tier'];
        yield 'Staff · raise-their-own-tier' => [self::CLERK, 'raise-their-own-tier'];
    }

    #[DataProvider('actorsAndGainingPowers')]
    public function testAGainingPowerAskedAboutThemselvesIsRefusedWithTheReason(string $actor, string $power): void
    {
        $cells = $this->cells($actor, $power);

        self::assertNotSame([], $cells);
        foreach ($cells as $cell) {
            self::assertSame(CellAnswer::Refused, $cell->answer, $cell->kind->value);
            self::assertContains(self::REASON, $cell->reasons, $cell->kind->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function superAdminsOwnStanding(): iterable
    {
        yield 'raise-their-own-rank' => ['raise-their-own-rank'];
        yield 'move-their-own-rank-up-the-scale' => ['move-their-own-rank-up-the-scale'];
        yield 'seat-themselves-in-a-stronger-position' => ['seat-themselves-in-a-stronger-position'];
        yield 'create-an-account-in-a-stronger-position' => ['create-an-account-in-a-stronger-position'];
    }

    #[DataProvider('superAdminsOwnStanding')]
    public function testASuperAdminKeepsTodaysAnswerAboutThemselves(string $power): void
    {
        $cells = $this->cells('Naomi Kileo', $power);

        self::assertNotSame([], $cells);
        foreach ($cells as $cell) {
            self::assertSame(CellAnswer::Allowed, $cell->answer, $cell->kind->value);
        }
    }

    /** EVERY GAINING POWER THE LEDGER FINDS HELD IS A SUPER ADMIN'S. */
    public function testTheLedgerHasNoGainingPowerBelowASuperAdmin(): void
    {
        $allowed = [];
        foreach ($this->evaluator()->ledger() as $cell) {
            if (PowerGroup::GainingPower === $cell->power->group && CellAnswer::Allowed === $cell->answer
                && TeamRoleEnum::SuperAdmin !== $cell->actor->getTeamRole()) {
                $allowed[] = $cell->actor->getFullName().' · '.$cell->power->key;
            }
        }

        self::assertSame([], $allowed);
    }

    /** A SUPER ADMIN'S OWN YES IS EXPECTED AND NOT FILED, so the fixture files no gaining finding at all. */
    public function testARunFilesNoGainingFinding(): void
    {
        $run = $this->runNow();

        self::assertSame([], $this->service(PermissionFindingRepository::class)->findByRunAndList($run, FindingListEnum::Gaining));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function actsOnSomebodyElse(): iterable
    {
        yield 'an Admin seats a colleague' => ['Desta Haile', 'seat-in-a-stronger-position', PowerTarget::AColleague->value];
        yield 'an Admin makes a colleague Admin' => ['Desta Haile', 'make-admin', PowerTarget::AColleague->value];
        yield 'a Super Admin makes an Admin Super Admin' => ['Naomi Kileo', 'make-super-admin', PowerTarget::AnAdmin->value];
        yield 'a Super Admin seats an Admin' => ['Naomi Kileo', 'seat-in-a-stronger-position', PowerTarget::AnAdmin->value];
        yield 'an Admin opens a colleague\'s account' => ['Desta Haile', 'open-their-account', PowerTarget::AColleague->value];
        yield 'an Admin issues a colleague a one-time password' => ['Desta Haile', 'issue-a-one-time-password', PowerTarget::AColleague->value];
    }

    #[DataProvider('actsOnSomebodyElse')]
    public function testActingOnSomebodyElseKeepsTodaysAnswer(string $actor, string $power, string $kind): void
    {
        $cells = array_values(array_filter($this->cells($actor, $power), static fn (Cell $c): bool => $kind === $c->kind->value));

        self::assertCount(1, $cells);
        self::assertSame(CellAnswer::Allowed, $cells[0]->answer, implode(' ', $cells[0]->reasons));
    }

    public function testAnAdminMayStillConfigureSomebodyElseButNotThemselves(): void
    {
        $checker = $this->service(UserAuthorizationCheckerInterface::class);
        $admin = $this->people['Desta Haile'];

        self::assertTrue($checker->isGrantedForUser($admin, MemberVoter::CONFIGURE, $this->people['Thabo Ndlovu']));
        self::assertFalse($checker->isGrantedForUser($admin, MemberVoter::CONFIGURE, $admin));
    }

    /** THE WALK SAYS WHERE THE VOTER STOPPED: before the tier, and nothing after. */
    public function testTheWalkStopsBeforeTheTier(): void
    {
        $decision = $this->service(GrantVoter::class)->decide($this->people['Desta Haile'], Grant::parse('ranks.configure'), new OwnStandingRaised());

        self::assertFalse($decision->isAllowed());
        self::assertCount(1, $decision->getSteps());
        self::assertSame(GrantQuestionEnum::Tier, $decision->getRefusal()?->question);
        self::assertSame([self::REASON], $decision->getReasons());
    }

    public function testASuperAdminsOwnStandingPassesTheTier(): void
    {
        $decision = $this->service(GrantVoter::class)->decide($this->people['Naomi Kileo'], Grant::parse('ranks.configure'), new OwnStandingRaised());

        self::assertTrue($decision->isAllowed());
        self::assertSame(GrantQuestionEnum::Tier, $decision->getSteps()[0]->question);
    }

    /** THE WALK AND THE VOTE STILL AGREE, on every person and pair asked about their own standing. */
    public function testTheDecisionAgreesWithTheDecisionManagerAboutTheirOwnStanding(): void
    {
        $checker = $this->service(UserAuthorizationCheckerInterface::class);
        $voter = $this->service(GrantVoter::class);
        $asked = 0;

        foreach ($this->people as $name => $person) {
            foreach ($this->service(ConcernCatalogue::class)->pairs() as $pair) {
                foreach ([new OwnStandingRaised(), new WholeOrganization(), null] as $subject) {
                    self::assertSame(
                        $checker->isGrantedForUser($person, $pair, $subject),
                        $voter->decide($person, Grant::parse($pair), $subject)->isAllowed(),
                        \sprintf('%s · %s · %s', $name, $pair, get_debug_type($subject)),
                    );
                    ++$asked;
                }
            }
        }

        self::assertGreaterThan(500, $asked);
    }

    /** @return list<Cell> */
    private function cells(string $actor, string $power): array
    {
        return array_values(array_filter(
            $this->evaluator()->forPerson($this->people[$actor]),
            static fn (Cell $c): bool => $power === $c->power->key,
        ));
    }

    private function evaluator(): PermissionEvaluator
    {
        $evaluator = static::getContainer()->get('team.permissions.evaluator');
        self::assertInstanceOf(PermissionEvaluator::class, $evaluator);

        return $evaluator;
    }
}
