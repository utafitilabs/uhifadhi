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
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Enum\GrantQuestionEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\GrantDecision;
use Uhifadhi\Bundle\TeamBundle\Permissions\GrantStep;
use Uhifadhi\Bundle\TeamBundle\Security\GrantVoter;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\BuildsAnOrganization;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\WholeOrganization;

/**
 * THE VOTER RECORDS EACH QUESTION AS IT ASKS IT, and votes from the record.
 *
 * The Check page prints these steps, so the steps must be the voter's own:
 * each worked example's walk is asserted here line by line, and then every
 * person of the fixture is asked every pair in every place, both ways — the
 * decision, and the decision manager — and the two must never disagree.
 */
#[CoversClass(GrantVoter::class)]
#[CoversClass(GrantDecision::class)]
#[CoversClass(GrantStep::class)]
final class GrantDecisionTest extends IntegrationTestCase
{
    use BuildsAnOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrganization($this->em);
    }

    public function testATierIsAboveTheMatrixAndNothingElseIsAsked(): void
    {
        $decision = $this->decide('Desta Haile', 'positions.configure', new WholeOrganization());

        self::assertTrue($decision->isAllowed());
        self::assertSame([['tier', 'ok']], $this->walk($decision));
    }

    /** §7, a ranger: record patrols at Mlima Mkuu is refused at the fifth question. */
    public function testTheRangerIsRefusedWhereTheirPlacementDoesNotReach(): void
    {
        $decision = $this->decide('Thabo Ndlovu', 'surveys.record', $this->areas['Mlima Mkuu']);

        self::assertFalse($decision->isAllowed());
        self::assertSame([['tier', 'skip'], ['tier-only', 'ok'], ['position', 'ok'], ['exception', 'skip'], ['placement', 'ok'], ['place', 'no']], $this->walk($decision));
        self::assertSame(GrantQuestionEnum::Place, $decision->getRefusal()?->question);
    }

    public function testTheRangerAtHomeIsAllowedBecauseTheirDepartmentRunsTheModule(): void
    {
        $decision = $this->decide('Thabo Ndlovu', 'surveys.record', $this->areas['Kilimani Game Reserve']);

        self::assertTrue($decision->isAllowed());
        self::assertSame(['place', 'ok'], $this->walk($decision)[5]);
        self::assertSame(['department', 'ok'], $this->walk($decision)[6]);
        self::assertSame('Protection Service', $decision->getServedBy()?->getName());
        self::assertSame('surveys', $decision->getModule());
    }

    public function testADepartmentThatDoesNotRunTheModuleRefusesLast(): void
    {
        $decision = $this->decide('Neema Swai', 'surveys.read', $this->areas['Kilimani Game Reserve']);

        self::assertFalse($decision->isAllowed());
        self::assertSame(['department', 'no'], $this->walk($decision)[6]);
        self::assertSame(['Protection Service'], array_map(static fn ($d): ?string => $d->getName(), $decision->getRunning()));
        self::assertNull($decision->getServedBy());
    }

    public function testAConcernOfNoModuleDoesNotAskTheDepartment(): void
    {
        $decision = $this->decide('Thabo Ndlovu', 'stations.read', $this->areas['Kilimani Game Reserve']);

        self::assertTrue($decision->isAllowed());
        self::assertSame(['department', 'skip'], $this->walk($decision)[6]);
        self::assertNull($decision->getModule());
    }

    /** §7, the head of operations: Live locations, with the reason in force, everywhere. */
    public function testAnExceptionInForceIsReadIntoTheWalk(): void
    {
        $decision = $this->decide('Grace Ndosi', 'locations.read', $this->areas['Sinde Flats']);

        self::assertTrue($decision->isAllowed());
        self::assertSame(['exception', 'ok'], $this->walk($decision)[3]);
        self::assertSame('the rank rule', $decision->getLifts());
        self::assertSame('Runs every operation across the four areas.', $decision->getJustification()?->getReason());
    }

    public function testAnExceptionHeldWithoutAReasonIsRefusedAtTheException(): void
    {
        $decision = $this->decide('Elias Mdee', 'locations.read', $this->areas['Mlima Mkuu']);

        self::assertFalse($decision->isAllowed());
        self::assertSame([['tier', 'skip'], ['tier-only', 'ok'], ['position', 'ok'], ['exception', 'no']], $this->walk($decision));
        self::assertNull($decision->getJustification());
    }

    public function testATierOnlyPairAPositionStoresIsRefusedBeforeThePositionIsRead(): void
    {
        $decision = $this->decide('Mariam Temba', 'positions.configure', new WholeOrganization());

        self::assertSame([['tier', 'skip'], ['tier-only', 'no']], $this->walk($decision));
        self::assertStringContainsString('only Admins and Super Admins hold', implode(' ', $decision->getReasons()));
    }

    public function testNoPositionIsRefusedAtThePosition(): void
    {
        $decision = $this->decide('Latifa Macha', 'stations.read', $this->areas['Ziwa Kavu']);

        self::assertSame(['position', 'no'], $this->walk($decision)[2]);
        self::assertNull($decision->getPosition());
    }

    public function testAPlacementWithNoDepartmentReachesNothing(): void
    {
        $decision = $this->decide('Bakari Chuwa', 'stations.read', $this->areas['Mlima Mkuu']);

        self::assertSame(['placement', 'no'], $this->walk($decision)[4]);
        self::assertSame(['their placement reaches nothing: no ground, or no department yet.'], $decision->getReasons());
    }

    public function testTheWholeOrganizationIsRefusedToAPlacementAtOneArea(): void
    {
        $decision = $this->decide('Rehema Mushi', 'stations.read', new WholeOrganization());

        self::assertSame(['place', 'no'], $this->walk($decision)[5]);
        self::assertSame(['this reaches the whole organization, and their placement covers only part of it.'], $decision->getReasons());
    }

    /** THE WALK AND THE VOTE NEVER DISAGREE, on any person, pair or place of the fixture. */
    public function testTheDecisionAgreesWithTheDecisionManagerEverywhere(): void
    {
        $checker = $this->service(UserAuthorizationCheckerInterface::class);
        $places = [new WholeOrganization(), null, ...array_values($this->areas)];
        $asked = 0;

        foreach ($this->people as $name => $person) {
            foreach ($this->service(ConcernCatalogue::class)->pairs() as $pair) {
                foreach ($places as $place) {
                    $decision = $this->voter()->decide($person, Grant::parse($pair), $place);
                    self::assertSame(
                        $checker->isGrantedForUser($person, $pair, $place),
                        $decision->isAllowed(),
                        \sprintf('%s · %s · %s', $name, $pair, $place instanceof WholeOrganization ? 'the whole organization' : ($place?->getName() ?? 'no place')),
                    );
                    ++$asked;
                }
            }
        }

        self::assertGreaterThan(1000, $asked);
    }

    private function decide(string $person, string $pair, mixed $place): GrantDecision
    {
        return $this->voter()->decide($this->people[$person], Grant::parse($pair), $place);
    }

    /** @return list<array{string, string}> */
    private function walk(GrantDecision $decision): array
    {
        return array_map(static fn (GrantStep $step): array => [$step->question->value, $step->outcome->value], $decision->getSteps());
    }

    private function voter(): GrantVoter
    {
        return $this->service(GrantVoter::class);
    }
}
