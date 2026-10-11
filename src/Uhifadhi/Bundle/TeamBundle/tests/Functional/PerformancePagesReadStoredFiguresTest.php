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

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Uhifadhi\Bundle\RegistryBundle\Service\FactRebuildService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Facts\TeamFactProvider;
use Uhifadhi\Contracts\Facts\FactPeriod;

/**
 * THE PERFORMANCE PAGES READ STORED FIGURES, so what a page costs does not
 * grow with what the organization holds.
 *
 * Each page is opened twice — over a small establishment, and again after
 * it has grown tenfold — and asks the database the same number of
 * questions both times. A page that counted posts or people itself would
 * ask more the second time.
 *
 * What the database was asked is read off the DBAL debug middleware the
 * profiler reads, `doctrine.debug_data_holder`, on in a debug kernel.
 *
 * @see https://symfony.com/doc/current/testing/profiling.html — the Doctrine collector's query count is the documented measure of a request's queries
 * @see vendor/doctrine/doctrine-bundle/config/middlewares.php — `doctrine.debug_data_holder`
 */
final class PerformancePagesReadStoredFiguresTest extends WebTestCaseWithSchema
{
    private int $made = 0;

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'overview' => ['/departments/performance'];
        yield 'overview, a year' => ['/departments/performance?period=year'];
        yield 'topics' => ['/departments/performance/topics'];
        yield 'staffing' => ['/departments/performance/topics/staffing'];
        yield 'staffing, a quarter' => ['/departments/performance/topics/staffing?period=quarter'];
        yield 'goals' => ['/departments/performance/topics/goals'];
        yield 'attention' => ['/departments/performance/topics/attention'];
        yield 'briefing' => ['/departments/performance/briefing'];
    }

    #[DataProvider('pages')]
    public function testAPageAsksAsMuchOfALargeEstablishmentAsOfASmallOne(string $page): void
    {
        $protection = $this->department('Protection Service');
        $wetlands = $this->areaDepartment('Wetland Management', $this->area('Northern Reserve'));
        $this->staff($protection, 2);
        $this->staff($wetlands, 2);
        $this->em->flush();
        $this->fileTheTeamsFigures();
        $this->administrator();

        // THE FIRST REQUEST SHARES THE SUITE'S KERNEL and finds what the
        // setup already loaded; every later one boots its own. Both
        // measured requests are later ones.
        $this->statementsFor($page);
        $small = $this->statementsFor($page);

        // A REQUEST AFTER THE FIRST SHUT THE SUITE'S KERNEL DOWN, and its
        // entity manager with it: the departments are found again.
        $this->staff($this->found($protection), 20);
        $this->staff($this->found($wetlands), 20);
        $this->em->flush();
        $this->fileTheTeamsFigures();

        self::assertSame($small, $this->statementsFor($page), 'the page asked more of a larger establishment');
    }

    /** People, each in a post of their own, placed in the department. */
    private function staff(Department $department, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            ++$this->made;
            $this->place(
                $this->person('Member', 'Number'.$this->made)->setPosition($this->position('Post '.$this->made)),
                null,
                [$department],
            );
        }
    }

    private function found(Department $department): Department
    {
        $found = $this->em->find(Department::class, $department->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function fileTheTeamsFigures(): void
    {
        /** @var FactRebuildService $rebuild */
        $rebuild = static::getContainer()->get('test_public.'.FactRebuildService::class);
        $rebuild->rebuild([FactPeriod::month(new \DateTimeImmutable())], TeamFactProvider::SLUG);
    }

    private function statementsFor(string $page): int
    {
        $this->log()->reset();

        $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        // READ FROM THE CONTAINER THAT ANSWERED: the browser boots a fresh
        // kernel for every request after its first.
        return \count($this->log()->getData()['default'] ?? []);
    }

    private function log(): BacktraceDebugDataHolder
    {
        $log = $this->client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $log);

        return $log;
    }
}
