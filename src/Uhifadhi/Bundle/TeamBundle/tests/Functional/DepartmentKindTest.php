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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentGoal;
use Uhifadhi\Bundle\TeamBundle\Entity\DepartmentKind;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;

/**
 * THE ONE LIST THE DEPARTMENTS SECTION OWNS — the department kind.
 *
 * A KIND IS A LENS AND NOT A PERMISSION. It groups departments for reading,
 * grants nothing and confines nothing, which is why it is a row an
 * organization adds rather than an enum a release adds. The tests below are
 * about the word: it is trimmed, it is not empty, and no two kinds share one.
 *
 * THE SCOPES ARE NOT A LIST and the page says so rather than hiding them —
 * somebody who cannot find the button has to be told there is not one.
 */
final class DepartmentKindTest extends WebTestCaseWithSchema
{
    public function testAKindIsAddedFromTheListsPage(): void
    {
        $this->lists();

        $this->post('/departments/configure/lists/kinds', ['name' => ' Operational ', 'meaning' => ' runs the ground ']);

        $kind = $this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Operational']);
        self::assertInstanceOf(DepartmentKind::class, $kind);
        self::assertSame('runs the ground', $kind->getMeaning(), 'The name and the meaning are trimmed.');
    }

    /**
     * AN EMPTY MEANING IS NULL, NOT "". "Nobody has said what this means" is a
     * real state, and two spellings of one absence is two readings of it.
     */
    public function testAKindWithNoMeaningStoresNullAndNotAnEmptyString(): void
    {
        $this->lists();

        $this->post('/departments/configure/lists/kinds', ['name' => 'Support', 'meaning' => '   ']);

        self::assertNull($this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Support'])?->getMeaning());
    }

    /** Two kinds by one name is one kind entered twice, and it is refused. */
    public function testASecondKindCannotTakeAnExistingName(): void
    {
        $this->lists();
        $this->post('/departments/configure/lists/kinds', ['name' => 'Operational']);
        $this->post('/departments/configure/lists/kinds', ['name' => 'Operational']);

        self::assertCount(1, $this->em->getRepository(DepartmentKind::class)->findBy(['name' => 'Operational']));
    }

    /** A kind needs a name, and the page says so rather than storing a blank. */
    public function testAKindNeedsAName(): void
    {
        $this->lists();

        $this->post('/departments/configure/lists/kinds', ['name' => '  ']);

        self::assertCount(0, $this->em->getRepository(DepartmentKind::class)->findAll());
    }

    public function testAKindIsRenamed(): void
    {
        $this->lists();
        $this->post('/departments/configure/lists/kinds', ['name' => 'Operational', 'meaning' => 'runs the ground']);

        $kind = $this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Operational']);
        self::assertInstanceOf(DepartmentKind::class, $kind);

        $this->post('/departments/configure/lists/kinds/'.$kind->getUuidString().'/rename', ['name' => 'Field', 'meaning' => 'on the ground']);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Field']));
    }

    /**
     * THE SCOPES ARE THE MODEL, and the page states them read-only. A third
     * scope would be a third rule in every query a module writes.
     */
    public function testTheScopesAreShownAndAreNotEditable(): void
    {
        $crawler = $this->lists();

        $card = $crawler->filter('.c')->reduce(static fn (Crawler $c): bool => str_contains($c->filter('.tab')->text(), 'Scopes'))->first();

        self::assertCount(2, $card->filter('tbody tr'));
        self::assertCount(0, $card->filter('form'));
    }

    /**
     * THE KINDS AND THE GOAL KINDS ARE REGISTERS, so each wears the house bar
     * and ends in the register foot, paged in the browser: the kinds chip by
     * use, the goal kinds by unit, each with a search; the rows carry their
     * value on the chip's axis and the words the search matches.
     */
    public function testTheKindsAndTheGoalKindsWearTheHouseBarAndFoot(): void
    {
        $this->lists();
        $this->post('/departments/configure/lists/kinds', ['name' => 'Operational']);
        $this->post('/departments/configure/lists/kinds', ['name' => 'Support']);
        $kind = $this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Operational']);
        self::assertInstanceOf(DepartmentKind::class, $kind);
        $field = $this->department('Field Unit')->setKind($kind);
        $this->goal($field, 'patrols.covered', '%');
        $this->goal($field, 'patrols.covered', '%');
        $this->goal($field, 'incidents.count', '');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments/configure/lists');
        $c = 'uhifadhi--team-bundle--table-filter';

        $kinds = $this->card($crawler, 'Department kinds');
        self::assertSame($c, $kinds->attr('data-controller'));
        self::assertSame('kinds', $kinds->attr('data-'.$c.'-noun-value'));
        $use = $kinds->filter('.lfilt details.i-dd[data-axis="use"]');
        self::assertSame('all · 2', self::squash($use->filter('summary.mchip.i-ddt .i-ddval')->text()));
        self::assertSame(['All 2', 'In use 1', 'Unused 1'], $use->filter('button.i-ddopt')->each(static fn (Crawler $o): string => $o->filter('.i-ddopt-l')->text().' '.$o->filter('.i-ddopt-n')->text()));
        self::assertSame(['', 'used', 'unused'], $use->filter('button.i-ddopt')->each(static fn (Crawler $o): string => (string) $o->attr('data-opt')));
        self::assertCount(1, $kinds->filter('.lfilt .lsearch input.fld[data-'.$c.'-target="search"]'));
        self::assertSame(['used', 'unused'], $kinds->filter('tbody tr[data-'.$c.'-target="row"]')->each(static fn (Crawler $r): string => (string) $r->attr('data-use')));
        self::assertStringContainsString('operational', (string) $kinds->filter('tbody tr')->first()->attr('data-find'));
        self::assertSame('2 kinds', self::squash($kinds->filter('.rdf-foot [data-'.$c.'-target="count"]')->text()));
        self::assertSame(['20', '50', '100', 'all'], $kinds->filter('.rdf-foot details.rdf-size button.i-ddopt')->each(static fn (Crawler $o): string => (string) $o->attr('data-per')));
        self::assertCount(0, $kinds->filter('.sxfoot'));

        $goals = $this->card($crawler, 'Goal kinds');
        $unit = $goals->filter('.lfilt details.i-dd[data-axis="unit"]');
        self::assertSame(['Any unit 2', '% 1', 'no unit 1'], $unit->filter('button.i-ddopt')->each(static fn (Crawler $o): string => $o->filter('.i-ddopt-l')->text().' '.$o->filter('.i-ddopt-n')->text()));
        self::assertSame(['none', '%'], $goals->filter('tbody tr[data-'.$c.'-target="row"]')->each(static fn (Crawler $r): string => (string) $r->attr('data-unit')));
        self::assertSame('2 goal kinds', self::squash($goals->filter('.rdf-foot [data-'.$c.'-target="count"]')->text()));
        self::assertCount(0, $goals->filter('.sxfoot'));
        self::assertCount(0, $crawler->filter('.sxfoot'));
    }

    /** And the create card is the house one, always open at the top of the page. */
    public function testTheCreateCardIsTheHouseCardAndIsAlwaysOpen(): void
    {
        $crawler = $this->lists();

        self::assertCount(1, $crawler->filter('.dcadd .crcard'));
        self::assertSame('Add a department kind', $crawler->filter('.dcaddhd b')->text());
    }

    /**
     * THE KINDS ARE THE WHOLE ORGANIZATION'S VOCABULARY. Somebody who
     * configures departments at one area reads the list and does not change
     * it: they are offered no form, and a form sent anyway is refused.
     */
    public function testSomebodyConfiguringDepartmentsAtOneAreaDoesNotChangeTheKinds(): void
    {
        $kind = (new DepartmentKind())->setName('Operational');
        $this->em->persist($kind);
        $warden = $this->person('Baraka', 'Laizer');
        $warden->setPosition($this->position('Area Warden', ['departments.read', 'departments.configure']));
        $this->place($warden, [$this->area('Kilimani')]);
        $this->em->flush();
        $this->client->loginUser($warden);

        $crawler = $this->client->request('GET', '/departments/configure/lists');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/departments/configure/lists/kinds"]'), 'no create card');
        self::assertCount(0, $crawler->filter('form[action$="/rename"]'), 'no rename form');
        self::assertStringContainsString('Operational', $crawler->filter('main')->text(), 'the kinds are still listed');

        $this->client->request('POST', '/departments/configure/lists/kinds', ['name' => 'Support']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/departments/configure/lists/kinds/'.$kind->getUuidString().'/rename', ['name' => 'Field']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSomebodyConfiguringDepartmentsAcrossTheOrganizationAddsAKind(): void
    {
        $planner = $this->person('Rehema', 'Kimaro');
        $planner->setPosition($this->position('Planner', ['departments.read', 'departments.configure']));
        $this->place($planner);
        $this->em->flush();
        $this->client->loginUser($planner);

        $this->post('/departments/configure/lists/kinds', ['name' => 'Support']);

        self::assertNotNull($this->em->getRepository(DepartmentKind::class)->findOneBy(['name' => 'Support']));
    }

    private function lists(): Crawler
    {
        $admin = $this->person('Naomi', 'Kileo', TeamRoleEnum::Admin);
        $admin->setPosition($this->administratorPosition('Warden'));
        $this->em->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/departments/configure/lists');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Post with a REAL token, pulled off the page the form is on — the same
     * one a person's browser would send, so the guard is exercised rather
     * than side-stepped.
     *
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): void
    {
        $token = (string) $this->client->request('GET', '/departments/configure/lists')
            ->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $path, $fields + ['_token' => $token]);
        self::assertResponseRedirects('/departments/configure/lists');
        $this->em->clear();
    }

    /**
     * WHAT ADMINISTERING THE TEAM IS, WRITTEN AS PAIRS. `team.manage` was one
     * flat value; it is eight (concern, verb) pairs now, and these are the
     * eight the upgrade backfills it into, so a fixture that used to say
     * "this person administers the team" still says exactly that.
     */
    private function administratorPosition(string $name): Position
    {
        return $this->position($name, [
            'directory.read',
            'directory.manage',
            'personal-details.read',
            'personal-details.manage',
            'positions.read',
            'positions.configure',
            'departments.read',
            'departments.configure',
        ]);
    }

    private function card(Crawler $page, string $tab): Crawler
    {
        $card = $page->filter('.c')->reduce(static fn (Crawler $c): bool => $c->filter('.tab')->count() > 0 && str_starts_with($c->filter('.tab')->text(), $tab));
        self::assertCount(1, $card, $tab);

        return $card;
    }

    private function goal(Department $department, string $ref, string $unit): void
    {
        $goal = (new DepartmentGoal())->setDepartment($department)->setStatement('a goal')->setKpiRef($ref)->setUnit($unit)
            ->setTarget(1.0)->setOpensAt(new \DateTimeImmutable('2026-01-01'))->setClosesAt(new \DateTimeImmutable('2026-12-31'));
        $this->em->persist($goal);
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
