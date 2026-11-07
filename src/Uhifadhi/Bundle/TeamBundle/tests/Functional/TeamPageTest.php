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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Tests\FilesInADepartment;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\TestKernel;

/**
 * THE TEAM PAGE, RENDERED — the design's key structures asserted as markup
 * rather than as intentions.
 *
 * What is checked here is what the design argues about and a template can get
 * wrong: the attention pane collapsing to NOTHING when nobody needs a decision,
 * the three-line position cell, the tier pills as links carrying whole-roster
 * counts, the pager drawn in the state it is actually in — and the gate, which
 * is a PERMISSION and not a tier.
 */
final class TeamPageTest extends WebTestCase
{
    use FilesInADepartment;

    /**
     * NAMED IN CODE, not by KERNEL_CLASS. One repository holds several
     * packages, so one env var could only ever name one of their kernels.
     */
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
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

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole($tier)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }

    /**
     * A POSITION CARRIES NO DEPARTMENT. It used to take one and its name was
     * unique only inside it; the ruling made the name unique across the
     * organization, so the argument is gone from the signature rather than
     * ignored.
     *
     * @param list<string> $grants (concern, verb) pairs this position carries
     */
    private function position(string $name, array $grants = []): Position
    {
        $position = (new Position())->setName($name);
        $position->setGrantValues($grants, $grants);
        $this->em->persist($position);

        return $position;
    }

    /** A settled installation: everybody arrived, everybody holds something, two Super Admins. */
    private function settled(): User
    {
        $senior = $this->position('Senior Ranger', ['directory.manage', 'directory.read']);
        $ranger = $this->position('Ranger', ['directory.read']);

        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi')->setPosition($senior)->setRangerCode('R-104');
        $this->person('Zawadi', 'Naisenya')->setPosition($ranger);
        $this->em->flush();

        return $naomi;
    }

    /**
     * GRANT (CONCERN, VERB) PAIRS ON A POSITION. This suite's own fixture
     * writes the old flat values, so a test that needs the pair a route now
     * names writes it here, validated against the live catalogue exactly as
     * the positions page validates a save.
     *
     * @param list<string> $pairs
     */
    private function grant(Position $position, array $pairs): Position
    {
        $catalogue = static::getContainer()->get('test_public.'.ConcernCatalogue::class);
        \assert($catalogue instanceof ConcernCatalogue);

        return $position->setGrantValues($pairs, $catalogue->pairs());
    }

    /**
     * THE GATE IS A PAIR, NOT A TIER. A Staff member whose position carries
     * `directory.read` reads the team — that is the whole of what retiring
     * the Manager tier bought.
     */
    public function testAStaffMemberHoldingDirectoryReadReachesThePage(): void
    {
        $senior = $this->grant($this->position('Senior Ranger'), ['directory.read']);
        $grace = $this->person('Grace', 'Ndosi')->setPosition($senior);
        // A PERMISSION IS ONLY HELD SOMEWHERE. The position says what Grace
        // may do and the placement says where; the model fails closed, so
        // somebody placed nowhere reaches nothing however much they hold.
        $placement = new Placement();
        $placement->acrossTheOrganization();
        $placement->inDepartment($this->homeDepartment($this->em));
        $this->em->persist($placement);
        $grace->setPlacement($placement);
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->client->loginUser($grace);
        $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
    }

    /** And a Staff member whose position does not carry it is refused. */
    public function testAStaffMemberWithoutItIsRefused(): void
    {
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $this->client->loginUser($frank);
        $this->client->request('GET', '/team');

        self::assertResponseStatusCodeSame(403);
    }

    /** The two tiers above the matrix pass the same check, by tier. */
    public function testASuperAdminReachesThePage(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame('Team', $crawler->filter('h1.pg')->text());
    }

    /**
     * THE ATTENTION PANE COLLAPSES TO NOTHING. Not an empty plate saying
     * everything is fine — absent. A pane that stayed on screen to report that
     * nothing is wrong would spend the top of the page saying so, every visit.
     */
    public function testTheAttentionPaneIsAbsentWhenNobodyNeedsADecision(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('.tm-att'));
        self::assertStringNotContainsString('Needs a decision', $crawler->html());
    }

    /**
     * THE POSITION CELL: the position's name, what it grants, and the
     * administrator mark.
     *
     * IT USED TO BE QUALIFIED BY A DEPARTMENT, because a position's name was
     * unique only inside one and "Analyst" on its own was ambiguous. A position
     * belongs to no department now and its name is unique across the
     * organization, so the name IS the whole identification and there is no
     * second line to qualify it with.
     */
    public function testThePositionCellNamesThePositionAndSaysWhatItGrants(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $cell = $crawler->filter('.tm-pos.qual')->first();
        self::assertSame('Senior Ranger', $cell->filter('.n')->text());
        self::assertCount(0, $cell->filter('.q'), 'a name unique across the organization needs no qualifier');

        self::assertStringContainsString('2 granted', $crawler->html());
        // Scoped to the table: the tier explainer below it also spells
        // team.manage, and rightly — that widget's whole job is to say what the
        // permission means.
        self::assertCount(
            1,
            $crawler->filter('.c[data-tm] .tm-mgr'),
            'The one position carrying team.manage is marked, in the roster.',
        );
    }

    /**
     * A SUPER ADMIN'S POSITION CELL IS NOT A COUNT. The voter grants by tier
     * before it ever looks at a position, so a number there would name the
     * wrong cause.
     */
    public function testATierAboveTheMatrixReadsEverythingByTier(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertStringContainsString('everything, by tier', $crawler->html());
    }

    /**
     * THE TIER IS A CHIP OF THE HOUSE BAR, its options links carrying
     * whole-roster counts. Links because the page must work with no
     * JavaScript; whole-roster because an option reading "Admin 0" while you
     * search for a name is an option lying about the installation.
     */
    public function testTheTierChipOffersLinksWithWholeRosterCounts(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team?q=grace');

        $chip = $crawler->filter('.c[data-tm] .lfilt details.i-dd[data-axis="tier"]');
        self::assertCount(1, $chip);
        self::assertSame('all · 4', self::squash($chip->filter('summary.mchip.i-ddt .i-ddval')->text()));
        $options = $chip->filter('a.i-ddopt');
        self::assertSame(['All', 'Super Admin', 'Admin', 'Staff'], $options->filter('.i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('tier=super_admin', $options->eq(1)->attr('href') ?? '');
        self::assertStringContainsString('q=grace', $options->eq(1)->attr('href') ?? '', 'the search rides with the choice');
        // Two Super Admins exist; the search for "grace" must not change that.
        self::assertSame(['4', '2', '0', '2'], $options->filter('.i-ddopt-n')->each(static fn (Crawler $c): string => $c->text()));

        $chosen = $this->client->request('GET', '/team?tier=staff');
        self::assertSame('staff · 2', self::squash($chosen->filter('details.i-dd[data-axis="tier"] summary.mchip.on .i-ddval')->text()));
        self::assertCount(2, $chosen->filter('table.tbl tbody tr'));
    }

    /**
     * THE BAR IS THE HOUSE BAR — chips, the export door, the search a GET
     * form at the right end — and no sibling of it: no tool row, no pills,
     * no second count line beside the foot's.
     */
    public function testTheBarIsTheHouseBarAndTheSearchNarrowsTheTable(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team?q=ndosi&tier=staff');

        self::assertCount(0, $crawler->filter('.tm-tools, .tm-search, .fchip, .tm-shown'));
        $search = $crawler->filter('.c[data-tm] .lfilt form.lsearch');
        self::assertSame('get', strtolower($search->attr('method') ?? ''));
        self::assertSame('/team', $search->attr('action'));
        self::assertSame('ndosi', $search->filter('input.fld[type="search"][name="q"]')->attr('value'));
        self::assertSame(['tier' => 'staff'], self::hidden($search), 'the chosen chips ride with the search; the page does not');
        self::assertSame(
            ['tier', 'position', 'department', 'station', 'rank', 'account'],
            $crawler->filter('.lfilt details.i-dd')->each(static fn (Crawler $d): string => (string) ($d->attr('data-axis') ?? $d->attr('data-facet'))),
        );
        self::assertCount(1, $crawler->filter('table.tbl tbody tr'));
        self::assertSame(['1 person', '20 per page', 'page 1 of 1'], self::foot($crawler));
    }

    /**
     * THE REGISTER FOOT IS THE SHELL'S, drawn in the state it is in: four
     * people is one page at the default twenty, so there are no arrows, and
     * the chooser offers 20 · 50 · 100 · All as links that keep the filter.
     */
    public function testASinglePageDrawsTheHouseFootWithNoArrows(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team?tier=staff');

        $foot = $crawler->filter('.c[data-tm] > .rdf-foot');
        self::assertCount(1, $foot);
        self::assertSame(['2 people', '20 per page', 'page 1 of 1'], self::foot($crawler));
        self::assertCount(0, $foot->filter('.rdf-page a'));
        $sizes = $foot->filter('details.i-dd.rdf-size a.i-ddopt');
        self::assertSame(['20', '50', '100', 'All'], $sizes->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/team?tier=staff&per=50', $sizes->eq(1)->attr('href'));
        self::assertSame('true', $sizes->eq(0)->attr('aria-current'));
    }

    /** The pager cuts at the chosen size, and an arrow appears only where there is a page. */
    public function testThePagerCutsAtTheChosenSize(): void
    {
        $naomi = $this->settled();
        for ($i = 1; $i <= 30; ++$i) {
            $this->person('Person', \sprintf('%02d', $i));
        }
        $this->em->flush();

        $this->client->loginUser($naomi);
        $first = $this->client->request('GET', '/team');
        self::assertCount(20, $first->filter('table.tbl tbody tr'));
        self::assertSame(['34 people', '20 per page', 'page 1 of 2'], self::foot($first));
        self::assertCount(0, $first->filter('.rdf-page a[aria-label="Previous page"]'));
        self::assertSame('/team?page=2', $first->filter('.rdf-page a[aria-label="Next page"]')->attr('href'));

        $second = $this->client->request('GET', '/team?page=2');
        self::assertCount(14, $second->filter('table.tbl tbody tr'));
        self::assertCount(0, $second->filter('.rdf-page a[aria-label="Next page"]'));

        $fifty = $this->client->request('GET', '/team?per=50');
        self::assertCount(34, $fifty->filter('table.tbl tbody tr'));
        self::assertSame(['34 people', '50 per page', 'page 1 of 1'], self::foot($fifty));

        $all = $this->client->request('GET', '/team?per=all&page=2');
        self::assertCount(34, $all->filter('table.tbl tbody tr'));
        self::assertSame(['34 people', 'all on one page', 'page 1 of 1'], self::foot($all));
    }

    /** THE ROWS ARE THE HOUSE TABLE'S: one row a person, no band, fold or group row. */
    public function testTheRowsAreTheHouseTable(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(1, $crawler->filter('.c[data-tm] table.tbl'));
        self::assertCount(4, $crawler->filter('table.tbl > tbody > tr'));
        self::assertCount(0, $crawler->filter('table.tbl tr.sxgrp, table.tbl tr.foldrow, table.tbl tr.tm-bandrow'));
        self::assertCount(4, $crawler->filter('table.tbl tbody tr > td.door:last-child a.open-btn'), 'the row\'s door is its last cell');
    }

    /**
     * A DEACTIVATED ACCOUNT STAYS IN THE LIST, marked, with the action that
     * undoes it. "This ranger left in March" and "this ranger never existed"
     * are different facts and the roster is where the difference is read.
     */
    public function testADeactivatedAccountIsStillListedAndOffersToComeBack(): void
    {
        $naomi = $this->settled();
        $this->person('Hawa', 'Rajabu')->setVerified(false)->deactivate();
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertStringContainsString('Hawa Rajabu', $crawler->html());
        self::assertCount(1, $crawler->filter('tr.tm-off'));
        // Two pills, not one merged state: the axes never merge.
        self::assertStringContainsString('Deactivated', $crawler->filter('tr.tm-off')->text());
        self::assertStringContainsString('Never signed in', $crawler->filter('tr.tm-off')->text());
        // And the honest invitation line for an account nobody invited.
        self::assertStringContainsString('created directly', $crawler->filter('tr.tm-off')->text());
        self::assertStringContainsString('Reactivate', $crawler->filter('tr.tm-off')->text());
    }

    /** THE PEOPLE REGISTER IS ONE TABLE (owner 2026-09-22): no library, no presets, no widget grid. */
    public function testThePeopleRegisterIsOneTableWithNoLibrary(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('.w-grid'));
        self::assertCount(1, $crawler->filter('.c[data-tm] table.tbl'));
        self::assertStringContainsString('People', $crawler->filter('.c[data-tm] > .tab')->text());
        self::assertCount(0, $crawler->filter('.pgact a[href="/team/widgets"]'));
    }

    /**
     * A FRESH INSTALLATION GETS NO STRIP OF ZEROS. One account is the whole
     * roster, and the page says so in a sentence with something to do next.
     */
    public function testAFreshInstallationIsASentenceRatherThanAStripOfZeros(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('.dp-kstrip'));
        self::assertStringContainsString('One account, and it is yours', $crawler->html());
        // And no bar: a table with one row and a filter bar filtering
        // nothing is a table pretending to be a list.
        self::assertCount(0, $crawler->filter('.c[data-tm] .lfilt'));
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The register foot as it reads: the count, the chosen size, the page.
     *
     * @return array{string, string, string}
     */
    private static function foot(Crawler $page): array
    {
        $foot = $page->filter('.rdf-foot');
        self::assertCount(1, $foot);

        return [
            self::squash($foot->filter(':scope > span > span')->first()->text()),
            self::squash(str_replace('▾', '', $foot->filter('details.rdf-size > summary')->text())),
            self::squash(str_replace(['‹', '›'], '', $foot->filter('.rdf-page')->text())),
        ];
    }

    /** @return array<string, string> the hidden fields a form carries */
    private static function hidden(Crawler $form): array
    {
        $fields = [];
        foreach ($form->filter('input[type="hidden"]') as $input) {
            \assert($input instanceof \DOMElement);
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }
}
