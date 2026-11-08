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

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Core\Tests\Core\Fixtures\SettingsModuleKernel;
use Uhifadhi\Core\Tests\Core\Fixtures\SightingsSettings;

/**
 * SETTINGS › MODULES › <MODULE>: one page per module that declares settings,
 * read at /settings/modules/{slug} and changed at
 * /settings/configure/modules/{slug} by Super Admins and Admins, drawn with
 * the same cascade as Settings › Core
 * (uhifadhi-web/settings/modules/incidents.html, settings/configure/modules/incidents.html).
 *
 * A CHOICE IS ONE CONTROL OF NATIVE RADIOS, one per choice, inside labels
 * drawn as the house segmented row: they post with the form, take the arrow
 * keys natively, and are fields a count of the form's fields finds.
 */
final class SettingsModuleScreenTest extends FieldApiTestCase
{
    private AreaOfInterest $kilimani;
    private AreaOfInterest $tambarare;

    protected static function getKernelClass(): string
    {
        return SettingsModuleKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->kilimani = $this->area('Kilimani Game Reserve');
        $this->tambarare = $this->area('Tambarare Game Reserve');
        $this->em->persist(new SettingValue(SightingsSettings::UNSURE, SettingDepth::Area, $this->kilimani->getUuidString(), 'refuse', 'seed', new \DateTimeImmutable()));
        $this->em->flush();
    }

    public function testTheReadPageIsTheModulesCascade(): void
    {
        $this->signIn(TeamRoleEnum::Admin);

        $page = $this->visit('/settings/modules/sightings');

        self::assertSame('Sightings', $page->filter('h1')->text());
        self::assertSame('Every animal seen, where and when. The default for every area, and where an area sets its own.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('Modules', $page->filter('.atabs a.on')->text());
        self::assertSame('/settings/modules', $page->filter('.pgact a.open-btn')->attr('href'));
        self::assertSame('/settings/configure/modules/sightings', $page->filter('.pgact a.tgl')->attr('href'));
        self::assertStringContainsString('Sightings settings', $page->filter('.c > .tab')->text());
        self::assertStringContainsString('1 area sets its own', $page->filter('.c > .tab')->text());
        self::assertSame(['Recording'], $page->filter('tr.fam .slab')->each(static fn (Crawler $c): string => $c->text()));

        $unsure = $this->settingRow($page, 'A sighting of an unsure species');
        self::assertSame('A sighting from a phone is always kept.', $unsure->filter('button.mrow + p.cx-note')->text());
        self::assertSame('Ask', $unsure->filter('td.lvc.org .valf')->text());
        self::assertSame('1 of 2 areas set their own', $unsure->filter('td.lvc.area .sumc')->text());

        $places = $this->placesOf($page, SightingsSettings::UNSURE);
        self::assertSame('Ask', $places->eq(0)->filter('td.lvc.org .valf')->text());
        self::assertSame('Refuse · this area’s own', self::squash($places->eq(1)->filter('td.lvc.area')->text()));
        self::assertSame('Ask · the default', self::squash($places->eq(2)->filter('td.lvc.area')->text()));

        self::assertSame('200 m', $this->settingRow($page, 'Merge if within')->filter('td.lvc.org .valf')->text());
        self::assertCount(0, $this->settingRow($page, 'Merge if within')->filter('.cx-note'), 'a setting without a note draws none');
        self::assertCount(0, $page->filter('input, .ed-bar'), 'the read page changes nothing');
    }

    public function testAChoiceIsOneRadioPerChoiceWithTheChosenOnesMeaningUnderTheDefault(): void
    {
        $this->signIn(TeamRoleEnum::SuperAdmin);

        $page = $this->visit('/settings/configure/modules/sightings');

        self::assertSame('Change the default for every area, or set an area’s own. Nothing is saved until Save.', $page->filter('.pghead .pgsub')->text());
        self::assertSame('/settings/modules/sightings', $page->filter('.pgact a.open-btn')->attr('href'));
        $places = $this->placesOf($page, SightingsSettings::UNSURE);

        $default = $places->eq(0)->filter('td.lvc.org .seg.row');
        self::assertSame('radiogroup', $default->attr('role'));
        self::assertSame(['Keep', 'Ask', 'Refuse'], $default->filter('label')->each(static fn (Crawler $c): string => self::squash($c->text())));
        $radios = $default->filter('label > input[type="radio"]');
        self::assertSame(['keep', 'ask', 'refuse'], $radios->each(static fn (Crawler $c): string => (string) $c->attr('value')));
        self::assertSame(['org[sightings.unsure_species]'], array_values(array_unique($radios->each(static fn (Crawler $c): string => (string) $c->attr('name')))));
        self::assertSame(['ask'], $default->filter('input:checked')->each(static fn (Crawler $c): string => (string) $c->attr('value')));
        self::assertSame(['kept as unknown', 'kept once the recorder confirms it', 'not kept until a species is named'], $radios->each(static fn (Crawler $c): string => (string) $c->attr('data-means')));
        self::assertSame('kept once the recorder confirms it', $places->eq(0)->filter('td.lvc.org .ed-hint[data-means-line]')->text());

        $own = $places->eq(1)->filter('td.lvc.area');
        self::assertSame(['refuse'], $own->filter('.seg.row input:checked')->each(static fn (Crawler $c): string => (string) $c->attr('value')));
        self::assertSame('area[sightings.unsure_species]['.$this->kilimani->getUuidString().']', $own->filter('.seg.row input')->attr('name'));
        self::assertSame('Use the default', $own->filter('button.ed-reset')->text());
        self::assertCount(0, $own->filter('[data-means-line]'), 'the meaning is said under the default alone');
        self::assertSame('Ask · the default', self::squash($places->eq(2)->filter('.ed-follow')->text()));
        self::assertSame('Set this area’s own', $places->eq(2)->filter('button.ed-custom')->text());

        self::assertSame('200', $this->placesOf($page, SightingsSettings::MERGE_WITHIN)->eq(0)->filter('.ed-in input')->attr('value'));
    }

    public function testSavingAChoiceWritesItAndAnAreaGoesBackToTheDefault(): void
    {
        $this->signIn(TeamRoleEnum::Admin);
        $page = $this->visit('/settings/configure/modules/sightings');

        $this->client->request('POST', '/settings/configure/modules/sightings', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            'org' => [SightingsSettings::UNSURE => 'keep', SightingsSettings::MERGE_WITHIN => '200'],
            'area' => [SightingsSettings::UNSURE => [(string) $this->tambarare->getUuidString() => 'refuse']],
        ]);

        self::assertResponseRedirects('/settings/configure/modules/sightings');
        self::assertSame('keep', $this->stored(SightingsSettings::UNSURE, SettingDepth::Organization, null));
        self::assertNull($this->stored(SightingsSettings::UNSURE, SettingDepth::Area, $this->kilimani->getUuidString()), 'Use the default removes the area’s value');
        self::assertSame('refuse', $this->stored(SightingsSettings::UNSURE, SettingDepth::Area, $this->tambarare->getUuidString()));
        self::assertNull($this->stored(SightingsSettings::MERGE_WITHIN, SettingDepth::Organization, null), 'an unchanged default is not written');
        self::assertSame('Saved', $this->client->followRedirect()->filter('.ed-bar b')->text());
    }

    public function testAChoiceThatIsNotOneOfItsChoicesIsRefused(): void
    {
        $this->signIn(TeamRoleEnum::Admin);
        $page = $this->visit('/settings/configure/modules/sightings');

        $refused = $this->client->request('POST', '/settings/configure/modules/sightings', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            'org' => [SightingsSettings::UNSURE => 'shrug', SightingsSettings::MERGE_WITHIN => '200'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A sighting of an unsure species', $refused->filter('.ed-bar.refused b')->text());
        self::assertNull($this->stored(SightingsSettings::UNSURE, SettingDepth::Organization, null));
    }

    public function testOnlySuperAdminsAndAdminsChangeAModulesSettings(): void
    {
        $this->client->loginUser($this->ranger(grants: ['settings.read']));

        $page = $this->visit('/settings/modules/sightings');
        self::assertCount(0, $page->filter('.pgact a.tgl'), 'no door to a page that refuses them');

        $this->client->request('GET', '/settings/configure/modules/sightings');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/settings/configure/modules/sightings', ['org' => [SightingsSettings::UNSURE => 'keep']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored(SightingsSettings::UNSURE, SettingDepth::Organization, null));
    }

    public function testSomebodyWhoMayNotReadSettingsIsRefusedTheReadPage(): void
    {
        $this->client->loginUser($this->ranger(grants: []));

        $this->client->request('GET', '/settings/modules/sightings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAModuleWithoutSettingsHasNoPage(): void
    {
        $this->signIn(TeamRoleEnum::SuperAdmin);

        foreach (['/settings/modules/nothing', '/settings/configure/modules/nothing', '/settings/modules/core', '/settings/configure/modules/core'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testSettingsModulesOpensOntoEachModulesSettings(): void
    {
        $this->signIn(TeamRoleEnum::Admin);

        $doors = $this->visit('/settings/modules')->filter('a[href="/settings/modules/sightings"]');

        self::assertCount(1, $doors);
    }

    private function signIn(TeamRoleEnum $tier): User
    {
        $user = $this->officeStaff('Naomi', 'Kileo')->setTeamRole($tier);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function visit(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function settingRow(Crawler $page, string $label): Crawler
    {
        $row = $page->filter('table.tbl.dw tbody tr')->reduce(static fn (Crawler $tr): bool => 1 === $tr->filter('.cmname b')->count() && $label === $tr->filter('.cmname b')->text());
        self::assertCount(1, $row, $label);

        return $row;
    }

    private function placesOf(Crawler $page, string $key): Crawler
    {
        return $page->filter(\sprintf('table.tbl.dw tbody tr.dk[data-setting="%s"]', $key));
    }

    private function stored(string $key, SettingDepth $level, ?string $place): int|float|bool|string|null
    {
        $this->em->clear();
        $row = $this->em->getRepository(SettingValue::class)->findOneBy(['key' => $key, 'level' => $level, 'placeUuid' => $place]);

        return $row?->getValue();
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
