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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration;

use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\MappingException;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * FROM `composer require` TO TABLES — the claims the registry's recipe makes about
 * that road, pinned here so the documentation cannot quietly become a lie.
 *
 * The first two were found the same way: by installing a project with
 * `composer create-project` and following the instructions as written. Neither
 * survived the walk, and the recipe's comments now say what these tests say.
 *
 * 1. AN AREA IS REQUIRED, NOT OPTIONAL. Reading `resolve_target_entities` as
 *    the step that merely buys you the per-area half implies an installation
 *    without it simply has less. It has less *and* no schema at all: every tool
 *    that resolves the association stops on the unresolved interface. The claim
 *    under test is the honest one — a bundle that boots without an answer and
 *    cannot be schema'd without one.
 *
 * 2. THE TOOL THAT CREATES THOSE TABLES SHIPS WITH THE BUNDLE THAT ADDS THEM.
 *    An installed project had no `doctrine:migrations:*` commands, because
 *    nothing in the chain required the bundle — the registry contributed two
 *    tables and left the operator to discover there was nothing to create them
 *    with.
 *
 * 3. THE REGISTRY ANSWERS ITS OWN CONTRACT FOR NOBODY, AND MUST NOT. Whoever knows
 *    the answer states the resolution: the registry does not know one, because it
 *    holds the per-area table for installations whose area model is their own.
 *    So it yields — and that abstention is what lets AreaBundle
 *    prepend the answer and an installation write no doctrine line at all. It
 *    is asserted here rather than assumed, because a resolution quietly added
 *    to this bundle would break every installation that brought its own area
 *    and nothing would say so.
 *
 * WHAT THIS SUITE CANNOT DO is install the answer-module and watch the schema
 * build: AreaBundle requires the registry, so the registry requiring it back would be
 * a cycle. That half is pinned on the other side, in AreaBundle's own
 * `Integration/InstallabilityTest`, which boots this bundle alongside it and
 * asserts all three tables appear with no doctrine configuration at all.
 */
final class InstallabilityTest extends RegistryKernelTestCase
{
    /**
     * The registry alone boots. That is the state a host is in between
     * `composer require` and writing its area entity, and it must not be a
     * broken one.
     */
    public function testTheRegistryAloneBootsWithoutAnAreaMapping(): void
    {
        self::bootKernel();

        self::assertInstanceOf(EntityManagerInterface::class, $this->entityManager());
    }

    /**
     * …and cannot be given a schema, naming the interface as it refuses.
     *
     * The message matters as much as the failure: it is the string an operator
     * pastes into a search box, and it is quoted verbatim in
     * `config/packages/registry.yaml` so that the answer is in the file the error
     * is about.
     */
    public function testWithoutTheAreaMappingThereIsNoSchemaToCreate(): void
    {
        self::bootKernel();

        $em = $this->entityManager();
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Class 'Uhifadhi\\Contracts\\Entity\\AreaInterface' does not exist");

        new SchemaTool($em)->getCreateSchemaSql($metadata);
    }

    /**
     * The migrations bundle is the registry's dependency, not the host's homework.
     *
     * A bundle that contributes tables owns the need for a migration tool the
     * same way it owns the need for the ORM — which the registry has always
     * required. It owns the VERSIONS too: the catalogue and the per-area ledger
     * are the registry's tables, so the statements that create them arrive with
     * the code that maps them, and an installation runs them rather than
     * generating its own.
     */
    public function testTheMigrationToolArrivesWithTheBundleThatAddsTables(): void
    {
        self::assertTrue(
            class_exists(DoctrineMigrationsBundle::class),
            'an installed project gets doctrine:migrations:* because the registry requires the bundle',
        );

        self::assertSame(
            ['Version20260101000200.php', 'Version20260919000100.php', 'Version20260924000100.php'],
            array_map(basename(...), glob(\dirname(__DIR__, 2).'/migrations/*.php') ?: []),
            'and the registry ships the versions that create and grow its own two tables',
        );
    }

    /**
     * THE REGISTRY YIELDS. Its own kernel resolves the association to the INTERFACE
     * and not to a class, which is the state that makes an answer-module's
     * prepend the effective one — and the state an installation with its own
     * area entity depends on.
     */
    public function testTheRegistryPrependsNoAnswerOfItsOwn(): void
    {
        self::bootKernel();

        self::assertSame(
            AreaInterface::class,
            $this->entityManager()
                ->getClassMetadata(AreaModule::class)
                ->getAssociationMapping('area')
                ->targetEntity,
            'the registry must not name an area class; whoever knows the answer states the resolution',
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
