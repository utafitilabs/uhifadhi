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

namespace Uhifadhi\Core\Tests\Core\Authority;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE ORGANIZATION EVERY PROBE IS SENT INTO: Uhifadhi Nature Reserves, with
 * Kilimani and Tambarare Game Reserves, two organization-wide departments, a
 * station and a zone at Kilimani, a position, a member of staff and the three
 * links a person is sent by email.
 *
 * IT KEEPS IDENTIFIERS, NEVER ENTITIES. Signing somebody in reboots the
 * client's kernel, which detaches whatever the previous entity manager held,
 * so a caller asks for an entity again by its identifier each time.
 */
final readonly class World
{
    public const string KILIMANI = 'Kilimani Game Reserve';
    public const string TAMBARARE = 'Tambarare Game Reserve';
    public const string OPERATIONS = 'Operations';
    public const string ICT = 'ICT';

    private function __construct(
        public string $kilimani,
        public string $tambarare,
        public int $operations,
        public int $ict,
        public string $operationsUuid,
        public string $station,
        public string $zone,
        public string $position,
        public string $member,
        public string $invitation,
        public string $reset,
        public string $emailChange,
    ) {
    }

    public static function seed(EntityManagerInterface $em): self
    {
        $kilimani = new AreaOfInterest()->setName(self::KILIMANI);
        $tambarare = new AreaOfInterest()->setName(self::TAMBARARE);
        $operations = new Department()->setName(self::OPERATIONS);
        $ict = new Department()->setName(self::ICT);

        $zone = new Zone()->setArea($kilimani)->setName('Lone Hills')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[37.0,-2.9],[37.2,-2.9],[37.2,-2.7],[37.0,-2.7],[37.0,-2.9]]]]}');
        $station = new Station()->setArea($kilimani)->setName('Mlima Station')
            ->setPoint('{"type":"Point","coordinates":[37.1,-2.8]}');

        $position = new Position()->setName('Ranger')->setGrantValues([], []);

        $member = self::staff('Naserian', 'Lekishon', 'naserian.lekishon@unr.example')
            ->setPosition($position)
            ->setPlacement(new Placement()->inAreas([$kilimani])->inDepartment($operations));

        $invitation = bin2hex(random_bytes(32));
        $invited = self::staff('Baraka', 'Mollel', 'baraka.mollel@unr.example')
            ->setVerified(false)
            ->setVerificationToken($invitation)
            ->setInvitationExpiresAt(new \DateTimeImmutable('+1 day'));

        $reset = bin2hex(random_bytes(32));
        $resetting = self::staff('Rehema', 'Kimaro', 'rehema.kimaro@unr.example')
            ->setPasswordResetToken($reset);

        $emailChange = bin2hex(random_bytes(32));
        $moving = self::staff('Salum', 'Mwaipopo', 'salum.mwaipopo@unr.example')
            ->requestEmailChange('salum.m@unr.example', $emailChange, new \DateTimeImmutable());

        foreach ([$kilimani, $tambarare, $operations, $ict, $zone, $station, $position, $member, $invited, $resetting, $moving] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return new self(
            kilimani: (string) $kilimani->getUuidString(),
            tambarare: (string) $tambarare->getUuidString(),
            operations: (int) $operations->getId(),
            ict: (int) $ict->getId(),
            operationsUuid: (string) $operations->getUuidString(),
            station: (string) $station->getUuidString(),
            zone: (string) $zone->getUuidString(),
            position: (string) $position->getUuidString(),
            member: (string) $member->getUuidString(),
            invitation: $invitation,
            reset: $reset,
            emailChange: $emailChange,
        );
    }

    /**
     * Each identifier with the name it is printed as, so the table reads the
     * same on every run although every run mints new identifiers.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return [
            $this->kilimani => '{kilimani}',
            $this->tambarare => '{tambarare}',
            $this->operationsUuid => '{operations}',
            $this->station => '{station}',
            $this->zone => '{zone}',
            $this->position => '{position}',
            $this->member => '{member}',
            $this->invitation => '{invitation}',
            $this->reset => '{reset}',
            $this->emailChange => '{email-change}',
        ];
    }

    private static function staff(string $first, string $last, string $email): User
    {
        return new User()
            ->setEmail($email)
            ->setFirstName($first)
            ->setLastName($last)
            ->setPassword('a hash, never a password')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true);
    }
}
