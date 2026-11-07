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
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionCheckService;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionFindingsReader;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission\AnOrganization;

/**
 * TEAM › PERMISSIONS › CHECK — can this person do this, here, and why?
 * Asked live of the voters for one person, a Super Admin's by tier. The
 * address is the check: `?person=<id>&q=<pair or power>&target=<place or
 * target kind>` runs it on arrival. Under the walk: the person, the
 * question, what the voter reads of them at the place, every pair of the
 * catalogue answered there, the checks the last run's findings make worth
 * running, and this session's recent checks.
 */
final class PermissionsCheckTest extends WebTestCaseWithSchema
{
    private const string PATH = '/team/permissions/check';

    private const string RANGERS = 'Patrol, security and inspection';

    private AnOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = new AnOrganization($this->em);
    }

    public function testOnlyASuperAdminOpensIt(): void
    {
        $this->client->loginUser($this->org->personNamed('Naomi Kileo'));
        $this->client->request('GET', self::PATH);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->org->personNamed('Mariam Temba'));
        $this->client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(403, 'Staff, even one whose position stores positions.configure');

        $this->client->loginUser($this->org->personNamed('Desta Haile'));
        $this->client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(403, 'an Admin, by tier');
    }

    public function testItIsHeadedAsALookup(): void
    {
        $page = $this->page();

        self::assertSame('Permissions — Check', $page->filter('.pghead h1.pg')->text());
        self::assertSame('Can this person do this, here — and why? Asked live, of the real rules, for one person.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('uhifadhi / team / permissions / check', self::squash($page->filter('.crumb')->text()));
        self::assertSame('/team/permissions', $page->filter('.crumb a')->last()->attr('href'));
        self::assertSame(['Positions', 'Analysis', 'Check'], $page->filter('.atabs a')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Check'], $page->filter('.atabs a.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(self::PATH, $page->filter('.atabs a.on')->attr('href'));
        self::assertSame('Super Admin only', $page->filter('.pghead .pgact .chip.acc')->text());
        self::assertCount(0, $page->filter('.pghead .pp-fresh'), 'Check asks live; it has no run in its head');
    }

    public function testTheIndexIsOneRowPerCard(): void
    {
        $this->runNow();
        $flagged = \count(array_filter($this->findings()->worthRunning(), static fn (array $r): bool => $r['flagged']));
        $rows = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Kilimani Game Reserve'))->filter('.onpage-nav .onpage-row');

        self::assertSame(['Check', 'Everything here', 'Worth running', 'Recent checks'], $rows->each(static fn (Crawler $c): string => $c->filter('.onpage-l')->text()), 'the facts are part of the Check card');
        self::assertSame(['#check', '#everything', '#worth', '#recent'], $rows->each(static fn (Crawler $c): ?string => $c->attr('href')));
        self::assertCount(4, $rows->filter('.onpage-dot'));
        $allowed = \count($this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Kilimani Game Reserve'))->filter('#everything tbody tr.ok'));
        self::assertSame(['', (string) $allowed, (string) $flagged, ''], $rows->each(static fn (Crawler $c): string => $c->filter('.onpage-n')->count() > 0 ? $c->filter('.onpage-n')->text() : ''), 'Everything here counts the allowed, Worth running the flagged; Recent checks is counted in the browser');
    }

    public function testTheFormIsTheHouseBarOfThreeChipsAndTheButton(): void
    {
        $card = $this->page()->filter('.onpage-main #check');

        self::assertSame('Check live', $card->filter('.tab')->getNode(0)?->firstChild?->textContent);
        self::assertSame('· asked of the real rules, now', self::squash($card->filter('.tab .src')->text()));
        $form = $card->filter('form.lfilt[method="get"][action="'.self::PATH.'"]');
        self::assertCount(1, $form);
        self::assertCount(0, $card->filter('.pp-k, .pp-f, .dd, .pp-go'), 'no labels above, no shell dropdown: the house bar');
        self::assertSame(['input', 'input', 'input', 'details', 'details', 'details', 'button'], $form->children()->each(static fn (Crawler $c): string => $c->nodeName()), 'the chips flush in the bar, the button last');
        self::assertSame(['person', 'q', 'target'], $form->filter('input[type="hidden"]')->each(static fn (Crawler $c): ?string => $c->attr('name')));
        self::assertSame('placed', $form->filter('input[name="target"]')->attr('value'));
        $go = $form->children()->last();
        self::assertSame('cta pp-gosm', $go->attr('class'), 'the house primary button, at the right end');
        self::assertSame('submit', $go->attr('type'));
        self::assertNull($go->attr('disabled'), 'Check opens the chip still to choose');
        self::assertSame('Check', trim($go->text()));

        $chips = $form->filter('details.i-dd');
        self::assertSame(['person', 'q', 'target'], $chips->each(static fn (Crawler $c): ?string => $c->attr('data-pc-axis')));
        self::assertSame(['choose a person', 'choose a question', 'where they are placed'], $chips->each(static fn (Crawler $c): string => $c->filter('summary.mchip.i-ddt .i-ddval')->text()));

        $person = $chips->eq(0)->filter('.i-ddmenu.pp-ddscroll.pp-ddwide');
        self::assertSame('Find a person or position', $person->children()->first()->filter('.lsearch.pp-ddfind input.fld[type="search"]')->attr('placeholder'), 'the find box pinned at the top');
        $people = $this->service(PermissionCheckService::class)->people();
        self::assertSame(array_map(static fn (array $g): string => $g['label'].' · '.\count($g['people']), $people), $person->filter('.pp-ddlist .pp-ddg .i-ddhead')->each(static fn (Crawler $c): string => $c->text()), 'by tier, counted');
        self::assertSame(['Super Admin', 'Admin', 'Staff'], array_column($people, 'label'));
        $thabo = $person->filter('button.i-ddopt[data-pc-opt="'.$this->id('Thabo Ndlovu').'"]');
        self::assertSame(['Thabo Ndlovu', self::RANGERS], [$thabo->filter('.i-ddopt-l')->text(), $thabo->filter('.i-ddopt-n')->text()], 'the position at the right');
        self::assertSame(mb_strtolower('Thabo Ndlovu '.self::RANGERS), $thabo->attr('data-pc-find'), 'found by name or position');
        self::assertSame('no position', $person->filter('button.i-ddopt[data-pc-opt="'.$this->id('Frank Massawe').'"] .i-ddopt-n')->text());
        self::assertCount(0, $person->filter('button.i-ddopt[data-pc-opt="'.$this->id('Kito Lema').'"]'), 'a deactivated account is nobody to ask about');
        self::assertSame('Nobody by that name or position.', $person->filter('p.au-why.pp-ddnone')->text());
        self::assertNotNull($person->filter('p.pp-ddnone')->attr('hidden'));

        $q = $chips->eq(1)->filter('.i-ddmenu.pp-ddscroll.pp-ddwide');
        self::assertSame('Find a question', $q->children()->first()->filter('.lsearch.pp-ddfind input.fld')->attr('placeholder'));
        $heads = $q->filter('.pp-ddg .i-ddhead')->each(static fn (Crawler $c): string => $c->text());
        $powers = array_values(array_intersect($heads, ['Gaining power', 'Giving power', 'Taking over an account', 'Somebody else’s records']));
        self::assertNotEmpty($powers);
        self::assertSame($powers, \array_slice($heads, -\count($powers)), 'the powers last');
        self::assertSame($powers, array_values(array_intersect(['Gaining power', 'Giving power', 'Taking over an account', 'Somebody else’s records'], $powers)), 'in the design\'s order');
        self::assertContains('Surveys', $heads, 'a permission under its package head');
        self::assertSame('Surveys · record', $q->filter('button.i-ddopt[data-pc-opt="surveys.record"] .i-ddopt-l')->text());
        self::assertSame('No question by that name.', $q->filter('p.pp-ddnone')->text());

        $target = $chips->eq(2)->filter('.i-ddmenu.pp-ddscroll');
        self::assertCount(0, $target->filter('.pp-ddfind'), 'a short menu needs no find box');
        self::assertSame(['where they are placed', 'the whole organization', 'Kilimani Game Reserve', 'Mlima Mkuu', 'Sinde Flats', 'Ziwa Kavu'], $target->filter('.pp-ddlist button.i-ddopt .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['placed', 'organization'], \array_slice($target->filter('button.i-ddopt')->each(static fn (Crawler $c): ?string => $c->attr('data-pc-opt')), 0, 2));
        self::assertSame(['placed'], $target->filter('button.i-ddopt.on')->each(static fn (Crawler $c): ?string => $c->attr('data-pc-opt')));
        self::assertGreaterThan(0, $card->filter('template[data-pc-targets]')->count(), 'the target menu a power swaps in');

        self::assertCount(0, $card->filter('.pp-ans, .pp-facts'), 'no answer and no facts before a check');
        self::assertCount(0, $this->page()->filter('#person, #question, #here'));
        self::assertNotNull($this->page()->filter('#everything')->attr('hidden'), 'Everything here waits for a check');
    }

    public function testArrivalRunsTheCheckAndTheWalkIsTheVotersOwn(): void
    {
        $this->runNow();
        $card = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Mlima Mkuu'))->filter('#check');

        self::assertSame([(string) $this->id('Thabo Ndlovu'), 'surveys.record', $this->areaKey('Mlima Mkuu')], $card->filter('form.lfilt input[type="hidden"]')->each(static fn (Crawler $c): ?string => $c->attr('value')));
        self::assertSame(['thabo ndlovu', 'surveys · record', 'mlima mkuu'], $card->filter('form.lfilt details.i-dd .i-ddval')->each(static fn (Crawler $c): string => $c->text()), 'the chips name the choice');
        self::assertSame([(string) $this->id('Thabo Ndlovu'), 'surveys.record', $this->areaKey('Mlima Mkuu')], $card->filter('form.lfilt button.i-ddopt.on')->each(static fn (Crawler $c): ?string => $c->attr('data-pc-opt')));

        $answer = $card->filter('.pp-ans');
        self::assertSame('refused', $answer->filter('.pp-ahead .au-v.pp-v.no')->text());
        self::assertSame('Can Thabo Ndlovu record surveys in Mlima Mkuu?', self::squash($answer->filter('.pp-qline')->text()));
        self::assertSame('Thabo Ndlovu', $answer->filter('.pp-qline b')->text());

        $walk = $answer->filter('ol.pp-walk > li');
        self::assertSame(['Tier', 'Position', 'Exception', 'Placement', 'Place', ''], $walk->each(static fn (Crawler $c): string => $c->filter('.pp-wq')->count() > 0 ? $c->filter('.pp-wq')->text() : ''));
        self::assertSame(['skip', 'ok', 'skip', 'ok', 'no', 'pp-wend'], $walk->each(static fn (Crawler $c): string => (string) $c->attr('class')));
        self::assertCount(5, $walk->filter('svg.pp-mk'));
        self::assertSame('Mlima Mkuu — their placement does not cover it (placed at Kilimani Game Reserve only)', $walk->eq(4)->filter('.pp-wt')->text());
        self::assertSame('open the placement →', $walk->eq(4)->filter('a.more')->text());
        self::assertSame('/team/'.$this->org->personNamed('Thabo Ndlovu')->getUuidString(), $walk->eq(4)->filter('a.more')->attr('href'));
        self::assertSame('open the position →', $walk->eq(1)->filter('a.more')->text());
        self::assertSame('/team/positions/'.$this->org->positionNamed(self::RANGERS)->getUuidString(), $walk->eq(1)->filter('a.more')->attr('href'), 'the position record');
        self::assertSame('nothing further was asked', $walk->eq(5)->text());

        self::assertStringStartsWith('In Mlima Mkuu, nobody below the tiers can; by tier: Super Admin and Admin. As of ', self::squash($answer->filter('p.pp-cov')->text()));
        self::assertCount(1, $answer->filter('p.pp-cov time'));

        $kilimani = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Kilimani Game Reserve'))->filter('#check .pp-ans');
        self::assertSame('allowed', $kilimani->filter('.au-v.pp-v.ok')->text());
        self::assertSame(['skip', 'ok', 'skip', 'ok', 'ok', 'ok'], $kilimani->filter('ol.pp-walk > li')->each(static fn (Crawler $c): string => (string) $c->attr('class')), 'the department is the last question: nothing more to say');
        self::assertStringStartsWith('In Kilimani Game Reserve, 3 can: '.self::RANGERS.' 3; by tier: Super Admin and Admin. As of ', self::squash($kilimani->filter('p.pp-cov')->text()));
    }

    public function testWhereTheyArePlacedIsTheQuestionsOwnWords(): void
    {
        $answer = $this->page('?person='.$this->id('Thabo Ndlovu').'&q=surveys.record')->filter('#check .pp-ans');
        self::assertSame('Can Thabo Ndlovu record surveys where they are placed — Kilimani Game Reserve?', self::squash($answer->filter('.pp-qline')->text()));
        $placed = $this->page('?person='.$this->id('Thabo Ndlovu').'&q=surveys.record')->filter('form.lfilt');
        self::assertSame('placed', $placed->filter('input[name="target"]')->attr('value'));
        self::assertSame('where they are placed', $placed->filter('details[data-pc-axis="target"] .i-ddval')->text());

        $grace = $this->page('?person='.$this->id('Grace Ndosi').'&q=surveys.read&target=organization')->filter('#check .pp-qline');
        self::assertSame('Can Grace Ndosi read surveys across the whole organization?', self::squash($grace->text()));
    }

    public function testTheFactsAreThreeColumnsUnderTheCoverageLine(): void
    {
        $answer = $this->page($this->ask('Grace Ndosi', 'locations.read', 'Mlima Mkuu'))->filter('.onpage-main > #check .pp-ans');

        self::assertSame(['ahead', 'walk', 'cov', 'facts'], $answer->children()->each(static fn (Crawler $c): string => substr(explode(' ', (string) $c->attr('class'))[0], 3)), 'the facts block follows the coverage line');
        $columns = $answer->filter('.pp-facts > section.pp-fcol');
        self::assertSame(['person', 'question', 'here'], $columns->each(static fn (Crawler $c): ?string => $c->attr('id')));
        self::assertSame(['The person', 'The question', 'Here'], $columns->each(static fn (Crawler $c): string => (string) $c->filter('h2.zone')->getNode(0)?->firstChild?->textContent), 'each column under its zone head');
        self::assertCount(0, $answer->filter('.pp-facts .c, .pp-facts .tab'), 'not cards');
    }

    public function testThePersonAndTheQuestion(): void
    {
        $page = $this->page($this->ask('Grace Ndosi', 'locations.read', 'Mlima Mkuu'));

        $person = $page->filter('#check .pp-facts #person');
        self::assertSame('· Grace Ndosi · read live', self::squash($person->filter('h2.zone .src')->text()));
        self::assertSame(['Tier', 'Position', 'Placed at', 'Department', 'Supports', 'Exception'], $person->filter('.pp-kv .pp-kk')->each(static fn (Crawler $c): string => $c->text()));
        $values = $person->filter('.pp-kv .pp-kvv')->each(static fn (Crawler $c): string => self::squash($c->text()));
        self::assertSame(['Staff', 'Head of Operations'], \array_slice($values, 0, 2));
        self::assertStringStartsWith('the whole organization', $values[2]);
        self::assertStringEndsWith('open the placement →', $values[2]);
        self::assertSame(['Protection Service', 'Ecology and Monitoring'], \array_slice($values, 3, 2));
        self::assertStringStartsWith('read live locations — in force · ', $values[5]);
        self::assertSame('/team/positions/'.$this->org->positionNamed('Head of Operations')->getUuidString(), $person->filter('.pp-kv')->eq(1)->filter('a')->attr('href'));

        $question = $page->filter('#check .pp-facts #question');
        self::assertSame('· read live locations', self::squash($question->filter('h2.zone .src')->text()));
        self::assertSame(['Verbs', 'Granted over', 'Module', 'Sensitive', 'Kept for the tiers', 'Held by'], $question->filter('.pp-kv .pp-kk')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(1, $question->filter('p.pp-lead'));
        self::assertSame('read', $question->filter('.pp-kv')->eq(0)->filter('b')->text(), 'the asked verb');
        self::assertStringStartsWith('yes — it lifts ', self::squash($question->filter('.pp-kv')->eq(3)->filter('.pp-kvv')->text()));
        self::assertSame('no — a position can hold it', $question->filter('.pp-kv')->eq(4)->filter('.pp-kvv')->text());
        self::assertSame('2 positions · 2 peopleopen the positions →', self::squash($question->filter('.pp-kv')->eq(5)->filter('.pp-kvv')->text()));
        self::assertSame('/team/permissions/positions', $question->filter('.pp-kv')->eq(5)->filter('a.more')->attr('href'));
    }

    public function testHereHoldsOnlyWhatTheVoterReads(): void
    {
        $here = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Mlima Mkuu'))->filter('#check .pp-facts #here');

        self::assertSame('Here', $here->filter('h2.zone')->getNode(0)?->firstChild?->textContent);
        self::assertSame('· Thabo Ndlovu at Mlima Mkuu · asked live', self::squash($here->filter('h2.zone .src')->text()));
        self::assertSame(['Placement covers it', 'Department runs Surveys', 'Exception'], $here->filter('.pp-kv .pp-kk')->each(static fn (Crawler $c): string => $c->text()));
        $values = $here->filter('.pp-kv .pp-kvv');
        self::assertSame('no — their placement names Kilimani Game Reserve only · 1 area', self::squash($values->eq(0)->text()));
        self::assertSame('yes — Protection Service runs Surveys · they serve Protection Servicenot reached here — the placement refuses first', self::squash($values->eq(1)->text()));
        self::assertSame('none held', $values->eq(2)->text());
        self::assertNotNull($here->filter('p.pp-none')->attr('hidden'));

        $rhino = $this->page($this->ask('Elias Mdee', 'locations.read', 'Kilimani Game Reserve'))->filter('#here .pp-kv');
        self::assertSame('yes — placed across the whole organization', $rhino->eq(0)->filter('.pp-kvv')->text());
        self::assertSame('Department runs the module', $rhino->eq(1)->filter('.pp-kk')->text());
        self::assertSame('not asked — the question belongs to no module', $rhino->eq(1)->filter('.pp-kvv')->text());
        self::assertSame('held, not in force — read live locations is not lifted, here or anywhere', $rhino->eq(2)->filter('.pp-kvv')->text());
    }

    public function testATierHoldsEverythingHere(): void
    {
        $page = $this->page($this->ask('Desta Haile', 'surveys.record', 'Mlima Mkuu'));

        self::assertSame('allowed', $page->filter('#check .au-v.pp-v.ok')->text());
        self::assertCount(0, $page->filter('#here .pp-kv'));
        self::assertSame('Admin — above the matrix: the tier holds every permission here, and nothing else is asked.', $page->filter('#here p.pp-none')->text());
        self::assertNull($page->filter('#here p.pp-none')->attr('hidden'));
        self::assertNotNull($page->filter('#everything')->attr('hidden'), 'no table for a tier');
    }

    public function testEverythingHereIsTheWholeCatalogueAllowedFirst(): void
    {
        $pairs = $this->catalogue()->pairs();
        $card = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Kilimani Game Reserve'))->filter('#everything');

        self::assertNull($card->attr('hidden'));
        self::assertSame('· '.\count($pairs).' permissions · asked live', self::squash($card->filter('.tab .src')->text()));
        self::assertSame(['Question', 'Verdict', 'Decided by', ''], $card->filter('table.tbl.pp-here thead th')->each(static fn (Crawler $c): string => $c->text()));

        $rows = $card->filter('table.tbl.pp-here tbody tr');
        self::assertCount(\count($pairs), $rows, 'every pair of the catalogue, one flat table');
        $classes = $rows->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        $firstNo = array_search('no', $classes, true);
        self::assertNotFalse($firstNo);
        self::assertSame(array_fill(0, (int) $firstNo, 'ok'), \array_slice($classes, 0, (int) $firstNo), 'the allowed first');
        self::assertNotContains('ok', \array_slice($classes, (int) $firstNo));

        $asked = $rows->filter('tr[aria-current="true"]');
        self::assertCount(1, $asked);
        self::assertSame('record surveys', $asked->filter('.pp-q')->text());
        self::assertCount(0, $rows->filter('b, strong'), 'no bold: the asked row is marked by the bar');
        self::assertSame('allowed', $asked->filter('td.pp-vd .au-v.pp-v.ok')->text());
        self::assertSame('department', $asked->filter('td.pp-db .chip.ok')->text());
        self::assertSame('Protection Service runs Surveys', $asked->filter('td.pp-db .pp-dl')->text());
        self::assertSame('Check →', $asked->filter('td.pp-exd a.more')->text());
        self::assertSame(self::PATH.'?person='.$this->id('Thabo Ndlovu').'&q=surveys.record&target='.$this->areaKey('Kilimani Game Reserve'), $asked->filter('td.pp-exd a.more')->attr('href'), 'the same pair at the same place');

        $tierOnly = $rows->filter('tr[data-group="tier-only"]')->first();
        self::assertSame('tier-only', $tierOnly->filter('td.pp-db .chip.warn')->text());
        self::assertSame('position', $rows->filter('tr[data-group="not-held"]')->first()->filter('td.pp-db .chip.warn')->text());

        $bar = $card->filter('.lfilt');
        $only = $bar->filter('details.i-dd[data-pc-axis="here-only"]');
        self::assertSame('all · '.\count($pairs), $only->filter('summary.mchip.i-ddt .i-ddval')->text());
        $options = $only->filter('.i-ddmenu button.i-ddopt');
        self::assertSame(['All', 'Allowed', 'Refused'], \array_slice($options->each(static fn (Crawler $c): string => $c->filter('.i-ddopt-l')->text()), 0, 3));
        self::assertContains('For the tiers', $options->each(static fn (Crawler $c): string => $c->filter('.i-ddopt-l')->text()));
        self::assertNotContains('By Exception', $options->each(static fn (Crawler $c): string => $c->filter('.i-ddopt-l')->text()), 'a group nobody lands in draws no option');
        self::assertSame((string) \count($rows->filter('tr.ok')), $options->eq(1)->filter('.i-ddopt-n')->text());
        $concern = $bar->filter('details.i-dd[data-pc-axis="here-concern"]');
        self::assertSame('resource', $concern->filter('.i-ddval')->text());
        self::assertSame('Any resource', $concern->filter('.i-ddmenu button.i-ddopt')->first()->filter('.i-ddopt-l')->text());
        self::assertContains('Surveys', $concern->filter('.i-ddmenu .i-ddhead')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Find a question', $bar->filter('.lsearch input.fld[type="search"]')->attr('placeholder'));

        self::assertPageSize($card, \count($pairs).' permissions', 'everything');
        self::assertStringStartsWith('page 1 of '.(int) ceil(\count($pairs) / 20), self::squash($card->filter('.rdf-foot .rdf-page')->text()));
        self::assertSame(\count($rows->filter('tr.ok')).' of '.\count($pairs).' permissions allowed here · asked live.', self::squash($card->filter('p.pp-cov')->text()));
        self::assertSame('No permission matches these filters.', $card->filter('p.pq-empty')->text());
    }

    public function testAPowerIsAskedAboutItsTargetAtTheirOwnPlacement(): void
    {
        $this->runNow();
        $page = $this->page('?person='.$this->id('Thabo Ndlovu').'&q=make-admin&target=a-colleague');

        $check = $page->filter('#check');
        self::assertSame('refused', $check->filter('.au-v.pp-v.no')->text());
        self::assertSame('Can Thabo Ndlovu make Admin — about a colleague in their area?', self::squash($check->filter('.pp-qline')->text()));
        self::assertSame('a-colleague', $check->filter('form.lfilt input[name="target"]')->attr('value'));
        $chip = $check->filter('form.lfilt details[data-pc-axis="target"]');
        self::assertSame('a colleague in their area', $chip->filter('.i-ddval')->text());
        self::assertContains('a colleague in their area', $chip->filter('.pp-ddlist .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()), 'the power\'s own targets');
        self::assertNotContains('the whole organization', $chip->filter('.pp-ddlist .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertMatchesRegularExpression('/^\d+ of \d+ actors hold this over a colleague in their area\.$/', self::squash($check->filter('p.pp-cov')->text()));

        self::assertSame('· make Admin', self::squash($page->filter('#question h2.zone .src')->text()));
        self::assertSame(['Power', 'Asks first', 'About'], $page->filter('#question .pp-kv .pp-kk')->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('one of ', $page->filter('#question .pp-kv')->eq(2)->text());
        self::assertSame('A power is asked of their own placement, so the place here is where they are placed — Kilimani Game Reserve.', self::squash($page->filter('#here p.pp-lead')->text()));
        self::assertSame(['Placement covers it', 'Exception'], $page->filter('#here .pp-kv .pp-kk')->each(static fn (Crawler $c): string => $c->text()));
    }

    public function testWorthRunningListsEveryStaffPersonTheFlaggedFirst(): void
    {
        $this->runNow();
        $worth = $this->findings()->worthRunning();
        $flagged = \count(array_filter($worth, static fn (array $r): bool => $r['flagged']));
        $card = $this->page()->filter('#worth');

        self::assertNull($card->attr('hidden'));
        self::assertStringStartsWith('· '.\count($worth).' people · '.$flagged.' flagged · from the run of ', self::squash($card->filter('.tab .src')->text()));
        self::assertSame(['Person', 'Question', 'Why', ''], $card->filter('table.tbl.pp-ex thead th')->each(static fn (Crawler $c): string => $c->text()));
        $rows = $card->filter('table.tbl.pp-ex tbody tr');
        self::assertCount(\count($worth), $rows, 'every active Staff person');
        self::assertSame(array_column(array_column($worth, 'person'), 'name'), $rows->each(static fn (Crawler $c): string => (string) $c->filter('td')->getNode(0)?->firstChild?->textContent), 'in the reader\'s order: flagged first');
        self::assertCount(0, $rows->filter('b, strong'), 'no bold outside the chips');

        $first = $rows->eq(0);
        $position = $this->org->personNamed($worth[0]['person']['name'])->getPosition()?->getName() ?? 'no position';
        self::assertSame($position, $first->filter('td')->eq(0)->filter('span.sub')->text(), 'the position as the sub-line');
        self::assertSame($worth[0]['place']['name'], $first->filter('td')->eq(1)->filter('span.sub')->text(), 'the place under the question');
        self::assertSame($worth[0]['why'], $first->filter('td')->eq(2)->text());
        self::assertCount(1, $first->filter('.chip.warn'));
        self::assertSame('Run →', $first->filter('td.pp-exd a.more')->text());
        self::assertSame(self::PATH.'?person='.$worth[0]['person']['id'].'&q='.$worth[0]['question']['key'].'&target='.$worth[0]['place']['key'], $first->filter('td.pp-exd a.more')->attr('href'));
        self::assertCount(\count($worth) - $flagged, $rows->filter('.chip.pp-quiet'), 'nothing flagged is the quiet chip');
        self::assertCount(1, $rows->filter('.chip.ok'), 'the exception in force is a confirmation');

        $only = $card->filter('.lfilt details.i-dd[data-pc-axis="worth-only"]');
        self::assertSame('all · '.\count($worth), $only->filter('.i-ddval')->text());
        $labels = $only->filter('button.i-ddopt .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text());
        self::assertSame('All', $labels[0]);
        self::assertContains('Nothing flagged', $labels);
        self::assertNotContains('Gains power', $labels, 'nobody gains power on the fixture');
        $sort = $card->filter('.lfilt details.i-dd[data-pc-axis="worth-sort"]');
        self::assertSame('flagged first', $sort->filter('.i-ddval')->text());
        self::assertSame(['Flagged first', 'By name', 'By place'], $sort->filter('button.i-ddopt .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Find a person, question or place', $card->filter('.lsearch input.fld')->attr('placeholder'));
        self::assertPageSize($card, \count($worth).' people', 'worth');
        self::assertSame('Nobody matches these filters.', $card->filter('p.pq-empty')->text());
    }

    public function testWorthRunningWaitsForARun(): void
    {
        self::assertNotNull($this->page()->filter('#worth')->attr('hidden'));
    }

    public function testRecentChecksAreTheBrowsersAndTheCheckIsHandedToThem(): void
    {
        $recent = $this->page()->filter('#recent');
        self::assertSame('· this session, newest first', self::squash($recent->filter('.tab .src')->text()));
        self::assertSame(['Person', 'Question', 'Verdict', ''], $recent->filter('table.tbl.pp-rec thead th')->each(static fn (Crawler $c): string => $c->text()));
        self::assertNotNull($recent->filter('table.tbl')->attr('hidden'), 'nothing asked yet');
        self::assertCount(0, $recent->filter('table.tbl tbody tr'), 'the rows are the browser\'s');
        $only = $recent->filter('.lfilt details.i-dd[data-pc-axis="recent-only"]');
        self::assertSame(['All', 'Allowed', 'Refused'], $only->filter('button.i-ddopt .i-ddopt-l')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Find a person, question or place', $recent->filter('.lsearch input.fld')->attr('placeholder'));
        self::assertSame('recent', $recent->filter('.rdf-foot details.i-dd.rdf-size')->attr('data-pc-size'));
        self::assertSame('No check matches these filters.', $recent->filter('p.pq-empty')->text());
        self::assertSame('No check asked yet this session.', $recent->filter('p.au-why.pp-empty')->text());

        $page = $this->page($this->ask('Thabo Ndlovu', 'surveys.record', 'Mlima Mkuu'))->filter('[data-controller="uhifadhi--team-bundle--permission-check"]');
        $asked = json_decode((string) $page->attr('data-uhifadhi--team-bundle--permission-check-asked-value'), true);
        self::assertIsArray($asked);
        self::assertSame([
            'person' => 'Thabo Ndlovu',
            'words' => 'record surveys in Mlima Mkuu',
            'question' => 'record surveys',
            'place' => 'Mlima Mkuu',
            'allowed' => false,
            'href' => self::PATH.'?person='.$this->id('Thabo Ndlovu').'&q=surveys.record&target='.$this->areaKey('Mlima Mkuu'),
        ], $asked);
    }

    public function testThePageSaysResourceAndPermissionNeverConcernOrPair(): void
    {
        $this->runNow();
        foreach (['', $this->ask('Thabo Ndlovu', 'surveys.record', 'Mlima Mkuu'), $this->ask('Thabo Ndlovu', 'surveys.record', 'Kilimani Game Reserve'), $this->ask('Elias Mdee', 'locations.read', 'Kilimani Game Reserve'), $this->ask('Desta Haile', 'surveys.record', 'Mlima Mkuu'), '?person='.$this->id('Thabo Ndlovu').'&q=make-admin&target=a-colleague'] as $query) {
            $page = $this->page($query)->filter('.pghead, .onpage-nav, .onpage-main');
            $read = implode(' ', $page->each(static fn (Crawler $c): string => $c->text()));
            foreach ($page->filter('[aria-label], [placeholder], [title], template') as $node) {
                \assert($node instanceof \DOMElement);
                $read .= ' '.$node->getAttribute('aria-label').' '.$node->getAttribute('placeholder').' '.$node->getAttribute('title').' '.$node->textContent;
            }
            preg_match_all('/.{0,40}\b(?:concern|pair)s?\b.{0,40}/iu', (string) preg_replace('/\s+/u', ' ', $read), $words);
            self::assertSame([], array_values(array_unique($words[0])), 'a reader meets resources and permissions: '.$query);
        }
    }

    public function testAnAddressThatNamesNobodyAsksNothing(): void
    {
        $page = $this->page('?person=999999&q=surveys.record');
        self::assertCount(0, $page->filter('.pp-ans'));
        self::assertSame('surveys.record', $page->filter('form.lfilt input[name="q"]')->attr('value'), 'what can be kept is kept');
        self::assertSame('choose a person', $page->filter('form.lfilt details[data-pc-axis="person"] .i-ddval')->text());

        $page = $this->page('?person='.$this->id('Thabo Ndlovu').'&q=nothing.such');
        self::assertCount(0, $page->filter('.pp-ans'));
        self::assertSame((string) $this->id('Thabo Ndlovu'), $page->filter('form.lfilt input[name="person"]')->attr('value'));
        self::assertSame('choose a question', $page->filter('form.lfilt details[data-pc-axis="q"] .i-ddval')->text(), 'no question, no check');

        $page = $this->page('?person='.$this->id('Thabo Ndlovu').'&q=surveys.record&target=area-999999');
        self::assertSame('placed', $page->filter('form.lfilt input[name="target"]')->attr('value'), 'an unknown place is where they are placed');
    }

    private function ask(string $person, string $pair, string $area): string
    {
        return '?person='.$this->id($person).'&q='.$pair.'&target='.$this->areaKey($area);
    }

    private function id(string $name): int
    {
        return (int) $this->org->personNamed($name)->getId();
    }

    private function areaKey(string $name): string
    {
        return 'area-'.$this->org->areaNamed($name)->getId();
    }

    private function page(string $query = ''): Crawler
    {
        $this->client->loginUser($this->naomi());
        $page = $this->client->request('GET', self::PATH.$query);
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function naomi(): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => 'naomi.kileo@example.test']) ?? throw new \LogicException('gone');
    }

    private function runNow(): void
    {
        $service = $this->service(PermissionRunService::class);
        $run = $service->requestRun(PermissionRunTriggerEnum::Manual, null);
        $service->handle($run->getId());
    }

    private function findings(): PermissionFindingsReader
    {
        return $this->service(PermissionFindingsReader::class);
    }

    private function catalogue(): ConcernCatalogue
    {
        return $this->service(ConcernCatalogue::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->client->getContainer()->get('test_public.'.$class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * The foot is the shell's (@Shell/_foot, paged in the browser): the
     * count, then the page size as a chooser of its own, 20 · 50 · 100 · All,
     * remembered per table, the arrows the page's controller moves.
     */
    private static function assertPageSize(Crawler $card, string $count, string $table): void
    {
        self::assertCount(1, $card->filter('.rdf-foot'));
        $foot = $card->filter('.rdf-foot > span')->first();
        self::assertSame($count, self::squash((string) $foot->filter('[data-pc-count]')->text()), 'the count alone; the separator is the foot\'s');
        self::assertStringStartsWith($count.' · 20 per page', self::squash($foot->text()));
        self::assertSame(['20', '50', '100', 'all'], $foot->filter('.i-ddmenu[aria-label="Rows per page"] button.i-ddopt')->each(static fn (Crawler $c): string => (string) $c->attr('data-pc-per')));
        self::assertCount(1, $card->filter('.rdf-foot > .rdf-page[data-pc-page]'));
        $size = $foot->filter('details.i-dd.rdf-size');
        self::assertSame($table, $size->attr('data-pc-size'));
        self::assertSame('20 per page▾', self::squash($size->filter('summary')->text()));
        self::assertSame('20', $size->filter('summary .pg')->text());
        self::assertSame(['20', '50', '100', 'All'], $size->filter('.i-ddmenu button.i-ddopt')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['20'], $size->filter('.i-ddmenu button.i-ddopt.on')->each(static fn (Crawler $c): string => $c->text()));
        self::assertCount(0, $size->filter('.i-ddhead'), 'no head');
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
