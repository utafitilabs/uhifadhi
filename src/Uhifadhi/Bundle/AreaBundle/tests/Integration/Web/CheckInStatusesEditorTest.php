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

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Controller\CheckInStatusesController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckIn;
use Uhifadhi\Bundle\AreaBundle\Entity\CheckInStatus;
use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Shell\AreaConfigurationSections;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE AREA'S CHECK-IN STATUSES CARD on Configure › Area settings
 * (uhifadhi-web/areas/kilimani/configure/index.html#check-in-statuses): the
 * list it is drawn from, the save through the settings save bar, and the
 * refusals, each through HTTP.
 */
#[CoversClass(CheckInStatusesController::class)]
#[CoversClass(AreaConfigurationSections::class)]
final class CheckInStatusesEditorTest extends WebTestCase
{
    public function testTheCardIsDrawnFromTheAreasListWithTheKindsInThePlatformsWords(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $body = $this->body($this->settingsUrl($area));

        self::assertStringContainsString('id="check-in-statuses"', $body);
        self::assertStringContainsString('Check-in statuses<span class="src">&middot; duty_checkin_status</span>', $body);
        self::assertStringContainsString('data-controller="uhifadhi--area-bundle--check-in-statuses"', $body);
        self::assertStringContainsString('action="/areas/'.$area->getUuidString().'/configure/check-in-statuses"', $body);
        self::assertStringContainsString('At least one At a station status stays offered', $body);
        self::assertStringContainsString('A kind is fixed once a status has check-ins', $body);
        self::assertStringContainsString('Add a status', $body);
        self::assertStringContainsString('<b>No unsaved changes</b>', $body);

        $model = $this->model($body);
        self::assertSame(['at_post', 'unfit', 'outside', 'special'], array_column($model['saved'], 'key'), 'an area asked for the first time is given the four it starts with');
        self::assertSame([false, false, false, false], array_column($model['saved'], 'used'));
        self::assertSame(['At a station', 'Working elsewhere', 'Not working', 'Special assignment'], array_column($model['kinds'], 'label'));
        self::assertSame('On duty at a named station · the only kind that takes a station', $model['kinds'][0]['means']);
        self::assertNull($model['current']);
        self::assertNull($model['refusal']);
    }

    public function testAStatusWithCheckInsIsMarkedUsed(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aCheckInWith($area, 'outside');

        $model = $this->model($this->body($this->settingsUrl($area)));

        self::assertSame(['at_post' => false, 'unfit' => false, 'outside' => true, 'special' => false], array_combine(array_column($model['saved'], 'key'), array_column($model['saved'], 'used')));
    }

    public function testASaveIsStoredAndTheBarSaysSaved(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $token = $this->tokenOn($this->settingsUrl($area));

        $this->browser()->request('POST', $this->saveUrl($area), [
            '_token' => $token,
            'statuses' => [
                ['key' => 'outside', 'label' => 'Off park', 'kind' => 'working_elsewhere', 'active' => '1'],
                ['key' => 'at_post', 'label' => 'At post', 'kind' => 'at_post', 'active' => '1'],
                ['key' => 'in_court', 'label' => 'In court', 'kind' => 'working_elsewhere', 'active' => '1', 'new' => '1'],
                ['key' => 'unfit', 'label' => 'Unfit for duty', 'kind' => 'not_working', 'active' => '0'],
                ['key' => 'special', 'label' => 'Special assignment', 'kind' => 'special', 'active' => '1'],
            ],
        ]);

        $response = $this->browser()->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->settingsUrl($area).'#check-in-statuses', $response->headers->get('Location'));

        $this->em->clear();
        $stored = $this->em->getRepository(CheckInStatus::class)->findBy(['area' => $this->reloaded($area)], ['position' => 'ASC']);
        self::assertSame(['outside', 'at_post', 'in_court', 'special', 'unfit'], array_map(static fn (CheckInStatus $s): string => $s->getKey(), $stored));
        self::assertSame('Off park', $stored[0]->getLabel());
        self::assertSame(CheckInStatusKind::WorkingElsewhere, $stored[2]->getKind());
        self::assertFalse($stored[4]->isActive());

        $after = $this->body($this->settingsUrl($area));
        self::assertStringContainsString('<b>Saved</b>', $after);
        self::assertTrue($this->model($after)['justSaved']);
    }

    public function testARefusedSaveKeepsTheEditsAndSaysWhyWithA422(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $token = $this->tokenOn($this->settingsUrl($area));

        $this->browser()->request('POST', $this->saveUrl($area), [
            '_token' => $token,
            'statuses' => [
                ['key' => 'outside', 'label' => 'Off park', 'kind' => 'working_elsewhere', 'active' => '1'],
                ['key' => 'unfit', 'label' => 'Unfit', 'kind' => 'not_working', 'active' => '1', 'new' => '1'],
            ],
        ]);

        $response = $this->browser()->getResponse();
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('class="ed-bar refused"', $body);
        self::assertStringContainsString('Not saved — another save took the key unfit first. Choose another key; nothing else was lost.', $body);

        $model = $this->model($body);
        self::assertSame('unfit', $model['refusedKey']);
        self::assertSame([
            ['key' => 'outside', 'label' => 'Off park', 'kind' => 'working_elsewhere', 'active' => true, 'new' => false],
            ['key' => 'unfit', 'label' => 'Unfit', 'kind' => 'not_working', 'active' => true, 'new' => true],
        ], $model['current'], 'every edit is kept');

        $this->em->clear();
        self::assertSame('Outside the park', $this->em->getRepository(CheckInStatus::class)->findOneBy(['area' => $this->reloaded($area), 'key' => 'outside'])?->getLabel(), 'nothing was saved');
    }

    public function testTheLastAtAStationStatusCannotBeDeactivated(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('POST', $this->saveUrl($area), [
            '_token' => $this->tokenOn($this->settingsUrl($area)),
            'statuses' => [['key' => 'at_post', 'label' => 'At post', 'kind' => 'at_post', 'active' => '0']],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('at least one At a station status stays offered', (string) $this->browser()->getResponse()->getContent());
    }

    public function testASaveWithoutAValidTokenIsRefused(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('POST', $this->saveUrl($area), [
            '_token' => 'forged',
            'statuses' => [['key' => 'at_post', 'label' => 'Here', 'kind' => 'at_post', 'active' => '1']],
        ]);

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
    }

    public function testSomebodyWhoMayNotConfigureTheAreaCannotSaveIt(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();
        $area = $this->anArea();

        $this->browser()->request('POST', $this->saveUrl($area), [
            '_token' => 'any',
            'statuses' => [['key' => 'at_post', 'label' => 'Here', 'kind' => 'at_post', 'active' => '1']],
        ]);

        self::assertContains($this->browser()->getResponse()->getStatusCode(), [401, 403]);
        $this->em->clear();
        self::assertNotSame('Here', $this->em->getRepository(CheckInStatus::class)->findOneBy(['key' => 'at_post'])?->getLabel());
    }

    public function testThePageAndTheControllerNameTheSameFields(): void
    {
        $controller = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/controllers/check_in_statuses_controller.js');
        $package = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/package.json');

        foreach (["'statuses[' + i + '][' + name + ']'", "{ key: r.key, label: r.label, kind: r.kind ?? '', active: r.active ? '1' : '0' }", "fields.new = '1'", 'script[data-cis-model]', 'template[data-cis-icon="up"]', "'.ed-bar'"] as $name) {
            self::assertStringContainsString($name, $controller);
        }
        self::assertStringContainsString('"check-in-statuses"', $package);
        self::assertStringContainsString('controllers/check_in_statuses_controller.js', $package);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function settingsUrl(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/settings';
    }

    private function saveUrl(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/configure/check-in-statuses';
    }

    private function body(string $path): string
    {
        $this->browser()->request('GET', $path);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function tokenOn(string $path): string
    {
        $matched = preg_match('#name="_token" value="([^"]+)"[^>]*data-token="'.CheckInStatusesController::TOKEN.'"#', $this->body($path), $m);

        return 1 === $matched ? $m[1] : '';
    }

    /**
     * @return array{saved: list<array{key: string, label: string, kind: string, active: bool, used: bool}>, current: list<array<string, mixed>>|null, kinds: list<array{v: string, label: string, means: string}>, refusal: string|null, refusedKey: string|null, justSaved: bool}
     */
    private function model(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" data-cis-model>(.*?)</script>#s', $body, $m), 'the card carries its model');
        /** @var array{saved: list<array{key: string, label: string, kind: string, active: bool, used: bool}>, current: list<array<string, mixed>>|null, kinds: list<array{v: string, label: string, means: string}>, refusal: string|null, refusedKey: string|null, justSaved: bool} $model */
        $model = json_decode(html_entity_decode($m[1] ?? ''), true, flags: \JSON_THROW_ON_ERROR);

        return $model;
    }

    private function aCheckInWith(AreaOfInterest $area, string $key): void
    {
        /** @var CheckInStatusService $statuses */
        $statuses = static::getContainer()->get('test_public.area.checkin_statuses');
        $status = array_find($statuses->listedFor($area), static fn (CheckInStatus $s): bool => $key === $s->getKey());
        self::assertInstanceOf(CheckInStatus::class, $status);

        $person = new HostUser();
        $this->em->persist($person);
        $this->em->persist(new CheckIn()
            ->setArea($area)
            ->setPerson($person)
            ->setClientRef('c-'.$key)
            ->setLocalDate(new \DateTimeImmutable('2026-09-19'))
            ->setStatus($status)
            ->setOccurredAt(new \DateTimeImmutable('2026-09-19T06:08:12+03:00'))
            ->setDeviceId('d-1')
            ->setAppVersion('0.1.0'));
        $this->em->flush();
    }

    private function reloaded(AreaOfInterest $area): AreaOfInterest
    {
        $fresh = $this->em->find(AreaOfInterest::class, $area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);

        return $fresh;
    }
}
