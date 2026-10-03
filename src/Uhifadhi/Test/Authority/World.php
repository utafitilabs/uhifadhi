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

namespace Uhifadhi\Test\Authority;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\OrgOverviewCatalogue;
use Uhifadhi\Bundle\AreaBundle\Widget\AreaIndexWidgets;
use Uhifadhi\Bundle\ShellBundle\Widget\Entity\WidgetCustomPreset;
use Uhifadhi\Bundle\TeamBundle\Entity\ApiToken;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentKind;
use Uhifadhi\Bundle\TeamBundle\Entity\GrantJustification;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\Rank;
use Uhifadhi\Bundle\TeamBundle\Entity\RankScale;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\GoalDirectionEnum;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Widget\DepartmentWidgets;

/**
 * THE ORGANIZATION EVERY PROBE IS SENT INTO: Uhifadhi Nature Reserves, with
 * Kilimani and Tambarare Game Reserves, two organization-wide departments, a
 * station and a zone at Kilimani, a position, a member of staff posted at the
 * station, the three links a person is sent by email, and what the writes act
 * on: a goal, a department kind, two rank scales, a phone's token and a
 * custom widget layout on each surface — those last belong to the member, so
 * only the member could use them.
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

    /** Every account's password in the table, so a write that asks for the current one can be sent. */
    public const string PASSPHRASE = 'the authority table passphrase';

    /**
     * CANARIES: the colleague's address and phone, values no page prints by
     * accident. Seeing one is seeing a person's personal details.
     */
    public const string CANARY_EMAIL = 'naserian.c4f7e1@unr.example';
    public const string CANARY_PHONE = '+255 700 731 913';

    /** The full name each target is typed as on a delete page. */
    public const array NAMES = [
        'member' => 'Naserian Lekishon',
        'outOfReach' => 'Lomayani Laizer',
        'admin' => 'Upendo Massawe',
        'superAdmin' => 'Asha Kweka',
    ];

    private function __construct(
        public string $kilimani,
        public string $tambarare,
        public int $operations,
        public int $ict,
        public string $operationsUuid,
        public string $station,
        public string $zone,
        public string $position,
        public string $controlRoom,
        public string $member,
        public string $outOfReach,
        public string $admin,
        public string $superAdmin,
        public string $invited,
        public string $invitation,
        public string $reset,
        public string $emailChange,
        public string $goal,
        public string $kind,
        public string $scale,
        public string $otherScale,
        public string $rank,
        public int $handset,
        public string $posting,
        public string $exception,
        public string $orgPreset,
        public string $departmentPreset,
        public string $areasPreset,
    ) {
    }

    /**
     * @param string $exception a pair that lifts a rule, which a position holds only with a written reason
     */
    public static function seed(EntityManagerInterface $em, string $exception): self
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

        // HELD BY NOBODY, AND HOLDING AN EXCEPTION with its written reason, so
        // it can be retired and the exception taken back.
        $controlRoom = new Position()->setName('Control Room')->setGrantValues('' === $exception ? [] : [$exception], '' === $exception ? [] : [$exception]);
        $chief = self::staff('Asha', 'Kweka', 'asha.kweka@unr.example')->setTeamRole(TeamRoleEnum::SuperAdmin);
        $reasons = '' === $exception ? [] : [new GrantJustification($controlRoom, $exception, 'The control room watches every ranger on duty.', $chief, new \DateTimeImmutable())];

        $member = self::staff('Naserian', 'Lekishon', self::CANARY_EMAIL)
            ->setPhone(self::CANARY_PHONE)
            ->setPosition($position)
            ->setPlacement(new Placement()->inAreas([$kilimani])->inDepartment($operations));

        // THE OTHER TARGETS a person-route acts on: somebody placed where the
        // senders' placement does not reach, an Admin, and a Super Admin.
        $outOfReach = self::staff('Lomayani', 'Laizer', 'lomayani.laizer@unr.example')
            ->setPosition($position)
            ->setPlacement(new Placement()->inAreas([$tambarare])->inDepartment($ict));
        $admin = self::staff('Upendo', 'Massawe', 'upendo.massawe@unr.example')->setTeamRole(TeamRoleEnum::Admin);

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

        $goal = new DepartmentGoal()->setDepartment($operations)->setStatement('Patrol every zone each month')
            ->setTarget(12)->setUnit('patrols')->setDirection(GoalDirectionEnum::AtLeast)
            ->setOpensAt(new \DateTimeImmutable('first day of this month midnight'))
            ->setClosesAt(new \DateTimeImmutable('first day of next month midnight'));
        $kind = new DepartmentKind('Field', 'Works out on the ground');

        $scale = new RankScale()->setName('Rangers')->setSortOrder(0);
        $otherScale = new RankScale()->setName('Officers')->setSortOrder(1);
        $rank = new Rank($scale)->setName('Ranger I')->setShortCode('R1')->setSeniority(1);

        $handset = new ApiToken($member, hash('sha256', 'a phone nobody holds'), new \DateTimeImmutable('+30 days'));
        $posting = new Posting()->setStation($station)->setPerson($member)
            ->setSince(new \DateTimeImmutable('-1 month'))->setSource(PostingSource::WrittenHere);

        $presets = [
            new WidgetCustomPreset(OrgOverviewCatalogue::SURFACE, $member, null, 'Morning'),
            new WidgetCustomPreset(DepartmentWidgets::SURFACE, $member, null, 'Morning'),
            new WidgetCustomPreset(AreaIndexWidgets::SURFACE, $member, null, 'Morning'),
        ];

        foreach ([$kilimani, $tambarare, $operations, $ict, $zone, $station, $position, $controlRoom, $chief, ...$reasons, $member, $outOfReach, $admin, $invited, $resetting, $moving, $goal, $kind, $scale, $otherScale, $rank, $handset, $posting, ...$presets] as $entity) {
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
            controlRoom: (string) $controlRoom->getUuidString(),
            member: (string) $member->getUuidString(),
            outOfReach: (string) $outOfReach->getUuidString(),
            admin: (string) $admin->getUuidString(),
            superAdmin: (string) $chief->getUuidString(),
            invited: (string) $invited->getUuidString(),
            invitation: $invitation,
            reset: $reset,
            emailChange: $emailChange,
            goal: (string) $goal->getUuidString(),
            kind: (string) $kind->getUuidString(),
            scale: (string) $scale->getUuidString(),
            otherScale: (string) $otherScale->getUuidString(),
            rank: (string) $rank->getUuidString(),
            handset: (int) $handset->getId(),
            posting: (string) $posting->getUuidString(),
            exception: $exception,
            orgPreset: (string) $presets[0]->getUuidString(),
            departmentPreset: (string) $presets[1]->getUuidString(),
            areasPreset: (string) $presets[2]->getUuidString(),
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
            $this->controlRoom => '{control-room}',
            $this->member => '{member}',
            $this->outOfReach => '{out-of-reach}',
            $this->admin => '{admin}',
            $this->superAdmin => '{super-admin}',
            $this->invited => '{invited}',
            $this->invitation => '{invitation}',
            $this->reset => '{reset}',
            $this->emailChange => '{email-change}',
            $this->kind => '{kind}',
            $this->goal => '{goal}',
            $this->posting => '{posting}',
            $this->scale => '{scale}',
            $this->otherScale => '{other-scale}',
            $this->rank => '{rank}',
            $this->orgPreset => '{org-preset}',
            $this->departmentPreset => '{department-preset}',
            $this->areasPreset => '{areas-preset}',
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
