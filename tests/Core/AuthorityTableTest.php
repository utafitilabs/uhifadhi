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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Core\Tests\Application\Kernel;
use Uhifadhi\Core\Tests\Core\Authority\CoreProbes;
use Uhifadhi\Core\Tests\Core\Authority\Person;
use Uhifadhi\Core\Tests\Core\Authority\Probe;
use Uhifadhi\Core\Tests\Core\Authority\World;

/**
 * THE AUTHORITY TABLE: every route of the core, called as every kind of
 * person, compared with the reviewed table committed beside this test.
 *
 * Nothing here decides what an outcome should be. The firewall and the
 * voters decide, exactly as they do for a real request, and the table holds
 * what was reviewed. A change in who may open what is a change to the table:
 * it fails until it is recorded and its diff is read.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Core/AuthorityTableTest.php
 *
 * One line per route, one column per kind of person, so a changed answer is
 * one changed line in the diff.
 */
#[CoversNothing]
final class AuthorityTableTest extends WebTestCase
{
    private const string TABLE = __DIR__.'/authority-table.md';
    private const string RECORD = 'UHIFADHI_RECORD_AUTHORITY_TABLE';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private World $world;

    /** @var array<string, int> accounts already made, by kind and position, so each is made once */
    private array $accounts = [];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = $this->entityManager();
        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->world = World::seed($this->em);
    }

    protected function tearDown(): void
    {
        $em = $this->entityManager();
        new SchemaTool($em)->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    public function testEveryRouteHasAProbeOrAStatedReason(): void
    {
        $probed = [];
        foreach (CoreProbes::all($this->world) as $probe) {
            $probed[$probe->key()] = true;
        }
        $named = CoreProbes::PENDING + CoreProbes::SHADOWED;

        $unprobed = [];
        $routes = [];
        foreach ($this->router()->getRouteCollection()->all() as $name => $route) {
            foreach ([] === $route->getMethods() ? ['GET'] : $route->getMethods() as $method) {
                if ('HEAD' === $method) {
                    continue;
                }
                $key = $method.' '.$name;
                $routes[$key] = true;
                if (!isset($probed[$key]) && !isset($named[$key]) && !isset(CoreProbes::PENDING_METHODS[$method])) {
                    $unprobed[] = $key;
                }
            }
        }

        $stale = array_keys(array_diff_key($probed + $named, $routes));
        $twice = array_keys(array_intersect_key($probed, $named));

        self::assertSame([], $unprobed, "These routes have no probe, so the table cannot say who may open them:\n".implode("\n", $unprobed));
        self::assertSame([], $stale, "These probes or reasons name routes that do not exist:\n".implode("\n", $stale));
        self::assertSame([], $twice, "These routes are probed and also listed as not probed; take them off the list:\n".implode("\n", $twice));
    }

    public function testEveryRouteAnswersEveryKindOfPersonAsTheReviewedTableSays(): void
    {
        $table = $this->table();

        if ('1' === getenv(self::RECORD)) {
            file_put_contents(self::TABLE, $table);
            self::markTestIncomplete('The authority table was recorded. Read its diff before committing it.');
        }

        self::assertFileExists(self::TABLE, 'There is no reviewed table yet. Record it with '.self::RECORD.'=1 and read it.');
        self::assertSame(
            (string) file_get_contents(self::TABLE),
            $table,
            'Who may open what has changed. If the change is intended, record the table with '.self::RECORD.'=1 and review its diff; if it is not, a loophole has opened.',
        );
    }

    private function table(): string
    {
        $people = Person::cases();
        $lines = [
            '# Authority table',
            '',
            'Every route of the core, called as every kind of person. Generated by',
            '`tests/Core/AuthorityTableTest.php`; a change fails the build until it is',
            'recorded with `'.self::RECORD.'=1` and its diff is reviewed.',
            '',
            'The kinds of person: *signed out*; *deactivated* — an Admin deactivated while',
            'signed in; Staff with *no position*; Staff holding exactly *the pair* the route',
            'checks, placed at Kilimani in Operations; Staff holding *all but the pair*, placed',
            'the same; Staff holding *the pair, elsewhere* — placed at Tambarare in ICT; *Admin*;',
            '*Super Admin*. Records are Kilimani\'s and Operations\'.',
            '',
            '| Route | Request | Checks | '.implode(' | ', array_map(static fn (Person $person): string => $person->value, $people)).' |',
            '|'.str_repeat('---|', 3 + \count($people)),
        ];

        foreach (CoreProbes::all($this->world) as $probe) {
            $checked = $this->checks($probe);
            $cells = [];
            foreach ($people as $person) {
                $cells[] = $this->outcome($probe, $person, $checked);
            }

            $lines[] = \sprintf(
                '| %s | %s %s | %s | %s |',
                $probe->route,
                $probe->method,
                $this->printed($this->router()->generate($probe->route, $probe->parameters)),
                [] === $checked ? '—' : '`'.implode('`, `', $checked).'`',
                implode(' | ', $cells),
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * What one kind of person gets back.
     *
     * @param list<string> $checked the pairs the route checks
     */
    private function outcome(Probe $probe, Person $person, array $checked): string
    {
        $this->client->restart();

        $account = $this->account($person, $checked);
        if (null !== $account) {
            $this->client->loginUser($account);
        }

        $deactivated = Person::DeactivatedWhileSignedIn === $person && null !== $account;
        if ($deactivated) {
            $this->setActive($account, false);
        }

        $path = $this->router()->generate($probe->route, $probe->parameters);
        $this->client->request($probe->method, $path);
        $response = $this->client->getResponse();

        if ($deactivated) {
            $this->setActive($account, true);
        }

        $status = $response->getStatusCode();
        self::assertLessThan(500, $status, \sprintf(
            '%s %s as %s failed with %d: an error is never an answer. %s',
            $probe->method,
            $path,
            $person->value,
            $status,
            rawurldecode((string) $response->headers->get('X-Debug-Exception')).' at '.$response->headers->get('X-Debug-Exception-File'),
        ));

        if ($response->isRedirection()) {
            $to = (string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH);

            return '/login' === $to ? 'sign-in' : '→ '.$this->printed($to);
        }

        return match (true) {
            $response->isSuccessful() => 'allowed',
            403 === $status => 'refused',
            404 === $status => 'not found',
            default => (string) $status,
        };
    }

    /**
     * The account a kind of person signs in with, or null for somebody
     * signed out. Each is made once per position it needs.
     *
     * @param list<string> $checked
     */
    private function account(Person $person, array $checked): ?User
    {
        $everyPair = $this->catalogue()->positionPairs();
        $pairs = array_values(array_intersect($checked, $everyPair));

        [$tier, $grants, $area, $department] = match ($person) {
            Person::SignedOut => [null, null, null, null],
            Person::DeactivatedWhileSignedIn, Person::Admin => [TeamRoleEnum::Admin, null, null, null],
            Person::SuperAdmin => [TeamRoleEnum::SuperAdmin, null, null, null],
            Person::StaffWithoutPosition => [TeamRoleEnum::Staff, null, $this->world->kilimani, $this->world->operations],
            Person::StaffHoldingThePair => [TeamRoleEnum::Staff, $pairs, $this->world->kilimani, $this->world->operations],
            Person::StaffHoldingAllButThePair => [TeamRoleEnum::Staff, array_values(array_diff($everyPair, $pairs)), $this->world->kilimani, $this->world->operations],
            Person::StaffHoldingThePairElsewhere => [TeamRoleEnum::Staff, $pairs, $this->world->tambarare, $this->world->ict],
        };

        if (null === $tier) {
            return null;
        }

        $key = $person->name.'|'.implode(',', $grants ?? ['-']);
        if (isset($this->accounts[$key])) {
            $known = $this->em()->find(User::class, $this->accounts[$key]);
            self::assertInstanceOf(User::class, $known);

            return $known;
        }

        $em = $this->em();
        $user = new User()
            ->setEmail('person'.(\count($this->accounts) + 1).'@unr.example')
            ->setFirstName('Person')
            ->setLastName((string) (\count($this->accounts) + 1))
            ->setPassword('a hash, never a password')
            ->setTeamRole($tier)
            ->setVerified(true);

        if (null !== $grants) {
            $position = new Position()->setName('Seat '.(\count($this->accounts) + 1))->setGrantValues($grants, $everyPair);
            $em->persist($position);
            $user->setPosition($position);
        }

        if (null !== $area && null !== $department) {
            $ground = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $area]);
            $home = $em->find(Department::class, $department);
            self::assertInstanceOf(AreaOfInterest::class, $ground);
            self::assertInstanceOf(Department::class, $home);
            $placement = new Placement()->inAreas([$ground])->inDepartment($home);
            $em->persist($placement);
            $user->setPlacement($placement);
        }

        $em->persist($user);
        $em->flush();
        $this->accounts[$key] = (int) $user->getId();

        return $user;
    }

    /**
     * The pairs a route checks: read from its gate, and stated by the probe
     * where the controller asks them itself.
     *
     * @return list<string>
     */
    private function checks(Probe $probe): array
    {
        $route = $this->router()->getRouteCollection()->get($probe->route);
        self::assertNotNull($route, $probe->route.' is not a route.');

        $gate = array_filter(GateReader::pairsOn($route), static fn (string $attribute): bool => null !== Grant::tryParse($attribute));

        return array_values(array_unique([...$gate, ...$probe->asks]));
    }

    /** The account's active flag, changed in the database only, behind the open session. */
    private function setActive(User $account, bool $active): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE team_user SET is_active = ? WHERE id = ?', [$active, $account->getId()], ['boolean', 'integer']);
    }

    /** An address with the world's identifiers printed as their names. */
    private function printed(string $path): string
    {
        return strtr($path, $this->world->names());
    }

    private function router(): RouterInterface
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    private function catalogue(): ConcernCatalogue
    {
        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);

        return $catalogue;
    }

    /** The entity manager of the client's current kernel, which a sign-in replaces. */
    private function em(): EntityManagerInterface
    {
        return $this->entityManager();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
