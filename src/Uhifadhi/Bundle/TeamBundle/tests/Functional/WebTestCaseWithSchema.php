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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\TestKernel;
use Uhifadhi\Contracts\Access\ScopeKind;

/**
 * A browser and a real database, with the schema rebuilt per test, plus the
 * sample cast every screen suite needs.
 *
 * THE CAST IS INVENTED AND THE DOMAIN IS `example.test` — the domain the shipped
 * sign-in template's own placeholder uses, so it is provably nobody's. Three of
 * these people are load-bearing and appear in every suite that seeds them:
 * somebody with no position (the model's zero), a position nobody holds, and a
 * Staff member who administers the team because their position grants
 * `positions.configure`.
 */
abstract class WebTestCaseWithSchema extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    /**
     * NAMED IN CODE, not by KERNEL_CLASS. One repository holds several
     * packages, so one env var could only ever name one of their kernels.
     */
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        // EVERY TEST STARTS WITH A FULL RATE-LIMIT BUDGET. The limiters count
        // into a FILESYSTEM pool, as a deployment's do, so a window outlives
        // the process that opened it: two runs inside one minute would share
        // one budget and the second would fail for reasons that have nothing to
        // do with the code under test — every request here comes from the same
        // address, and the address budget is twenty a minute. The pool is
        // CLEARED rather than swapped for an in-memory one, because the
        // throttling suite needs a count that survives the kernel reboot
        // between its own requests; an in-memory store would be wiped by each
        // and the sixth attempt would never be throttled at all.
        $pool = static::getContainer()->get('test_public.rate_limiter_pool');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
    }

    protected function tearDown(): void
    {
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

    protected function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole($tier)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }

    protected function department(string $name): Department
    {
        $department = (new Department())->setName($name);
        $this->em->persist($department);

        return $department;
    }

    /**
     * A host area, played by the integration fixture the kernel resolves
     * {@see \Uhifadhi\Contracts\Entity\AreaInterface} to — the area an
     * area-level department is confined to.
     */
    protected function area(string $name): HostArea
    {
        $area = (new HostArea())->setName($name);
        $this->em->persist($area);

        return $area;
    }

    /** A department confined to one area — area-level, the scope derived from it. */
    protected function areaDepartment(string $name, HostArea $area): Department
    {
        $department = (new Department())->setName($name)->setArea($area);
        $this->em->persist($department);

        return $department;
    }

    /**
     * A POSITION CARRIES NO DEPARTMENT, and the argument that used to say so
     * is gone from the signature rather than ignored, so a suite written
     * against the old model fails loudly instead of quietly filing nothing.
     *
     * IT GRANTS PAIRS. The second argument used to be a list of flat
     * flat permission values; a grant is a
     * `<concern>.<verb>` pair now, so the same argument carries pairs and the
     * write is the validated pair one. The shape of the call is unchanged on
     * purpose — every suite in this package seeds a position through it — so
     * what a suite changes is the strings, not the line.
     *
     * THE CATALOGUE IS THE RUNNING ONE, read from the container rather than
     * spelled here: a helper that validated against its own hand-written list
     * would let a suite seed something the installation does not declare, and
     * the refusal that matters is the installation's.
     *
     * @param list<string>    $grants each written `<concern>.<verb>`
     * @param list<ScopeKind> $allows which kinds of placement it offers
     */
    protected function position(string $name, array $grants = [], array $allows = [ScopeKind::Organization, ScopeKind::Area]): Position
    {
        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        \assert($catalogue instanceof ConcernCatalogue);

        $position = (new Position())->setName($name)->setAllowedKinds($allows);
        $position->setGrantValues($grants, $catalogue->pairs());
        $this->em->persist($position);

        return $position;
    }

    /**
     * WHERE SOMEBODY IS PLACED — the second half of every check, written
     * against the person. Null areas is the whole organization; a list is the
     * named ground. Null departments is all of them.
     *
     * @param list<HostArea>|null   $areas
     * @param list<Department>|null $departments
     */
    protected function place(User $person, ?array $areas = null, ?array $departments = null): Placement
    {
        $placement = new Placement();
        null === $areas ? $placement->acrossTheOrganization() : $placement->inAreas($areas);
        null === $departments ? $placement->acrossAllDepartments() : $placement->inDepartments($departments);

        $this->em->persist($placement);
        $person->setPlacement($placement);

        return $placement;
    }

    /** Somebody who can reach every gated screen, so a suite can get in. */
    protected function administrator(): User
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        return $naomi;
    }

    /** Pull one CSRF token out of a rendered page, so a POST test posts a real one. */
    protected function tokenFrom(string $url, string $selector = 'input[name="_token"]'): string
    {
        $crawler = $this->client->request('GET', $url);

        return (string) $crawler->filter($selector)->first()->attr('value');
    }
}
