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
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * EVERY SETTING AN INSTALLATION DECLARES CAN BE CHANGED ON A SCREEN.
 *
 * A VALUE THAT CAN BE READ AND NEVER WRITTEN IS A CONSTANT WITH A FORM'S
 * PROMISE. The settings store is read everywhere a value is in force, and
 * every page that shows one says where it is set; if that place does not
 * exist, an Admin is shown a value nobody can change, and the build stays
 * green, because every page still renders. So the build asks the question
 * directly, of the catalogue the installation compiles: each owner that
 * declares a setting has its own screen at the address the settings design
 * gives it, `/settings/configure/{owner}`, that opens for a Super Admin, posts
 * a form, and names each of the owner's settings by its own label beside a
 * field.
 */
#[CoversNothing]
final class EverySettingHasAnEditorTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /** Without this, every test below passes over an empty catalogue and proves nothing. */
    public function testTheInstallationDeclaresSettings(): void
    {
        self::assertNotSame([], $this->catalogue()->owners(), 'The core declares settings of its own; a catalogue without them is not reading its sources.');
    }

    public function testEveryOwnerChangesItsSettingsOnItsOwnScreen(): void
    {
        $this->signedInAsSomebodyWhoMaySeeEverything();
        $missing = [];

        foreach ($this->catalogue()->owners() as $owner) {
            $path = '/settings/configure/'.$owner;
            $crawler = $this->client->request('GET', $path);
            $status = $this->client->getResponse()->getStatusCode();
            if (200 !== $status) {
                $missing[] = \sprintf('%s answered %d', $path, $status);
                continue;
            }

            $form = $crawler->filter('form[method="post"], form[method="POST"]');
            if (0 === $form->count()) {
                $missing[] = \sprintf('%s posts no form', $path);
                continue;
            }

            $definitions = $this->catalogue()->forOwner($owner);
            $fields = $form->filter('input:not([type="hidden"]):not([type="submit"]), select, textarea')->count();
            if ($fields < \count($definitions)) {
                $missing[] = \sprintf('%s has %d fields for %d settings', $path, $fields, \count($definitions));
            }

            $text = $form->text();
            foreach ($definitions as $definition) {
                if (!str_contains($text, $definition->label)) {
                    $missing[] = \sprintf('%s does not name "%s" (%s)', $path, $definition->label, $definition->key);
                }
            }
        }

        self::assertSame([], $missing, "A setting with nowhere to change it:\n".implode("\n", $missing));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $this->em->getConnection()->executeStatement('CREATE EXTENSION IF NOT EXISTS postgis');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        new SchemaTool($this->em)->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->close();
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

    private function catalogue(): SettingsCatalogue
    {
        $catalogue = static::getContainer()->get('test_public.registry.settings.catalogue');
        self::assertInstanceOf(SettingsCatalogue::class, $catalogue);

        return $catalogue;
    }

    /**
     * A SUPER ADMIN, because the question is whether the screen EXISTS. A
     * refusal would read as a missing screen and say nothing about either.
     */
    private function signedInAsSomebodyWhoMaySeeEverything(): void
    {
        $naomi = new User()
            ->setEmail('naomi.kileo@example.test')
            ->setFirstName('Naomi')->setLastName('Kileo')->setPassword('x')
            ->setTeamRole(TeamRoleEnum::SuperAdmin)->setVerified(true);
        $this->em->persist($naomi);
        $this->em->flush();

        $this->client->loginUser($naomi);
    }
}
