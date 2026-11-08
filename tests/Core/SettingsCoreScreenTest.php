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
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Contracts\Settings\SettingDepth;

/**
 * SETTINGS › CORE: the cascade of the core's settings, read at /settings/core
 * and changed at /settings/configure/core by Super Admins and Admins
 * (uhifadhi-web/settings/core.html and settings/configure/core.html).
 */
final class SettingsCoreScreenTest extends FieldApiTestCase
{
    private AreaOfInterest $kilimani;
    private AreaOfInterest $tambarare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kilimani = $this->area('Kilimani Game Reserve');
        $this->tambarare = $this->area('Tambarare Game Reserve');
        $this->em->persist(new SettingValue(CoreSettings::PING_INTERVAL, SettingDepth::Area, $this->kilimani->getUuidString(), 10, 'seed', new \DateTimeImmutable()));
        $this->em->flush();
    }

    public function testTheReadPageDrawsEverySettingAsASummaryThatOpensOntoItsPlaces(): void
    {
        $this->signIn(TeamRoleEnum::Admin);

        $page = $this->visit('/settings/core');

        self::assertSame('Core', $page->filter('.atabs a.on')->text());
        self::assertSame(['Setting', 'Default', 'Area', 'Department'], $page->filter('table.tbl.dw thead th')->each(static fn (Crawler $c): string => self::squash($c->text())));
        self::assertStringContainsString('1 area sets its own', $page->filter('.c > .tab')->text());
        self::assertStringContainsString('only Super Admins and Admins change this', $page->filter('.c > .tab')->text());
        self::assertSame(['Presence', 'Zones'], $page->filter('tr.fam .slab')->each(static fn (Crawler $c): string => $c->text()));

        $ping = $this->settingRow($page, 'Ping interval');
        self::assertSame('How often a handset on duty reports its position.', $ping->filter('.cmwhat')->text());
        self::assertSame('30 min', $ping->filter('td.lvc.org .valf')->text());
        self::assertSame('1 of 2 areas set their own', $ping->filter('td.lvc.area .sumc')->text());
        self::assertSame('false', $ping->filter('button.mrow')->attr('aria-expanded'));

        $places = $this->placesOf($page, CoreSettings::PING_INTERVAL);
        self::assertCount(3, $places);
        self::assertStringContainsString('the default for every area', $places->eq(0)->filter('.cn em')->text());
        self::assertSame('30 min', $places->eq(0)->filter('td.lvc.org .valf')->text());
        self::assertStringStartsWith('Kilimani Game Reserve', $places->eq(1)->filter('.cn')->text());
        self::assertSame('area', $places->eq(1)->filter('.cn em')->text());
        self::assertSame('10 min · this area’s own', self::squash($places->eq(1)->filter('td.lvc.area')->text()));
        self::assertSame('30 min · the default', self::squash($places->eq(2)->filter('td.lvc.area')->text()));
        self::assertNotNull($places->eq(1)->attr('hidden'), 'a setting row is shut until it is opened');

        $stale = $this->placesOf($page, CoreSettings::STALE_AFTER);
        self::assertSame('two ping intervals', $stale->eq(0)->filter('td.lvc.org .valf')->text(), 'unset, the owner computes it');

        self::assertCount(0, $page->filter('input, .ed-bar'), 'the read page changes nothing');
        self::assertSame('/settings/configure/core', $page->filter('.pgact a.tgl')->attr('href'));
    }

    public function testTheConfigurePageHoldsOneControlPerValue(): void
    {
        $this->signIn(TeamRoleEnum::SuperAdmin);

        $page = $this->visit('/settings/configure/core');

        $form = $page->filter('form[method="post"]');
        self::assertCount(1, $form);
        $places = $this->placesOf($page, CoreSettings::PING_INTERVAL);

        $default = $places->eq(0)->filter('.ed-in input');
        self::assertSame('30', $default->attr('value'));
        self::assertSame('1', $default->attr('min'));
        self::assertSame('1440', $default->attr('max'));
        self::assertSame('min', $places->eq(0)->filter('.ed-in .u')->text());

        self::assertSame('10', $places->eq(1)->filter('td.lvc.area .ed-in input')->attr('value'));
        self::assertSame('Use the default', $places->eq(1)->filter('button.btn.sm.ed-reset')->text());
        self::assertCount(0, $places->eq(2)->filter('input'));
        self::assertSame('30 min · the default', self::squash($places->eq(2)->filter('.ed-follow')->text()));
        self::assertSame('Set this area’s own', $places->eq(2)->filter('button.btn.sm.ed-custom')->text());

        self::assertCount(1, $this->settingRow($page, 'Ping interval')->filter('td.lvc.org .valf'), 'the setting row only repeats the default');
        self::assertSame('No unsaved changes', $page->filter('.ed-bar b')->text());
        self::assertSame(['Discard', 'Review ▴', 'Save'], $page->filter('.ed-bar button')->each(static fn (Crawler $c): string => $c->text()));
    }

    public function testSavingTheDefaultWritesTheOrganizationsValue(): void
    {
        $this->signIn(TeamRoleEnum::Admin);
        $form = $this->visit('/settings/configure/core')->filter('form[method="post"]')->form();
        $form['org['.CoreSettings::PING_INTERVAL.']'] = '10';

        $this->client->submit($form);

        self::assertResponseRedirects('/settings/configure/core');
        self::assertSame(10, $this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Organization, null));
        self::assertSame(10, $this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Area, $this->kilimani->getUuidString()), 'nothing else moved');
        self::assertNull($this->stored(CoreSettings::ZONE_OVERLAP_TOLERANCE, SettingDepth::Organization, null), 'an unchanged default is not written');
        self::assertSame('Saved', $this->client->followRedirect()->filter('.ed-bar b')->text());
    }

    public function testAnAreaSetsItsOwnValueAndGoesBackToTheDefault(): void
    {
        $this->signIn(TeamRoleEnum::Admin);
        $page = $this->visit('/settings/configure/core');

        // Kilimani's field is gone (Use the default); Tambarare has one (Set this area's own).
        $this->client->request('POST', '/settings/configure/core', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            'org' => [CoreSettings::PING_INTERVAL => '30', CoreSettings::STALE_AFTER => '', CoreSettings::ZONE_OVERLAP_TOLERANCE => '1'],
            'area' => [CoreSettings::STALE_AFTER => [(string) $this->tambarare->getUuidString() => '45']],
        ]);

        self::assertResponseRedirects('/settings/configure/core');
        self::assertNull($this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Area, $this->kilimani->getUuidString()), 'Use the default removes the area’s value');
        self::assertSame(45, $this->stored(CoreSettings::STALE_AFTER, SettingDepth::Area, $this->tambarare->getUuidString()));
    }

    public function testAValueOutsideTheDefinitionsLimitsIsRefusedAndNothingIsKept(): void
    {
        $this->signIn(TeamRoleEnum::Admin);
        $form = $this->visit('/settings/configure/core')->filter('form[method="post"]')->form();
        $form['org['.CoreSettings::STALE_AFTER.']'] = '60';
        $form['org['.CoreSettings::PING_INTERVAL.']'] = '0';

        $page = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('"Ping interval" does not accept that value (1–1440 min).', $page->filter('.ed-bar.refused b')->text());
        self::assertNull($this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Organization, null));
        self::assertNull($this->stored(CoreSettings::STALE_AFTER, SettingDepth::Organization, null), 'one bad change refuses the batch');
    }

    public function testSomebodyBelowAdminReadsButIsRefusedTheConfigurePage(): void
    {
        $this->client->loginUser($this->ranger(grants: ['settings.read']));

        $page = $this->visit('/settings/core');
        self::assertCount(0, $page->filter('.pgact a.tgl'), 'no door to a page that refuses them');

        $this->client->request('GET', '/settings/configure/core');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/settings/configure/core', ['org' => [CoreSettings::PING_INTERVAL => '5']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Organization, null));
    }

    public function testSomebodyWhoMayNotReadSettingsIsRefusedTheReadPage(): void
    {
        $this->client->loginUser($this->ranger(grants: []));

        $this->client->request('GET', '/settings/core');
        self::assertResponseStatusCodeSame(403);
    }

    public function testASaveWithoutTheFormsTokenIsRefused(): void
    {
        $this->signIn(TeamRoleEnum::Admin);

        $this->client->request('POST', '/settings/configure/core', ['org' => [CoreSettings::PING_INTERVAL => '5'], '_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored(CoreSettings::PING_INTERVAL, SettingDepth::Organization, null));
    }

    public function testTheAreasSettingsLinkToSettingsCore(): void
    {
        $this->signIn(TeamRoleEnum::Admin);

        $page = $this->visit('/areas/'.$this->kilimani->getUuidString().'/configure/settings');

        $links = $page->filter('a.chip')->reduce(static fn (Crawler $c): bool => 'set in Settings › Core' === $c->text());
        self::assertCount(3, $links);
        self::assertSame('/settings/core', $links->first()->attr('href'));
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
