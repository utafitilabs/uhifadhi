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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Facts;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uhifadhi\Bundle\RegistryBundle\Repository\FigureFactRepository;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\FactsHostKernel;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\SurveyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures\TallyFactProvider;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\RegistryKernelTestCase;
use Uhifadhi\Contracts\Facts\FactReaderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;

/**
 * A booted installation with the facts ledger's table and two modules that
 * compute facts, on a clock fixed at 2026-09-25 14:00.
 */
abstract class FactsTestCase extends RegistryKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return FactsHostKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        SurveyFactProvider::reset();
        TallyFactProvider::$asked = [];

        self::bootKernel();

        // THE DATABASE IS SHARED WITH EVERY OTHER SUITE, whose tables may
        // hold foreign keys into this one's; a metadata-driven drop cannot
        // see those. So the schema goes back to what a test database starts
        // as — nothing but PostGIS — as the area suite's does.
        $em = $this->em();
        $connection = $em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->clear();
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function ledger(): FigureFactRepository
    {
        $ledger = self::getContainer()->get('test.'.FigureFactRepository::class);
        \assert($ledger instanceof FigureFactRepository);

        return $ledger;
    }

    protected function reader(): FactReaderInterface
    {
        $reader = self::getContainer()->get('test.registry.facts.reader');
        \assert($reader instanceof FactReaderInterface);

        return $reader;
    }

    /** Write one area figure the way a run does. */
    protected function write(string $area, string $figure, string $periodKey, ?float $value, string $at): void
    {
        $this->ledger()->upsert(
            [new FactValue(FactSubject::AREA, $area, $figure, $value)],
            $periodKey,
            new \DateTimeImmutable($at),
        );
    }

    /**
     * Type a console command into the booted installation.
     *
     * @param array<string, string|list<string>> $input
     *
     * @return array{int, string} the exit status and what it printed
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     */
    protected function console(array $input): array
    {
        $kernel = self::$kernel;
        \assert(null !== $kernel);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        $output = new BufferedOutput();
        $status = $application->run(new ArrayInput($input), $output);

        return [$status, $output->fetch()];
    }

    /**
     * What a provider was asked, as "period: figure, figure [subject]".
     *
     * @param list<FactRequest> $asked
     *
     * @return list<string>
     */
    protected static function asked(array $asked): array
    {
        return array_map(
            static fn (FactRequest $request): string => $request->period->key.': '.implode(', ', $request->figureKeys)
                .(null === $request->subjectUuid ? '' : ' ['.$request->subjectUuid.']'),
            $asked,
        );
    }

    /** One stored value, read straight from the table. */
    protected function stored(string $subject, string $figure, string $periodKey): ?float
    {
        $value = $this->em()->getConnection()->fetchOne(
            'SELECT value FROM figure_fact WHERE subject_uuid = :s AND figure_key = :f AND period_key = :p',
            ['s' => $subject, 'f' => $figure, 'p' => $periodKey],
        );

        return is_numeric($value) ? (float) $value : null;
    }

    protected function rows(): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM figure_fact');
        \assert(is_numeric($count));

        return (int) $count;
    }
}
