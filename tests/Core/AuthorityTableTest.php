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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Entity\PersonPosition;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PositionSourceEnum;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceFactsService;
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

    /** What an "own record" address is generated with for the table, printed as {own}. */
    private const string OWN_SHOWN = '00000000-0000-1000-8000-000000000000';

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
        // ONE KERNEL FOR THE WHOLE RUN, so one connection: every probe runs in
        // a transaction rolled back after it, and a write leaves nothing for
        // the next probe to find. Between requests the kernel resets its
        // services, as it does between two requests an installation serves.
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = $this->entityManager();
        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->world = World::seed($this->em, $this->catalogue()->exceptionPairs()[0] ?? '');

        // ON DUTY NOW, with a fresh ping each, so the live sheet has a mark to
        // answer about: the colleague at Kilimani and the person out of reach
        // at Tambarare.
        $this->onDuty($this->world->member, $this->world->kilimani, $this->world->station);
        $this->onDuty($this->world->outOfReach, $this->world->tambarare, null);
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
                if (!isset($probed[$key]) && !isset($named[$key])) {
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

    /**
     * A WRITE PROBE IS A REQUEST THAT WOULD SUCCEED. A Super Admin sending it
     * is never turned away — no 400, no 422, no error, no 403, which is also
     * what a wrong token answers, and never sent to sign in — or a cell saying
     * "allowed" would only mean "got past the gate", and one saying "refused"
     * might be the token's.
     */
    public function testEveryWriteProbeSendsAFormASuperAdminCanSave(): void
    {
        $turnedAway = [];

        foreach (CoreProbes::all($this->world) as $probe) {
            // ABOUT A COLLEAGUE, or about nobody: a Super Admin demoting or
            // deactivating themselves, or another Super Admin's account, is
            // refused by design, and that refusal is the table's to show.
            if ('GET' === $probe->method || isset(CoreProbes::NOT_SAVABLE_HERE[$probe->key()])
                || !\in_array($probe->target, [null, 'a colleague in reach'], true)) {
                continue;
            }

            [$status, $error, $location] = $this->send($probe, Person::SuperAdmin, $this->checks($probe));
            $signedOut = null !== $location && '/login' === parse_url($location, \PHP_URL_PATH) && 'team_login' !== $probe->route;

            if (\in_array($status, [400, 403, 422], true) || null !== $error || $signedOut) {
                $turnedAway[] = \sprintf('%s %s: %d%s', $probe->method, $probe->route, $status, null !== $error ? ' — '.$error : ($signedOut ? ' — sent to sign-in' : ''));
            }
        }

        self::assertSame([], $turnedAway, "These write probes send a form even a Super Admin cannot save:\n".implode("\n", $turnedAway));
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
                null === $probe->target ? $probe->route : $probe->route.' · '.$probe->target,
                $probe->method,
                $this->printed($this->router()->generate($probe->route, $this->own($probe->parameters, self::OWN_SHOWN))),
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
        [$status, $error, $location, $own] = $this->send($probe, $person, $checked);

        if (null !== $location) {
            $to = (string) parse_url($location, \PHP_URL_PATH);
            $to = null === $own ? $to : str_replace($own, Probe::OWN, $to);

            // A write refused with a message lands on a page like any other;
            // the message is what tells the two apart.
            return ('/login' === $to ? 'sign-in' : '→ '.$this->printed($to)).(null === $error ? '' : ' · error');
        }

        return match (true) {
            $status >= 200 && $status < 300 => 'allowed',
            403 === $status => 'refused',
            404 === $status => 'not found',
            default => (string) $status,
        };
    }

    /**
     * Sends a probe as a kind of person, inside a transaction rolled back
     * after it.
     *
     * @param list<string> $checked the pairs the route checks
     *
     * @return array{int, ?string, ?string, ?string} the status, the error the page reported if any, where a redirect went, and the sender's own identifier
     */
    private function send(Probe $probe, Person $person, array $checked): array
    {
        $this->client->restart();
        $account = $this->account($person, $checked);

        $connection = $this->em()->getConnection();
        $connection->beginTransaction();

        try {
            if (null !== $account) {
                $this->client->loginUser($account);
            }

            if (Person::DeactivatedWhileSignedIn === $person && null !== $account) {
                $connection->executeStatement('UPDATE team_user SET is_active = false WHERE id = ?', [$account->getId()]);
            }

            $body = $probe->body;
            foreach ($body as $field => $value) {
                if (Probe::OWN_NAME === $value) {
                    $body[$field] = null === $account ? '' : $account->getFullName();
                }
            }
            $server = [];
            if (null !== $probe->token) {
                $token = $this->mint($probe->token);
                if (null !== $probe->json) {
                    $server['HTTP_'.strtoupper(str_replace('-', '_', $probe->tokenField))] = $token;
                    $server['CONTENT_TYPE'] = 'application/json';
                } else {
                    $body[$probe->tokenField] = $token;
                }
            }

            $files = [];
            foreach ($probe->files as $field => $path) {
                $copy = tempnam(sys_get_temp_dir(), 'probe');
                self::assertIsString($copy);
                copy($path, $copy);
                $files[$field] = new UploadedFile($copy, basename($path), 'application/geo+json', null, true);
            }

            // Somebody signed out has no record of their own; their "own
            // record" is asked about the colleague's, which they cannot reach either.
            $path = $this->router()->generate($probe->route, $this->own($probe->parameters, (string) ($account?->getUuidString() ?? $this->world->member)));
            $this->client->request($probe->method, $path, $body, $files, $server, $probe->json);
            $response = $this->client->getResponse();
            $status = $response->getStatusCode();

            self::assertLessThan(500, $status, \sprintf(
                '%s %s as %s failed with %d: an error is never an answer. %s',
                $probe->method,
                $path,
                $person->value,
                $status,
                rawurldecode((string) $response->headers->get('X-Debug-Exception')).' at '.rawurldecode((string) $response->headers->get('X-Debug-Exception-File')),
            ));

            $session = $this->client->getSession();
            $errors = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->peek('error') : [];
            $error = [] === $errors ? null : implode(' ', array_map(static fn (mixed $e): string => \is_string($e) ? $e : '', $errors));

            return [$status, $error, $response->isRedirection() ? (string) $response->headers->get('Location') : null, $account?->getUuidString()];
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            // THE ROLLBACK RESTORES THE DATABASE, NOT WHAT DOCTRINE HOLDS. A
            // write that changed an account in memory — a new password hash —
            // would otherwise sign the next probe in with an account the
            // database no longer has, and the firewall rightly ends that session.
            $this->em()->clear();
        }
    }

    /**
     * The parameters with the sender's own record filled in.
     *
     * @param array<string, string> $parameters
     *
     * @return array<string, string>
     */
    private function own(array $parameters, string $uuid): array
    {
        return array_map(static fn (string $value): string => Probe::OWN === $value ? $uuid : $value, $parameters);
    }

    /**
     * An open check-in and a ping a minute old, the way the handset records
     * them, folded into the presence facts the live reads use.
     */
    private function onDuty(string $person, string $area, ?string $station): void
    {
        $em = $this->em();
        $account = $em->getRepository(User::class)->findOneBy(['uuid' => $person]);
        $ground = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $area]);
        $post = null === $station ? null : $em->getRepository(Station::class)->findOneBy(['uuid' => $station]);
        self::assertInstanceOf(User::class, $account);
        self::assertInstanceOf(AreaOfInterest::class, $ground);

        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        self::assertInstanceOf(CheckInStatusService::class, $statuses);
        $status = $statuses->offeredBy($ground)[0] ?? null;
        self::assertInstanceOf(CheckInStatus::class, $status);

        $now = new \DateTimeImmutable();
        $checkIn = new CheckIn()->setArea($ground)->setPerson($account)->setClientRef('duty-'.$person)
            ->setLocalDate($now->setTime(0, 0))->setStatus($status)->setStation($post)
            ->setOccurredAt($now->modify('-1 hour'))->setDeviceId('a phone')->setAppVersion('0.5.5');
        $ping = new PersonPosition()->setArea($ground)->setPerson($account)->setCheckIn($checkIn)
            ->setClientRef('ping-'.$person)->setRecordedAt($now->modify('-1 minute'))
            ->setPosition('{"type":"Point","coordinates":[37.1,-2.8]}')->setAccuracyM(8.0)->setSource(PositionSourceEnum::Gps);
        $em->persist($checkIn);
        $em->persist($ping);
        $em->flush();

        $facts = static::getContainer()->get('test_public.area.presence_facts');
        self::assertInstanceOf(PresenceFactsService::class, $facts);
        $facts->recordPings([$ping]);
    }

    /** A real token for this id, in the session the browser is about to send. */
    private function mint(string $id): string
    {
        $session = $this->client->getSession();
        self::assertNotNull($session);

        $stack = static::getContainer()->get('request_stack');
        $manager = static::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(RequestStack::class, $stack);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

        $request = new Request();
        $request->setSession($session);
        $stack->push($request);

        try {
            return $manager->getToken($id)->getValue();
        } finally {
            $stack->pop();
            $session->save();
        }
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
            ->setPassword($this->passphraseHash())
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

    private ?string $passphraseHash = null;

    /** Every account's password is the world's passphrase, so a write that asks for the current one can be sent. */
    private function passphraseHash(): string
    {
        if (null === $this->passphraseHash) {
            $hasher = static::getContainer()->get('security.user_password_hasher');
            self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
            $this->passphraseHash = $hasher->hashPassword(new User(), World::PASSPHRASE);
        }

        return $this->passphraseHash;
    }

    /**
     * An address with the world's identifiers printed as their names, and an
     * identifier the world did not seed — a record the write just made — as
     * {new}, so the table reads the same on every run.
     */
    private function printed(string $path): string
    {
        return (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{new}', strtr($path, [self::OWN_SHOWN => Probe::OWN] + $this->world->names()));
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
