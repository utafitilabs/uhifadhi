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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\SignedInPerson;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleCategory;
use Uhifadhi\Bundle\RegistryBundle\Enum\ModuleStatus;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;

/**
 * A page, a real database, and a viewer holding exactly the permissions the test
 * names. Boots {@see WebKernel} rather than the bare kernel, because a screen
 * needs twig to render in and a firewall to be refused by.
 */
abstract class WebTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    /**
     * EVERY PAIR THE GROUND'S CONCERNS OFFER — the ordinary admin. Spelt out
     * rather than read from the catalogue, because a suite that asked the
     * declaration what it declares would pass however the declaration drifted.
     *
     * @see \Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns
     */
    protected const array ALL_AREA_PERMISSIONS = [
        'areas.read', 'areas.configure',
        'zones.read', 'zones.configure', 'zones.delete', 'zones.export',
        'stations.read', 'stations.configure',
        'assignments.read', 'assignments.manage',
        'duty.read', 'duty.record',
    ];

    /**
     * THE READING HALF OF THEM: somebody who may look at every part of the
     * ground and change none of it. This is what proves a write is refused
     * without also proving the page went dark.
     */
    protected const array READ_ONLY_AREA_PERMISSIONS = [
        'areas.read', 'zones.read', 'stations.read', 'assignments.read', 'duty.read',
    ];

    /**
     * @param list<string> $grants
     * @param int          $attention how many items the stand-in module raises
     * @param string|null  $hubUrl    the hub's address, '' for the deployment that configured none, or null for the installation without the hub bundle
     */
    protected function boot(array $grants = self::ALL_AREA_PERMISSIONS, int $attention = 2, string $clock = WebKernel::CLOCK, int $figures = 1, ?string $hubUrl = WebKernel::HUB_URL): void
    {
        self::ensureKernelShutdown();
        $kernel = new WebKernel($grants, $attention, $clock, $figures, $hubUrl);
        $kernel->boot();
        self::$kernel = $kernel;
        self::$booted = true;
        $this->browser = null;

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        // The same reset the integration base performs: a suite that ran
        // before may have left tables this kernel does not map, and a foreign
        // key from one of them blocks the metadata-driven drop.
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $connection->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');
        $schemaTool->createSchema($metadata);

        // THE IDENTITY MAP IS EMPTIED WITH THE TABLES. Anything the boot left
        // managed in this entity manager belongs to the schema this method has
        // just dropped and recreated: ids restart at 1, so the first area the
        // test persists would take an id the map already holds and Doctrine
        // would refuse it. Clearing after a truncate is the documented answer,
        // and the collision message names it.
        $this->em->clear();
    }

    /**
     * A SIGNED-IN VIEWER. The sidebar asks "is anybody looking?" before it asks
     * what they may see — a page can render outside any firewall (an error page,
     * a console-rendered template) and the authorization checker THROWS there
     * rather than answering false. So a test about what a viewer sees has to put
     * somebody behind the glass first.
     */
    protected function signIn(): void
    {
        /** @var TokenStorageInterface $tokens */
        $tokens = static::getContainer()->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken(
            new InMemoryUser('ranger', null, ['ROLE_USER']),
            'main',
            ['ROLE_USER'],
        ));
    }

    /**
     * A SIGNED-IN VIEWER WHO IS ALSO A RECORD — the installation's own account
     * entity in the token, which is what a real one puts there.
     *
     * {@see signIn()} is enough for "is anybody looking?"; a screen that keeps
     * something PER PERSON (an adopted layout) needs a principal the contracts
     * recognise, because a layout belongs to somebody and an in-memory principal
     * is nobody.
     */
    protected function signInAsPerson(): HostUser
    {
        $person = new HostUser();
        $this->em->persist($person);
        $this->em->flush();

        /** @var SignedInPerson $principal */
        $principal = static::getContainer()->get(SignedInPerson::class);
        $principal->is($person);

        return $person;
    }

    /**
     * AN AREA WITH A MODULE SWITCHED ON — "live", which is what the operational
     * figures, the attention items and the flagship all need before they have
     * anything to draw.
     */
    protected function aLiveArea(string $name = 'Northern Conservation Reserve'): AreaOfInterest
    {
        $area = $this->anArea($name);
        $this->switchOn($area, 'patrols', 'Patrols');

        return $area;
    }

    /**
     * ONE MORE MODULE ON ONE AREA — how an installation's areas come to be
     * unalike, and the only way a suite can say what a register does with a
     * row that has nothing to answer in somebody else's column.
     *
     * THE CATALOGUE ROW IS UPSERTED because the catalogue is the
     * installation's and an area is switched on INTO it: a second area
     * running the same module is a second ledger entry, not a second module.
     */
    protected function switchOn(AreaOfInterest $area, string $slug, string $name): void
    {
        if (null === $this->em->getRepository(Module::class)->findOneBy(['slug' => $slug])) {
            $this->em->persist(new Module()
                ->setSlug($slug)
                ->setName($name)
                ->setCategory(ModuleCategory::Pressure)
                ->setStatus(ModuleStatus::Live)
                ->setDataSource('GPS field tracks')
                ->setPosition(0));
            $this->em->flush();
        }

        /** @var AreaModuleService $modules */
        $modules = static::getContainer()->get('test_public.registry.area_modules');
        $modules->install($area, $slug);
    }

    private ?KernelBrowser $browser = null;

    /**
     * ONE CLIENT PER TEST. `test.client` is defined shared:false, so asking the
     * container twice hands back two browsers and the second has never made the
     * request whose response the assertion wants.
     */
    protected function browser(): KernelBrowser
    {
        if (null === $this->browser) {
            /** @var KernelBrowser $client */
            $client = static::getContainer()->get('test.client');
            // ONE KERNEL FOR THE WHOLE TEST. The browser shuts the kernel down
            // between requests by default, and a new one has a new container: the
            // token this suite put in token storage, and the schema boot() built on
            // this entity manager, would both be gone by the second request. A test
            // that signs somebody in and then walks two pages needs them to be the
            // same somebody on both.
            $client->disableReboot();
            $this->browser = $client;
        }

        return $this->browser;
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            // THE GEOMETRY TABLES DO NOT OUTLIVE THIS SUITE. One database
            // carries every package's suite, and a kernel without the PostGIS
            // bundle in it cannot introspect a `geometry` column — so a table
            // left behind here breaks a sibling's suite the moment somebody
            // runs it on its own.
            new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());

            $this->em->close();
        }
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

    protected const string A_BOUNDARY = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';
    protected const string A_WEST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-30.0,-3.6],[-29.5,-3.6],[-29.5,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]]}';
    /** The other half, so a suite can put TWO zones on one area without them overlapping. */
    protected const string AN_EAST_HALF = '{"type":"MultiPolygon","coordinates":[[[[-29.5,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-29.5,-2.8],[-29.5,-3.6]]]]}';

    protected function anArea(string $name = 'Northern Conservation Reserve', string $source = 'WDPA'): AreaOfInterest
    {
        $area = new AreaOfInterest()->setName($name)->setGeom(self::A_BOUNDARY)->setSource($source);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    protected function aZone(AreaOfInterest $area, string $name = 'West', string $geom = self::A_WEST_HALF): Zone
    {
        $zone = new Zone()->setArea($area)->setName($name)->setGeom($geom);
        $this->em->persist($zone);
        $this->em->flush();

        return $zone;
    }
}
