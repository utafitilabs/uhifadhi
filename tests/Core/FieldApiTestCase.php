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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Service\ApiTokenManager;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * A BEARER TOKEN AND A REAL DATABASE, in the application that has every core
 * bundle in one kernel.
 *
 * The field API spans two packages — the account and its permissions are the
 * team's, the ground and its boundary are the area's — so a suite that boots
 * only one of them could never ask what a handset asks. This is the installation,
 * which is the only place these two endpoints exist at the same time.
 *
 * THE TOKEN IS MINTED THROUGH THE REAL CREDENTIAL STORE, never faked and never
 * stood in for by `loginUser()`: the point of every assertion here is that the
 * request crossed the stateless firewall and the bearer authenticator an
 * installation runs.
 *
 * THE CAST IS INVENTED and the domain is `example.test`, which is provably
 * nobody's.
 */
abstract class FieldApiTestCase extends WebTestCase
{
    /** The rectangle every area here is measured and drawn from — roughly 9,900 km². */
    protected const string A_BOUNDARY = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    /**
     * NAMED IN CODE, not by KERNEL_CLASS. One repository holds several packages,
     * so one env var could only ever name one of their kernels.
     */
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        /*
         * THE EXTENSION AN INSTALLATION ALWAYS HAS, PUT BACK.
         *
         * One database carries every suite in this repository, and the
         * specifications about migrating from nothing empty it by dropping the
         * PUBLIC SCHEMA — which takes the postgis extension with it, because that
         * is exactly the state migration zero is written for. Ground has a
         * `geometry` column, so a suite that runs after one of those would fail
         * on "type geometry does not exist", for a reason that has nothing to do
         * with what it is asserting.
         */
        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        // THE GEOMETRY TABLES DO NOT OUTLIVE THIS SUITE. A kernel without the
        // PostGIS bundle in it cannot introspect a `geometry` column, so a table
        // left behind here breaks a sibling's suite the moment somebody runs it
        // on its own.
        new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->em->close();
        parent::tearDown();

        // The debug error handler is registered during the test and never
        // popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /** An area with a gazetted boundary — the only kind a handset can be sent. */
    protected function area(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()
            ->setName($name)
            ->setGeom(self::A_BOUNDARY)
            ->setSource('upload');
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /** An area whose gazetted edge has not been imported yet — the state an area is created in. */
    protected function areaWithoutBoundary(string $name): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName($name);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /**
     * WHAT A RANGER WHO ONLY READS THE PARK HOLDS — the ground's own read
     * pairs, which is the reach of somebody the installation has given no
     * job beyond looking.
     *
     * @var list<string>
     */
    protected const array READS_THE_PARK = [
        'areas.read',
        'zones.read',
        'stations.read',
        'modules.read',
    ];

    /**
     * Somebody who works in the field: a service number, and a position carrying
     * the permissions named.
     *
     * THE PLACEMENT IS THE REACH, not the position: handed a department, the
     * ranger is placed at that department's area and in it; handed none, they
     * are placed across the whole organization and every department, which is
     * the reach of somebody the installation has not confined.
     *
     * EVERY GRANT IS A PAIR AND A PLAIN STRING. A module's concern is its
     * own, so a fixture that could only spell what the team bundle declares
     * could never grant one — and the endpoint that enforces it could not be
     * specified at all.
     *
     * @param list<string> $grants   the (concern, verb) pairs the position carries
     * @param list<string> $declared pairs other packages declare, granted verbatim
     */
    protected function ranger(
        string $rangerCode = 'sl-0142',
        array $grants = self::READS_THE_PARK,
        ?Department $department = null,
        array $declared = [],
    ): User {
        $position = new Position()->setName('Field Ranger');
        $pairs = array_values(array_unique([...$grants, ...$declared]));
        $position->setGrantValues($pairs, $pairs);
        $this->em->persist($position);

        $user = new User()
            ->setEmail($rangerCode.'@example.test')
            ->setFirstName('Witness')
            ->setLastName('Mbise')
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true)
            ->setRangerCode($rangerCode);
        $user->setPosition($position);

        $placement = new Placement();
        $area = $department?->getArea();
        null === $area ? $placement->acrossTheOrganization() : $placement->inAreas([$area]);
        null === $department ? $placement->acrossAllDepartments() : $placement->inDepartments([$department]);
        $this->em->persist($placement);
        $user->setPlacement($placement);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** A department confined to one area — area-level authority, derived from the scope. */
    protected function areaDepartment(string $name, AreaOfInterest $area): Department
    {
        $department = new Department()->setName($name)->setArea($area);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /** Anybody at all, for the roster — no position, no service number. */
    protected function officeStaff(string $first, string $last): User
    {
        $user = new User()
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)
            ->setLastName($last)
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** The plaintext credential a handset would hold, from the store that issues one. */
    protected function tokenFor(User $user): string
    {
        $tokens = static::getContainer()->get('test_public.team.api_token.manager');
        \assert($tokens instanceof ApiTokenManager);

        [$plaintext] = $tokens->issue($user, '7f1c2b90-0000-4000-8000-000000000001', 'the spare handset');

        return $plaintext;
    }

    /** @return array<array-key, mixed> */
    protected function get(string $path, ?string $token = null): array
    {
        $this->client->request(
            'GET',
            $path,
            server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($body) ? $body : [];
    }

    /** A post on the ground, with the ring that says what "inside" it means. */
    protected function station(AreaOfInterest $area, string $name, float $lon, float $lat, ?int $catchmentM = 300, ?string $code = null): Station
    {
        $station = new Station()
            ->setArea($area)
            ->setName($name)
            ->setPoint(\sprintf('{"type":"Point","coordinates":[%s,%s]}', $lon, $lat))
            ->setCode($code)
            ->setCatchmentM($catchmentM);
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    /**
     * SOMEBODY STANDING AT A POST — through the service that holds the rule,
     * because a test that wrote the row itself would pass while the rule the
     * product enforces was broken.
     */
    protected function postTo(Station $station, User $person): Posting
    {
        $postings = static::getContainer()->get('test_public.area.postings');
        self::assertInstanceOf(PostingService::class, $postings);

        $posting = $postings->post($station, $person, PostingSource::WrittenHere);
        $this->em->flush();

        return $posting;
    }

    /**
     * A WRITE, SENT THE WAY THE HANDSET SENDS IT: the body encoded as JSON,
     * `Content-Type: application/json`, and the credential in the header a
     * stateless firewall reads.
     *
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    protected function send(string $method, string $path, array $body, ?string $token = null): array
    {
        $this->client->request(
            $method,
            $path,
            server: array_merge(
                ['CONTENT_TYPE' => 'application/json'],
                null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
            ),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * A nested LIST OR OBJECT out of a decoded answer, addressed by its path.
     *
     * Every document on this wire is arrays inside arrays, and a specification
     * that narrowed each level by hand would read as an essay about a decoder
     * rather than as a statement about the contract. The walk asserts what the
     * contract already promises, so a missing member fails HERE, naming the key,
     * instead of somewhere further down as a type error.
     *
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    protected static function nested(array $document, int|string ...$path): array
    {
        $value = $document;

        foreach ($path as $key) {
            $next = $value[$key] ?? null;
            \assert(\is_array($next), \sprintf('the answer carries an array at "%s"', $key));
            $value = $next;
        }

        return $value;
    }

    /**
     * A LEAF out of a decoded answer — a string, a number, or the null the
     * contract allows. Returned untyped on purpose: what it should be is the
     * assertion's business, and narrowing it here would make a specification
     * about a field's TYPE pass without ever looking at it.
     *
     * @param array<array-key, mixed> $document
     */
    protected static function leaf(array $document, int|string ...$path): mixed
    {
        $last = array_pop($path);
        \assert(null !== $last);

        return self::nested($document, ...$path)[$last] ?? null;
    }
}
