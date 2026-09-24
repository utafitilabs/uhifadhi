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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * EVERY WIDGET LIBRARY IN THE INSTALLATION OPENS.
 *
 * A LIBRARY IS THE ONE PAGE A BUNDLE'S OWN SUITE IS WORST AT PROVING. Each of
 * them renders whole PREVIEWS of another surface's layouts, over rows every
 * other bundle contributes to, which is exactly the composition a single
 * bundle's kernel cannot assemble: the areas library 500'd in a real
 * installation while the area bundle's own web suite rendered it green, on a
 * row shape only a populated installation produces.
 *
 * SO IT IS ONE SMOKE TEST ACROSS ALL OF THEM, in the application that has
 * every core bundle in one kernel, against ground rich enough to differ from
 * itself: two live areas whose contributed figures are NOT the same shape,
 * because a table that draws a column per figure from the first live area and
 * then reads that column off every other row is the defect this exists to
 * catch. A redirect is a pass where a surface's library has been retired to
 * one — the redirect is the shipped answer, and what must not happen is a 500.
 */
#[CoversNothing]
final class EveryWidgetLibraryRendersTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /** @return array<string, array{string, list<int>}> */
    public static function libraries(): array
    {
        return [
            'the org dashboard' => ['/widgets', [200]],
            'the areas landing' => ['/areas/widgets', [200]],
            'the departments register' => ['/departments/widgets', [200]],
        ];
    }

    /**
     * @param list<int> $accepted
     */
    #[DataProvider('libraries')]
    public function testItAnswersWithoutFailing(string $path, array $accepted): void
    {
        $this->signedInAsSomebodyWhoMaySeeEverything();

        $this->client->catchExceptions(false);
        $this->client->request('GET', $path);
        $status = $this->client->getResponse()->getStatusCode();

        self::assertContains(
            $status,
            $accepted,
            \sprintf('%s answered %d; a library that cannot be opened is a library nobody can adopt from.', $path, $status),
        );
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

        $this->ground();
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

    /**
     * THE GROUND A DEVELOPER ACTUALLY OPENS THESE PAGES OVER — the shipped
     * demo, through the same providers devkit runs, plus one area that has no
     * boundary yet.
     *
     * A LIBRARY IS ONLY EVER WRONG OVER REAL ROWS. Two hand-made areas render
     * every one of these pages green; the installation's own demo — several
     * areas, zones, posts, a staffed roster and the figures modules contribute
     * to each — is the shape that broke one of them, so it is the shape the
     * smoke test uses.
     */
    private function ground(): void
    {
        foreach ([
            'test_public.team.devkit.content',
            'test_public.area.devkit.areas',
            'test_public.area.devkit.zones',
            'test_public.area.devkit.stations',
        ] as $id) {
            $provider = static::getContainer()->get($id);
            self::assertInstanceOf(ContentProviderInterface::class, $provider);
            $provider->load();
        }

        // AND ONE AREA THAT IS ONLY GAZETTED. Every register these libraries
        // preview draws a live row and a not-yet-live one differently, and a
        // ground where every area is alike proves only half of each of them.
        $this->em->persist(new AreaOfInterest()->setName('Sinde Flats'));
        $this->em->flush();
    }

    /**
     * A SUPER ADMIN, because the question here is whether the page RENDERS.
     * Every one of these addresses is gated, and a suite that tripped over a
     * gate would report a 403 as though the template were fine.
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
