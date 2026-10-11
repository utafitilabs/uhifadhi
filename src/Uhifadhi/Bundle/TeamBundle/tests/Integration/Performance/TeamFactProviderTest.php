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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Performance;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\RegistryBundle\Facts\FactProviders;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Facts\TeamFactProvider;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Contracts\Facts\FactPeriod;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;

/**
 * THE TEAM'S FIGURES ON THE FACTS LEDGER — seats and people per department,
 * and the posts that have stood empty too long per scope a performance page
 * is drawn for: the organization, and each area.
 *
 * The performance pages read these instead of counting every department's
 * posts on every request.
 */
#[CoversClass(TeamFactProvider::class)]
final class TeamFactProviderTest extends IntegrationTestCase
{
    public function testEachDepartmentsSeatsAndPeopleAreFiled(): void
    {
        $protection = new Department()->setName('Protection Service');
        $this->em->persist($protection);

        $ranger = $this->post('Ranger');
        $warden = $this->post('Warden');
        $this->holder('Mollel', $ranger, $protection);
        $this->holder('Laizer', $ranger, $protection);
        $this->holder('Ndosi', $warden, $protection, active: false);
        $this->em->flush();

        $filed = $this->filed(FactPeriod::month(new \DateTimeImmutable()), FactSubject::DEPARTMENT, (string) $protection->getUuidString());

        self::assertSame([
            StaffingFigures::POSITIONS => 2.0,
            StaffingFigures::FILLED => 1.0,
            StaffingFigures::VACANT => 1.0,
            StaffingFigures::PEOPLE => 2.0,
        ], array_intersect_key($filed, array_flip([StaffingFigures::POSITIONS, StaffingFigures::FILLED, StaffingFigures::VACANT, StaffingFigures::PEOPLE])));
    }

    /**
     * AN AREA'S PAGE READS ITS OWN DEPARTMENTS AND THE ORGANIZATION-WIDE
     * ONES, so its count of posts past the threshold is theirs; the
     * organization's counts every department's.
     */
    public function testThePostsPastTheThresholdAreFiledForTheOrganizationAndEachArea(): void
    {
        $north = new HostArea()->setName('Northern Reserve');
        $south = new HostArea()->setName('Southern Reserve');
        $this->em->persist($north);
        $this->em->persist($south);

        $protection = new Department()->setName('Protection Service');
        $wetlands = new Department()->setName('Wetland Management')->setArea($north);
        $this->em->persist($protection);
        $this->em->persist($wetlands);

        $this->holder('Mollel', $this->post('Ranger', vacantFor: 96), $protection, active: false);
        $this->holder('Laizer', $this->post('Scout'), $protection, active: false);
        $this->holder('Ndosi', $this->post('Warden', vacantFor: 70), $wetlands, active: false);
        $this->holder('Kimaro', $this->post('Guide', vacantFor: 3), $wetlands, active: false);
        $this->em->flush();

        $month = FactPeriod::month(new \DateTimeImmutable());

        $organization = $this->filed($month, FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID);
        self::assertSame(2.0, $organization[TeamFactProvider::OVER_THRESHOLD]);
        self::assertSame(1.0, $organization[TeamFactProvider::VACANT_UNDATED]);

        $northern = $this->filed($month, FactSubject::AREA, (string) $north->getUuidString());
        self::assertSame(2.0, $northern[TeamFactProvider::OVER_THRESHOLD], 'its own department’s and the organization-wide one’s');
        self::assertSame(1.0, $northern[TeamFactProvider::VACANT_UNDATED]);

        $southern = $this->filed($month, FactSubject::AREA, (string) $south->getUuidString());
        self::assertSame(1.0, $southern[TeamFactProvider::OVER_THRESHOLD], 'the organization-wide department only');
    }

    public function testItIsCollectedAsAFactProviderWithEveryFigureItFiles(): void
    {
        $providers = $this->service(FactProviders::class);

        $team = $providers->all(TeamFactProvider::SLUG);
        self::assertCount(1, $team);
        self::assertSame(
            [StaffingFigures::POSITIONS, StaffingFigures::FILLED, StaffingFigures::VACANT, StaffingFigures::PEOPLE, TeamFactProvider::OVER_THRESHOLD, TeamFactProvider::VACANT_UNDATED],
            array_map(static fn ($figure): string => $figure->key, $team[0]->figures()),
        );
        foreach ($team[0]->figures() as $figure) {
            self::assertFalse($figure->additive, $figure->key.' is a state at a moment, never the sum of its months');
        }
    }

    /** @return array<string, float|null> figure key to value, for one subject */
    private function filed(FactPeriod $period, string $subjectKind, string $subjectUuid): array
    {
        $provider = $this->service(TeamFactProvider::class);

        $keys = array_map(static fn ($figure): string => $figure->key, $provider->figures());

        $filed = [];
        foreach ($provider->compute(new FactRequest($period, $keys)) as $value) {
            if ($subjectKind === $value->subjectKind && $subjectUuid === $value->subjectUuid) {
                $filed[$value->figureKey] = $value->value;
            }
        }

        return $filed;
    }

    private function post(string $name, ?int $vacantFor = null): Position
    {
        $position = new Position()->setName($name);
        $position->setGrantValues([], []);
        if (null !== $vacantFor) {
            $position->setVacantSince(new \DateTimeImmutable(\sprintf('-%d days', $vacantFor)));
        }
        $this->em->persist($position);

        return $position;
    }

    private function holder(string $surname, Position $position, Department $department, bool $active = true): void
    {
        $placement = new Placement()->acrossTheOrganization()->inDepartment($department);
        $this->em->persist($placement);

        $person = new User()
            ->setEmail(strtolower($surname).'@example.test')
            ->setFirstName('Test')->setLastName($surname)
            ->setPassword('x')
            ->setPosition($position)->setPlacement($placement);
        if (!$active) {
            $person->deactivate();
        }
        $this->em->persist($person);
    }
}
