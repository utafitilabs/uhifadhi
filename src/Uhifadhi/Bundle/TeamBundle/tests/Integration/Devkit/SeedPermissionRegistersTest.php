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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Devkit;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Uhifadhi\Bundle\TeamBundle\Devkit\TeamContentProvider;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionChange;
use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\EventListener\PermissionRunListener;
use Uhifadhi\Bundle\TeamBundle\Message\RecomputePermissions;
use Uhifadhi\Bundle\TeamBundle\MessageHandler\RecomputePermissionsHandler;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\DevkitContentCollector;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\IntegrationTestCase;

/**
 * THE ANALYSIS REGISTERS, EACH WITH ROWS ON A SEEDED INSTALLATION.
 *
 * An empty register draws its one-line empty state and no filter bar, so a
 * register the seed leaves empty is a register nobody can check. Each one is
 * read here through the reader the Analysis page reads, after the seed and
 * after the worker has answered the request the seed's last history lines
 * raised — the two things a developer's `fixtures:seed` does before the page
 * is opened.
 */
#[CoversClass(TeamContentProvider::class)]
final class SeedPermissionRegistersTest extends IntegrationTestCase
{
    public function testSensitivePairsAreHeldWithNoWrittenReason(): void
    {
        $rows = $this->register('not-in-force');

        self::assertCount(3, $rows);
        self::assertSame(['locations.read'], array_values(array_unique(self::column($rows, 'pair'))));
        self::assertEqualsCanonicalizing(['Analyst', 'Records Clerk', 'Sergeant'], self::column($rows, 'position'));
    }

    public function testTierOnlyPairsAreStoredOnAPositionAndNotHonoured(): void
    {
        $rows = $this->register('not-honoured');

        self::assertCount(3, $rows);
        self::assertSame(['Records Clerk'], array_values(array_unique(self::column($rows, 'position'))));
        self::assertEqualsCanonicalizing(['directory.manage', 'personal-details.manage', 'positions.configure'], self::column($rows, 'pair'));
    }

    public function testExceptionsAreInForceWithTheirWrittenReasons(): void
    {
        $rows = $this->register('exceptions');

        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(['Control Room Officer', 'Head Ranger'], self::column($rows, 'position'));
        foreach ($rows as $row) {
            self::assertIsString($row['reason']);
            self::assertGreaterThan(12, mb_strlen($row['reason']), 'a written reason, not a shrug');
        }
        self::assertEqualsCanonicalizing(['Amara Okonkwo', 'Desta Haile'], self::column($rows, 'by'), 'given by the two tiers above the matrix');
    }

    public function testPeopleChangedTheirOwnStandingAndTheHistorySaysSo(): void
    {
        $rows = $this->register('self-changes');

        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(['Neema Chacha', 'Tendai Moyo'], self::column($rows, 'name'));
        self::assertSame(['tier', 'tier'], self::column($rows, 'what'));

        foreach ($rows as $row) {
            $line = $this->em->getRepository(PermissionChange::class)->findOneBy(['subjectId' => $row['personId'], 'byId' => $row['personId']]);
            self::assertInstanceOf(PermissionChange::class, $line, 'their own change has its history line');
        }
    }

    /**
     * AN ANSWER THAT MOVED WITH NOTHING IN THE HISTORY TO SAY WHY: the
     * Records Clerk's card, rewritten between the two runs by an import that
     * wrote no line.
     */
    public function testAnswersDriftedBetweenTheTwoRunsWithNoHistoryLine(): void
    {
        $this->seedAndAnswer();

        $drift = $this->findings()->drift();

        self::assertGreaterThanOrEqual(2, \count($drift['lines']));
        self::assertSame(['Records Clerk'], array_values(array_unique(self::column($drift['lines'], 'subject'))));
        self::assertGreaterThan(0, $drift['unreviewed']);
    }

    /**
     * THE FOUR THE SEED ALREADY FILLED STAY FILLED: the new content adds to
     * the lists, it never empties one.
     */
    public function testTheListsTheSeedAlreadyFilledKeepTheirRows(): void
    {
        self::assertNotSame([], $this->register('reach-nothing'));
        self::assertNotSame([], $this->register('vacant'));
    }

    /**
     * A SECOND SEED CHANGES NOTHING: no person, no position, no history line,
     * no run, and every register reads as it did.
     */
    public function testASecondRunChangesNothing(): void
    {
        $this->seedAndAnswer();
        $before = $this->snapshot();

        $this->seedAndAnswer();

        self::assertSame($before, $this->snapshot());
    }

    /**
     * WHAT THE SEED COMMAND DOES, AND THEN WHAT ITS END DOES: the content,
     * then the wake a console command's TERMINATE sends, answered by the
     * handler the worker runs.
     */
    private function seedAndAnswer(): void
    {
        $collector = static::getContainer()->get('test_public.devkit_content');
        self::assertInstanceOf(DevkitContentCollector::class, $collector);
        $collector->seed('team');

        $listener = $this->service(PermissionRunListener::class);
        $listener->wake();

        $transport = static::getContainer()->get('test_public.permissions_transport');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = $this->service(RecomputePermissionsHandler::class);
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RecomputePermissions::class, $message);
            $handler($message);
            $transport->ack($envelope);
        }

        $this->em->clear();
    }

    /** @return list<array<string, mixed>> the latest run's entries on one standing list */
    private function register(string $list): array
    {
        $this->seedAndAnswer();

        foreach ($this->findings()->standing()['lists'] as $standing) {
            if ($list === $standing['id']) {
                return $standing['entries'];
            }
        }

        self::fail(\sprintf('No standing list "%s".', $list));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $counts = [];
        foreach ($this->findings()->standing()['lists'] as $standing) {
            $counts[$standing['id']] = $standing['count'];
        }

        return [
            'lists' => $counts,
            'drift' => \count($this->findings()->drift()['lines']),
            'runs' => \count($this->em->getRepository(PermissionRun::class)->findAll()),
            'lines' => \count($this->em->getRepository(PermissionChange::class)->findAll()),
            'people' => \count($this->em->getRepository(User::class)->findAll()),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function column(array $rows, string $key): array
    {
        return array_map(static fn (array $row): string => \is_scalar($row[$key] ?? null) ? (string) $row[$key] : '', $rows);
    }

    private function findings(): PermissionFindingsReader
    {
        return $this->service(PermissionFindingsReader::class);
    }
}
