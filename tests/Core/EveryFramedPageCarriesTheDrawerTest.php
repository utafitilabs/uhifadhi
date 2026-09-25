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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Core\Tests\Application\Kernel;

/**
 * EVERY FRAMED PAGE CAN OPEN ITS MENU ON A PHONE.
 *
 * THE DEFECT THIS CLOSES (2026-09-25, on a 390px phone): the sidebar was
 * pushed off the screen and the top bar had no opener, so no page's menu
 * could be reached. The shell's own tests pin the markup on a fixture page;
 * this one asks the installation over HTTP, page by page, because a page
 * that replaced the top bar or the sidebar would pass the fixture and still
 * strand a phone.
 *
 * THE SWEEP is every GET route this suite can address: no parameter, or a
 * `{uuid}` it can fill — an area under /areas, the signed-in person under
 * /team. Every one that answers 200 with the frame is held to the drawer,
 * and four named pages must be among them — a person record, the area
 * overview, a register and a configure page — so a sweep that quietly
 * reached nothing cannot pass.
 */
#[CoversNothing]
final class EveryFramedPageCarriesTheDrawerTest extends WebTestCase
{
    /** The four kinds of page the owner named, by route. */
    private const array MUST_REACH = ['team_member', 'area_show', 'team_index', 'area_zones_configure'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->catchExceptions(true);

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

    public function testEveryFramedPageCarriesTheOpenerTheScrimAndTheCloseMark(): void
    {
        $area = new AreaOfInterest()->setName('Kilimani Crater');
        $this->em->persist($area);
        $this->em->flush();
        $areaUuid = (string) $area->getUuidString();

        $person = $this->everyPairHolder();
        $personUuid = (string) $person->getUuidString();

        $checked = [];
        $faults = [];

        foreach ($this->addressableRoutes() as $name => $path) {
            $url = str_starts_with($path, '/team/') ? str_replace('{uuid}', $personUuid, $path) : str_replace('{uuid}', $areaUuid, $path);

            $this->client->loginUser($person);
            $crawler = $this->client->request('GET', $url);
            $response = $this->client->getResponse();

            if (200 !== $response->getStatusCode() || !str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                continue;
            }
            if (0 === $crawler->filter('aside.side')->count()) {
                continue;
            }

            $checked[] = $name;
            foreach (self::faultsOf($crawler) as $fault) {
                $faults[] = $name.' ('.$url.'): '.$fault;
            }
        }

        sort($faults);
        self::assertSame([], $faults, "These framed pages cannot open their menu on a phone:\n".implode("\n", $faults));

        foreach (self::MUST_REACH as $route) {
            self::assertContains($route, $checked, \sprintf('The sweep never reached %s, so it proves nothing about that kind of page. Reached: %s', $route, implode(', ', $checked)));
        }
    }

    /**
     * What is missing from one page's drawer.
     *
     * @return list<string>
     */
    private static function faultsOf(Crawler $page): array
    {
        $id = ShellBundle::CONTROLLER_PREFIX.'sidebar';
        $target = 'data-'.$id.'-target';
        $faults = [];

        $shell = $page->filter('div.shell');
        if ($id !== $shell->attr('data-controller')) {
            $faults[] = 'no sidebar controller on .shell';
        }
        if (!str_contains((string) $shell->attr('data-action'), 'keydown.esc@document->'.$id.'#close')) {
            $faults[] = 'Escape does not close';
        }

        $opener = $page->filter('header.topbar button.side-open');
        if (1 !== $opener->count()) {
            $faults[] = 'no opener in the top bar';
        } elseif ($id.'#open' !== $opener->attr('data-action') || 'opener' !== $opener->attr($target)
            || 'side' !== $opener->attr('aria-controls') || 'false' !== $opener->attr('aria-expanded')
            || null === $opener->attr('aria-label')) {
            $faults[] = 'the opener is not wired (action, target, aria-controls, aria-expanded, aria-label)';
        }

        $close = $page->filter('aside.side button.side-close');
        if (1 !== $close->count()) {
            $faults[] = 'no close mark in the sidebar head';
        } elseif ($id.'#close' !== $close->attr('data-action') || 'closer' !== $close->attr($target) || null === $close->attr('aria-label')) {
            $faults[] = 'the close mark is not wired';
        }

        $scrim = $page->filter('div.shell > div.side-scrim');
        if (1 !== $scrim->count()) {
            $faults[] = 'no scrim';
        } elseif ('click->'.$id.'#close' !== $scrim->attr('data-action') || 'true' !== $scrim->attr('aria-hidden')) {
            $faults[] = 'the scrim is not wired';
        }

        $side = $page->filter('aside.side');
        if ('side' !== $side->attr('id') || 'side' !== $side->attr($target) || 'click->'.$id.'#follow' !== $side->attr('data-action')) {
            $faults[] = 'the aside is not the controller\'s side target';
        }
        if ('main' !== $page->filter('main.main')->attr($target)) {
            $faults[] = 'the main column is not the controller\'s main target';
        }

        return $faults;
    }

    /**
     * The GET routes this suite can fill.
     *
     * @return array<string, string>
     */
    private function addressableRoutes(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $methods = $route->getMethods();
            if ([] !== $methods && !\in_array('GET', $methods, true)) {
                continue;
            }

            $path = $route->getPath();
            if (1 === preg_match('/\{(?!uuid\})\w+\}/', $path)) {
                continue;
            }
            if (str_contains($path, '{uuid}') && !str_starts_with($path, '/areas/') && !str_starts_with($path, '/team/')) {
                continue;
            }

            $routes[$name] = $path;
        }

        return $routes;
    }

    /** Somebody holding every declared pair across the organization. */
    private function everyPairHolder(): User
    {
        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        self::assertInstanceOf(ConcernCatalogue::class, $catalogue);

        $placement = new Placement()->acrossTheOrganization()->acrossAllDepartments();
        $position = new Position()
            ->setName('Holds everything')
            ->setGrantValues($catalogue->pairs(), $catalogue->pairs());

        $person = new User()
            ->setEmail('everything@example.test')
            ->setFirstName('Asha')->setLastName('Holder')
            ->setPassword('x')
            ->setTeamRole(TeamRoleEnum::Staff)
            ->setVerified(true)
            ->setPosition($position)
            ->setPlacement($placement);

        $this->em->persist($position);
        $this->em->persist($placement);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }
}
