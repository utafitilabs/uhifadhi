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

namespace Uhifadhi\Bundle\TeamBundle\Devkit;

use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentService;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * A SMALL ORGANIZATION TO LOOK AT — three departments, five positions and six
 * people, so a developer's first screen is a populated one.
 *
 * THE POSITIONS GRANT SOMETHING, which is not decoration: a register where
 * every card reads "grants nothing" is a register that has never been read.
 *
 * IT GOES THROUGH THE SAME SERVICES THE SCREENS DO, and that is the whole
 * discipline of it. Demo content written straight to the tables is demo content
 * that can be shaped in ways the product cannot produce — a position holding a
 * permission no module declares, a department in a state no form can reach —
 * and every such row is a bug report about a screen that is working correctly.
 * Everything below is reachable by somebody clicking.
 *
 * IT SEEDS ONCE. An installation that already answers to any of the addresses
 * below is left exactly as it is: re-running a demo seeder is a developer
 * repeating a command, not an instruction to enter this organization twice —
 * and a department name is unique org-wide, so the second attempt is refused
 * rather than duplicated, taking every slice seeded after this one down with it.
 *
 * IT IS COLLECTED, NOT RUN. devkit installs through `require-dev`; in a
 * production build nothing collects this and it is an ordinary service nobody
 * ever asks anything of.
 *
 * NOBODY HERE HAS AN AREA. This bundle knows about people and never about
 * areas, so the departments it seeds are organization-wide and it depends on no
 * other content. A demo installation that also has areas confines them from the
 * screen, which is the same act an operator would perform.
 *
 * THE PASSWORDS ARE GENERATED AND NEVER PRINTED. Demo accounts are still
 * accounts: one shipped with a known password is a door left open on whatever
 * machine the demo was run on. Somebody who needs to sign in as one of these
 * resets it, exactly as they would for a colleague who has forgotten theirs.
 *
 * @see ContentProviderInterface
 */
final readonly class TeamContentProvider implements ContentProviderInterface
{
    /**
     * THE SIX ADDRESSES THIS SEEDS, written once and read twice: the roster
     * below is built from them, and whether any of them is already answered for
     * is how a second run recognises its own first one.
     *
     * The accounts are the detector rather than the departments, because an
     * account is what the whole slice ends in: an installation holding one of
     * these got here by running this, and the departments and positions in
     * front of it are already in place.
     *
     * @var array<string, string>
     */
    private const array ACCOUNTS = [
        'coordinator' => 'amara.okonkwo@example.test',
        'head_ranger' => 'desta.haile@example.test',
        'ranger' => 'kofi.mensah@example.test',
        'second_ranger' => 'nadia.sow@example.test',
        'analyst' => 'thabo.ndlovu@example.test',
        'unseated' => 'yara.benali@example.test',
    ];

    /**
     * WHAT EACH POSITION IS ALLOWED TO DO, and it is the point of the slice.
     *
     * A DEMO ORGANIZATION WHERE EVERY CARD READS "grants nothing" teaches the
     * register to say nothing. These are the pairs a park would actually
     * write: a coordinator administers the team, a head ranger runs the
     * ground and its assignments, a ranger reads it and books on for duty, an
     * analyst reads widely and exports, a sergeant stands between the two
     * field ones.
     *
     * THE PAIRS OUTSIDE THIS BUNDLE ARE WRITTEN AS STRINGS ON PURPOSE. Only
     * the team's own concerns are this bundle's to name in code: the area and
     * registry bundles depend on team, not the other way round, so importing
     * their catalogues here would invert the graph. {@see grant()} asks the
     * running catalogue what this installation actually declares and seeds the
     * intersection, which is exactly the set the configure screen would offer
     * — so an installation without one of those bundles seeds a smaller
     * organization rather than a failed one.
     *
     * @var array<string, list<string>>
     */
    private const array GRANTS = [
        'coordinator' => [
            TeamConcerns::DIRECTORY.'.read', TeamConcerns::DIRECTORY.'.manage',
            TeamConcerns::PERSONAL_DETAILS.'.read',
            TeamConcerns::POSITIONS.'.read', TeamConcerns::POSITIONS.'.configure',
            TeamConcerns::DEPARTMENTS.'.read', TeamConcerns::DEPARTMENTS.'.configure',
            'modules.read',
            'areas.read', 'stations.read', 'stations.configure', 'assignments.manage',
        ],
        'head_ranger' => [
            TeamConcerns::DIRECTORY.'.read',
            'areas.read', 'zones.read', 'stations.read', 'stations.configure',
            'assignments.manage', 'duty.record',
        ],
        'ranger' => [
            TeamConcerns::DIRECTORY.'.read',
            'areas.read', 'zones.read', 'stations.read', 'duty.record',
        ],
        'analyst' => [
            TeamConcerns::DIRECTORY.'.read',
            'modules.read',
            'areas.read', 'zones.read', 'zones.export',
        ],
        'sergeant' => [
            TeamConcerns::DIRECTORY.'.read',
            'areas.read', 'stations.read', 'assignments.manage', 'duty.record',
        ],
    ];

    /**
     * THE FIELD STAFF THE DEMO GROUND NEEDS, and the number is not arbitrary.
     *
     * The demo ground is two areas of twelve posts each, two posts in each
     * left deliberately empty because "nobody works out of here" is a state
     * the screens have to draw. The rest are not the same size — a main gate
     * holds five and an outpost holds two, which is what makes a duty board
     * worth reading — and somebody stands at ONE post (ruled), so the roster
     * has to be the sum of those sizes or the ground seeds half-empty and
     * the empty posts stop meaning anything.
     *
     * IT IS A NUMBER AND NOT A LOOKUP. This bundle knows nothing about ground
     * — the area bundle depends on it, not the other way round — so the two
     * cannot be wired together without inverting that. They are held in step
     * by a test in the core instead (DemoContentSeedsUnderTheRulesTest), which
     * may see both.
     */
    public const int FIELD_STAFF = 62;

    /**
     * The names they are seeded under, cycled with a surname list so twenty-
     * eight people read as people rather than as "Ranger 14". Nobody here is
     * anybody: they are the same invented-name shape as the six above.
     *
     * @var list<string>
     */
    private const array GIVEN = [
        'Adanna', 'Bakari', 'Chiamaka', 'Dawit', 'Eshe', 'Farai', 'Gugu',
        'Hakim', 'Imani', 'Juma', 'Kesi', 'Lulu', 'Mosi', 'Nia',
    ];

    /** @var list<string> */
    private const array FAMILY = ['Abara', 'Diallo', 'Kimathi', 'Mwangi', 'Nkosi', 'Osei', 'Tesfaye'];

    public function __construct(
        private UserService $accounts,
        private PositionService $positions,
        private DepartmentService $departments,
        private UserRepository $roster,
        private StaffingFigures $staffing,
        private PerformanceHistory $history,
        private ConcernCatalogue $catalogue,
        private PositionRepository $positionRows,
        private DepartmentRepository $departmentRows,
    ) {
    }

    public function key(): string
    {
        return 'team';
    }

    public function label(): string
    {
        return 'Team';
    }

    public function description(): string
    {
        return 'A small organization: departments, the positions filed under them, and the people who hold them.';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function load(): void
    {
        if ($this->alreadySeeded()) {
            // A SECOND SEED TOPS UP, NEVER REDRAWS: a demo position seeded
            // before it had anything to grant is granted now, by its name,
            // and only while it still grants nothing — a park that has since
            // ticked its own cells is left exactly as it is.
            foreach (self::GRANTS as $key => $pairs) {
                $position = $this->positionRows->findOneByName(ucwords(str_replace('_', ' ', $key)));
                if (null !== $position && [] === $position->getGrantValues()) {
                    $this->grant($position, $pairs);
                }
            }

            return;
        }

        // THE DEMO'S WORDS ARE FOUND BEFORE THEY ARE MADE. An installation that
        // already keeps a department or a position under one of these names
        // — recorded by hand, or imported — keeps its own record, and the demo
        // files its people under that one rather than failing on the name.
        $protection = $this->departmentNamed('Protection Service');
        $ecology = $this->departmentNamed('Ecology');
        $operations = $this->departmentNamed('Operations');

        // A POSITION BELONGS TO NO DEPARTMENT: it is named once, across the
        // organization, and where its holders work is written against each of
        // them below.
        $coordinator = $this->positionNamed('Coordinator');
        // ADMINISTERING THE TEAM, in pairs: writing the positions and the
        // departments, and reading the people they are about.
        $this->grant($coordinator, self::GRANTS['coordinator']);

        $headRanger = $this->positionNamed('Head Ranger');
        $ranger = $this->positionNamed('Ranger');
        $analyst = $this->positionNamed('Analyst');
        // A POSITION NOBODY HOLDS, and the register has to draw one: it is the
        // only state in which retiring is offered rather than refused.
        $sergeant = $this->positionNamed('Sergeant');

        $this->grant($headRanger, self::GRANTS['head_ranger']);
        $this->grant($ranger, self::GRANTS['ranger']);
        $this->grant($analyst, self::GRANTS['analyst']);
        $this->grant($sergeant, self::GRANTS['sergeant']);

        $this->person(self::ACCOUNTS['coordinator'], 'Amara', 'Okonkwo', TeamRoleEnum::SuperAdmin, $coordinator, [$operations]);
        $this->person(self::ACCOUNTS['head_ranger'], 'Desta', 'Haile', TeamRoleEnum::Admin, $headRanger, [$protection]);
        $this->person(self::ACCOUNTS['ranger'], 'Kofi', 'Mensah', TeamRoleEnum::Staff, $ranger, [$protection]);
        $this->person(self::ACCOUNTS['second_ranger'], 'Nadia', 'Sow', TeamRoleEnum::Staff, $ranger, [$protection]);
        // TWO DEPARTMENTS, ONE POSITION - the case the ruling exists for: an
        // analyst supporting Ecology and Protection is not two jobs.
        $this->person(self::ACCOUNTS['analyst'], 'Thabo', 'Ndlovu', TeamRoleEnum::Staff, $analyst, [$ecology, $protection]);

        // SOMEBODY WITH NO POSITION, because that is a real state the roster has
        // to draw: verified, able to sign in, and able to do nothing at all.
        $this->person(self::ACCOUNTS['unseated'], 'Yara', 'Benali', TeamRoleEnum::Staff, null, []);

        $this->fieldStaff($ranger, $protection);

        $this->twelveMonthsOfHistory([$protection, $ecology, $operations]);
    }

    /**
     * THE PEOPLE WHO ACTUALLY WORK OUT OF THE POSTS.
     *
     * The six above are the roles a reader has to see — the super admin, the
     * one who may administer, the analyst, the person holding nothing. These
     * are the body of the organization, and without them the demo ground
     * seeds with three posts staffed and thirteen empty, which makes the one
     * deliberately empty post say nothing at all.
     *
     * THEY ALL HOLD THE SAME POSITION, because that is what a field roster
     * looks like: one position, many people, which is also the case the
     * positions screen is built to show.
     */
    /**
     * WHAT THIS INSTALLATION WILL ACTUALLY HAVE OF A PROFILE.
     *
     * The intersection, in the catalogue's own order, so the seeded position
     * is one the configure screen could have produced click by click. A pair
     * nothing declares is not an error and not a silent loss either: it is a
     * module that is not installed here, and the position is simply smaller.
     *
     * @param list<string> $wanted each written `<concern>.<verb>`
     */
    private function grant(Position $position, array $wanted): void
    {
        $declared = $this->catalogue->pairs();

        $this->positions->setGrants(
            $position,
            array_values(array_filter($declared, static fn (string $pair): bool => \in_array($pair, $wanted, true))),
        );
    }

    private function fieldStaff(Position $ranger, Department $protection): void
    {
        for ($n = 0; $n < self::FIELD_STAFF; ++$n) {
            $given = self::GIVEN[$n % \count(self::GIVEN)];
            $family = self::FAMILY[intdiv($n, \count(self::GIVEN)) % \count(self::FAMILY)];

            $this->person(
                \sprintf('%s.%s%d@example.test', mb_strtolower($given), mb_strtolower($family), $n + 1),
                $given,
                $family,
                TeamRoleEnum::Staff,
                $ranger,
                [$protection],
            );
        }
    }

    /**
     * A YEAR OF CLOSED PERIODS, so the performance page has something to
     * compare against on the day it is first opened.
     *
     * THE FIGURES ARE THE STAFFING ONES the host answers for, walked
     * backwards from what is true now: a department that holds four seats
     * today held three or four last spring, which is how an organization
     * actually moves. Nothing here is a module's — a module publishes its
     * own history through its own snapshot.
     *
     * IT IS DEMO CONTENT AND IT SAYS SO BY BEING HERE: an installation
     * that has not run the devkit has no history, and its pages say "no
     * history yet" rather than drawing a flat line at nought.
     *
     * @param list<Department> $departments
     */
    private function twelveMonthsOfHistory(array $departments): void
    {
        $now = new \DateTimeImmutable('first day of this month');

        foreach ($departments as $index => $department) {
            $today = $this->staffing->of($department);

            // ONE MONTH AT A TIME, OLDEST FIRST, arriving at today's figure:
            // the run reads as a department that grew rather than as noise.
            for ($back = 12; $back >= 1; --$back) {
                $period = PerformanceHistory::monthKey($now->modify(\sprintf('-%d months', $back)));
                $shrink = min($back, 2 + $index % 2);

                foreach ($today as $key => $value) {
                    $then = match ($key) {
                        StaffingFigures::POSITIONS, StaffingFigures::FILLED, StaffingFigures::PEOPLE => max(0.0, $value - $shrink),
                        default => $value,
                    };

                    $this->history->record($department, $period, $key, $then);
                }

                // AND VACANCY IS THE DIFFERENCE, not a figure of its own:
                // two numbers that disagree about the same month would be
                // two histories.
                $this->history->record(
                    $department,
                    $period,
                    StaffingFigures::VACANT,
                    max(0.0, ($today[StaffingFigures::POSITIONS] - $shrink) - ($today[StaffingFigures::FILLED] - $shrink)),
                );
            }
        }
    }

    /**
     * ANY ONE OF THE ADDRESSES BEING ANSWERED IS ENOUGH. A run that stopped
     * part-way through left some of them and not others, and the honest reading
     * of that is still "this has been here" — the departments it would start
     * with are the ones that refuse a second write.
     */
    private function departmentNamed(string $name): Department
    {
        return $this->departmentRows->findOneByName($name) ?? $this->departments->create($name, null);
    }

    private function positionNamed(string $name): Position
    {
        return $this->positionRows->findOneByName($name) ?? $this->positions->create($name);
    }

    private function alreadySeeded(): bool
    {
        foreach (self::ACCOUNTS as $email) {
            if (null !== $this->roster->findOneByEmail($email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SOMEBODY, THEIR POSITION AND WHERE THEY ARE PLACED. The demo places
     * everybody across the organization, because this content seeds no areas
     * of its own; the departments are the dimension it does exercise.
     *
     * @param list<Department> $departments
     */
    private function person(string $email, string $firstName, string $lastName, TeamRoleEnum $tier, ?Position $position, array $departments): void
    {
        $person = $this->accounts->create($email, $firstName, $lastName, bin2hex(random_bytes(24)), $tier, $position);

        if ([] === $departments) {
            return;
        }

        $this->accounts->place($person, new Placement()->acrossTheOrganization()->inDepartments($departments));
    }
}
