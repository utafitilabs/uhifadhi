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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Settings\PeopleFigure;
use Uhifadhi\Bundle\TeamBundle\Settings\PositionFigure;
use Uhifadhi\Bundle\TeamBundle\Settings\TeamSteps;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceNavigation;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamNavigation;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;
use Uhifadhi\Contracts\Settings\SettingsFigure;
use Uhifadhi\Contracts\Settings\SettingsStep;

/**
 * EVERY CONTRIBUTION THIS BUNDLE PUTS ON A PAGE ASKS FOR ITS OWN PAIR.
 *
 * The page is assembled from contributions, and the shell that assembles it
 * holds no authorization service — so a sidebar row, a tab, a configure
 * section or a settings figure the viewer may not hold is withheld by the
 * contribution itself. Asserted on the markup a browser received: absent,
 * never hidden.
 */
#[CoversClass(TeamNavigation::class)]
#[CoversClass(PerformanceNavigation::class)]
#[CoversClass(TeamSectionTabs::class)]
#[CoversClass(DepartmentSectionTabs::class)]
#[CoversClass(DepartmentSectionConfiguration::class)]
#[CoversClass(PerformanceSectionTabs::class)]
#[CoversClass(PerformanceSectionConfiguration::class)]
#[CoversClass(PeopleFigure::class)]
#[CoversClass(PositionFigure::class)]
#[CoversClass(TeamSteps::class)]
final class ContributionsAskForTheirPairTest extends WebTestCaseWithSchema
{
    /** Reading the directory opens Team and nothing the departments own. */
    public function testTheDirectoryReaderIsOfferedTheTeamRowAlone(): void
    {
        $this->reader(['directory.read']);

        self::assertSame(['/team'], $this->rows($this->client->request('GET', '/_elsewhere')));
    }

    /** Reading the departments opens Performance and Departments, and not Team. */
    public function testTheDepartmentsReaderIsOfferedPerformanceAndDepartmentsAlone(): void
    {
        $this->reader(['departments.read']);

        self::assertSame(
            ['/departments/performance', '/departments'],
            $this->rows($this->client->request('GET', '/_elsewhere')),
        );
    }

    /**
     * MANAGING THE DIRECTORY OPENS NO READING SCREEN, so it is offered no row:
     * every row asks what the screen behind it enforces, not a stronger or a
     * neighbouring pair.
     */
    public function testManagingWithoutReadingIsOfferedNoRow(): void
    {
        $this->reader(['directory.manage']);

        self::assertSame([], $this->rows($this->client->request('GET', '/_elsewhere')));
    }

    /** Positions is gated on its own pair, in the strip and in the tree. */
    public function testThePositionsTabAndItsRowNeedPositionsRead(): void
    {
        $this->reader(['directory.read']);

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['Overview', 'People', 'Assignments', 'Roles'], $this->strip($crawler));
        self::assertNotContains('/team/positions', $crawler->filter('nav.nav a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
    }

    /** And somebody who reads both is offered both. */
    public function testThePositionsTabIsOfferedWithItsPair(): void
    {
        $this->reader(['directory.read', 'positions.read']);

        self::assertSame(
            ['Overview', 'People', 'Positions', 'Assignments', 'Roles'],
            $this->strip($this->client->request('GET', '/team')),
        );
    }

    /**
     * A SECTION WITH NOTHING THE VIEWER MAY CONFIGURE OFFERS NO CONFIGURE.
     * The departments' configure screens enforce `departments.configure`, and
     * so do the performance settings; a reader of the pages gets no action.
     */
    public function testTheDepartmentsReaderIsOfferedNoConfigureAction(): void
    {
        $this->reader(['departments.read']);

        foreach (['/departments', '/departments/performance'] as $path) {
            $crawler = $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertNotContains('Configure', $crawler->filter('.pgact > *')->each(static fn (Crawler $a): string => trim($a->text())), $path);
            self::assertStringNotContainsString('/departments/configure', (string) $this->client->getResponse()->getContent(), $path);
            self::assertStringNotContainsString('/departments/performance/settings', (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    /** And the configurer is offered it on both. */
    public function testTheConfigurerIsOfferedTheConfigureAction(): void
    {
        $this->reader(['departments.read', 'departments.configure']);

        foreach (['/departments', '/departments/performance'] as $path) {
            $actions = $this->client->request('GET', $path)->filter('.pgact > *');

            self::assertSame('Configure', trim($actions->last()->text()), $path);
        }
    }

    /**
     * A CELL'S DOOR ASKS THE PAIR OF WHERE IT LEADS. The vacancies cell on the
     * departments' overview names positions and points at their register,
     * which enforces `positions.read`; a departments reader gets the names
     * and no door.
     */
    public function testTheVacanciesCellDrawsNoDoorToThePositionsRegisterWithoutItsPair(): void
    {
        $this->reader(['departments.read']);

        $this->client->request('GET', '/departments/overview');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Positions nobody holds', $body);
        self::assertStringNotContainsString('Positions in Team', $body);
        self::assertStringNotContainsString('href="/team/positions"', $body);
    }

    /** And with the pair, the door is there. */
    public function testTheVacanciesCellDrawsItsDoorWithThePair(): void
    {
        $this->reader(['departments.read', 'positions.read']);

        $this->client->request('GET', '/departments/overview');

        self::assertStringContainsString('Positions in Team', (string) $this->client->getResponse()->getContent());
    }

    /**
     * THE SETTINGS SECTION'S FIGURES AND STEPS WITHHOLD THEMSELVES — the
     * section asks nothing about the viewer, so a count of people, a count of
     * positions and a step linking to either page is each asked for its own
     * pair.
     */
    public function testTheSettingsFiguresAndStepsAnswerOnlyWhatTheViewerMayRead(): void
    {
        $this->reader(['directory.read']);
        $this->client->request('GET', '/_elsewhere');

        self::assertSame(['people'], $this->figureKeys(PeopleFigure::class, PositionFigure::class));
        self::assertSame(['post-people'], $this->stepKeys());
    }

    /** Somebody who reads neither is told neither. */
    public function testTheSettingsSourcesTellAStrangerToThemNothing(): void
    {
        $this->reader(['departments.read']);
        $this->client->request('GET', '/_elsewhere');

        self::assertSame([], $this->figureKeys(PeopleFigure::class, PositionFigure::class));
        self::assertSame([], $this->stepKeys());
    }

    /** And somebody who reads the positions alone is told about them alone. */
    public function testThePositionsReaderIsToldAboutPositionsAlone(): void
    {
        $this->reader(['positions.read']);
        $this->client->request('GET', '/_elsewhere');

        self::assertSame(['positions'], $this->figureKeys(PeopleFigure::class, PositionFigure::class));
        self::assertSame(['compose-a-position'], $this->stepKeys());
    }

    /** @param list<string> $grants */
    private function reader(array $grants): void
    {
        $reader = $this->person('Wera', 'Mwita');
        $reader->setPosition($this->position('Reader', $grants));
        $this->place($reader);
        $this->em->flush();
        $this->client->loginUser($reader);
    }

    /** @return list<string> */
    private function rows(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('nav.nav a.nav-item')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
    }

    /** @return list<string> */
    private function strip(Crawler $crawler): array
    {
        return $crawler->filter('.atabs a')->each(static fn (Crawler $a): string => trim($a->text()));
    }

    /**
     * @param class-string<PeopleFigure|PositionFigure> ...$sources
     *
     * @return list<string>
     */
    private function figureKeys(string ...$sources): array
    {
        $keys = [];
        foreach ($sources as $class) {
            $source = static::getContainer()->get('test_public.'.$class);
            self::assertInstanceOf($class, $source);
            foreach ($source->settingsFigures() as $figure) {
                self::assertInstanceOf(SettingsFigure::class, $figure);
                $keys[] = $figure->key;
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function stepKeys(): array
    {
        $steps = static::getContainer()->get('test_public.'.TeamSteps::class);
        self::assertInstanceOf(TeamSteps::class, $steps);

        return array_values(array_map(static fn (SettingsStep $step): string => $step->key, [...$steps->settingsSteps()]));
    }
}
