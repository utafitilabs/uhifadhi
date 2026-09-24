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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaEditController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaIdentity;
use Uhifadhi\Bundle\AreaBundle\Service\BoundaryImport;

/**
 * THE EDIT SCREEN — an area's identity, edited in place, and its boundary,
 * replaced through the one import pipeline with an honest guard in front of it.
 *
 * Two writes live on this screen, and they are nothing alike. Editing the name,
 * IUCN category or gazettement year touches plain metadata with no geometric
 * dependency — saved in place. Replacing the boundary supersedes the geometry
 * everything else in the area is filed against, so it goes through the same
 * {@see BoundaryImport} as a new area and asks for an explicit confirmation
 * first. Every case here goes through the HTTP layer, because the interesting
 * failures — a blank name, a file that is not GeoJSON, a replace nobody
 * confirmed, a viewer without `areas.configure` — are all at the joins.
 */
#[CoversClass(AreaEditController::class)]
#[CoversClass(AreaIdentity::class)]
final class AreaEditTest extends WebTestCase
{
    private const string A_REPLACEMENT = '{"type":"Polygon","coordinates":[[[10.0,10.0],[11.0,10.0],[11.0,11.0],[10.0,11.0],[10.0,10.0]]]}';

    private function get(string $path): Response
    {
        $this->browser()->request('GET', $path);

        return $this->browser()->getResponse();
    }

    private function body(string $path): string
    {
        return (string) $this->get($path)->getContent();
    }

    private function editUrl(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/edit';
    }

    private function tokenOn(string $path, string $id): string
    {
        $matched = preg_match('#name="_token" value="([^"]+)"[^>]*data-token="'.$id.'"#', $this->body($path), $m);

        return 1 === $matched ? $m[1] : '';
    }

    /**
     * TWO MAIN CARDS AND A PREVIEW/GUARD SIDEBAR. The identity form on the left,
     * the boundary card beneath it, and on the right the current boundary drawn
     * on the shared map plate with the heads-up guard that names what a replace
     * would touch.
     */
    public function testTheEditScreenRendersTheIdentityAndBoundaryCardsAndThePreviewGuard(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Conservation Reserve');

        $body = $this->body($this->editUrl($area));

        // The identity form — every field the design edits.
        self::assertStringContainsString('name="name"', $body);
        self::assertStringContainsString('name="iucn"', $body);
        self::assertStringContainsString('name="established"', $body);
        self::assertStringContainsString('Save identity', $body);

        // The boundary card — the drop target and the warning-toned replace.
        self::assertStringContainsString('name="boundary"', $body);
        self::assertStringContainsString('Replace boundary', $body);

        // The preview sidebar — the current boundary on the shared map plate...
        self::assertStringContainsString('data-controller="uhifadhi--atlas-bundle--map-plate"', $body);
        self::assertStringContainsString('MultiPolygon', $body);
        // ...and the heads-up guard, deactivate-never-destroy in its own words.
        self::assertStringContainsString('HEADS UP', $body);
        self::assertStringContainsString('nothing is deleted', $body);
    }

    /** The identity form pre-fills what the area already is, ready to be edited. */
    public function testTheIdentityFormIsPreFilledWithTheAreasCurrentValues(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Conservation Reserve');
        $area->setIucnCategory('VI')->setEstablishedYear(1959);
        $this->em->flush();

        $body = $this->body($this->editUrl($area));

        self::assertStringContainsString('value="Northern Conservation Reserve"', $body);
        self::assertStringContainsString('value="1959"', $body);
        // The stored IUCN option is the selected one.
        self::assertMatchesRegularExpression('/<option[^>]*selected[^>]*>\s*VI\s*<\/option>/', $body);
    }

    public function testEditingTheIdentityPersistsAndRedirectsToTheRecord(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');
        $uuid = $area->getUuidString();

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Northern Conservation Reserve',
            'iucn' => 'VI',
            'established' => '1959',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);
        $response = $this->browser()->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/areas/'.$uuid.'/configure/settings', $response->headers->get('Location'));

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Conservation Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertSame('VI', $fresh->getIucnCategory());
        self::assertSame(1959, $fresh->getEstablishedYear());
    }

    /**
     * THE ONE SETTING ON THIS SCREEN ROUND-TRIPS: typed, saved, and read back
     * on the record beside the facts it is not one of.
     */
    public function testTheZoneOverlapToleranceRoundTrips(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Northern Reserve',
            'zoneOverlapTolerance' => '2.5',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);

        self::assertSame(302, $this->browser()->getResponse()->getStatusCode());

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertSame(2.5, $fresh->getZoneOverlapTolerancePct());

        // And the form offers it back, so a second edit is not a retype.
        $this->browser()->request('GET', $this->editUrl($fresh));
        self::assertMatchesRegularExpression(
            '/name="zoneOverlapTolerance"\s+value="2\.5"/',
            (string) $this->browser()->getResponse()->getContent(),
        );
    }

    /** Blank is NOT SET, which reads as the platform's default rather than as zero. */
    public function testABlankToleranceIsNotSet(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');
        $area->setZoneOverlapTolerancePct(4.0);
        $this->em->flush();

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Northern Reserve',
            'zoneOverlapTolerance' => '',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertNull($fresh->getZoneOverlapTolerancePct());
    }

    /** Past ten percent the answer is to fix the scheme, so the form says so instead of clamping. */
    public function testAToleranceOutOfRangeIsRefusedAndTheAreaIsUnchanged(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Northern Reserve',
            'zoneOverlapTolerance' => '50',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);

        self::assertSame(422, $this->browser()->getResponse()->getStatusCode());
        self::assertStringContainsString('between 0 and 10', (string) $this->browser()->getResponse()->getContent());

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertNull($fresh->getZoneOverlapTolerancePct());
    }

    /** Clearing the gazetted facts is allowed — they are optional and a blank means unrecorded. */
    public function testTheGazettedFactsCanBeClearedBackToUnrecorded(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');
        $area->setIucnCategory('VI')->setEstablishedYear(1959);
        $this->em->flush();

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Northern Reserve',
            'iucn' => '',
            'established' => '',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);

        self::assertSame(302, $this->browser()->getResponse()->getStatusCode());

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertNull($fresh->getIucnCategory());
        self::assertNull($fresh->getEstablishedYear());
    }

    public function testANamelessIdentityEditIsRefusedAndTheAreaIsUnchanged(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => '   ',
            '_token' => $this->tokenOn($this->editUrl($area), 'area_edit'),
        ]);
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertSame('Northern Reserve', $fresh->getName());
    }

    public function testTheIdentityFormRefusesAPostWithoutAValidToken(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        $this->browser()->request('POST', $this->editUrl($area), [
            'name' => 'Forged',
            '_token' => 'forged',
        ]);

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
    }

    /**
     * THE REPLACE GOES THROUGH THE IMPORT PIPELINE AND SUPERSEDES IN PLACE. A
     * confirmed replacement runs the same {@see BoundaryImport} as a new area,
     * so the geometry changes, the provenance becomes "upload", and the row is
     * the same area it was.
     */
    public function testReplacingTheBoundaryGoesThroughImportAndSupersedes(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve', 'WDPA');
        $uuid = $area->getUuidString();
        $before = $area->getGeom();

        $this->postReplacement($area, self::A_REPLACEMENT, 'new.geojson', confirm: true);
        $response = $this->browser()->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/areas/'.$uuid, $response->headers->get('Location'));

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertNotSame($before, $fresh->getGeom(), 'the boundary was superseded');
        self::assertStringContainsString('11', (string) $fresh->getGeom());
        self::assertSame(BoundaryImport::SOURCE, $fresh->getSource());
    }

    /**
     * VALIDATED BEFORE ANYTHING IS COMMITTED. A file that is not GeoJSON is
     * refused on the form and the current boundary is left exactly as it was —
     * no half-replace.
     */
    public function testAReplacementThatIsNotGeoJsonIsRefusedAndTheBoundaryIsUnchanged(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve', 'WDPA');

        $this->postReplacement($area, "name,lat,lon\nx,1,2\n", 'points.csv', confirm: true);
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('GeoJSON', $this->errorOn($response));

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        // The original extent is still there and the replacement never landed.
        self::assertStringContainsString('-3.6', (string) $fresh->getGeom());
        self::assertStringNotContainsString('11', (string) $fresh->getGeom());
        self::assertSame('WDPA', $fresh->getSource());
    }

    /**
     * NOT A HARD BLOCK, BUT NOT A SILENT ONE EITHER. A replace with no explicit
     * confirmation is refused with the guard's own words, and nothing is
     * superseded — the person is standing at the same screen with the file still
     * to confirm.
     */
    public function testReplacingWithoutConfirmingIsRefusedAndNothingIsSuperseded(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve', 'WDPA');

        $this->postReplacement($area, self::A_REPLACEMENT, 'new.geojson', confirm: false);
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('confirm', $this->errorOn($response));

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        // The original extent survives; the replacement never superseded it.
        self::assertStringContainsString('-3.6', (string) $fresh->getGeom());
        self::assertStringNotContainsString('11', (string) $fresh->getGeom());
    }

    public function testReplacingTheBoundaryRefusesAPostWithoutAValidToken(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve', 'WDPA');

        $path = tempnam(sys_get_temp_dir(), 'area-boundary-');
        self::assertIsString($path);
        file_put_contents($path, self::A_REPLACEMENT);

        $this->browser()->request(
            'POST',
            '/areas/'.$area->getUuidString().'/boundary/replace',
            ['confirm' => '1', '_token' => 'forged'],
            ['boundary' => new UploadedFile($path, 'new.geojson', null, null, true)],
        );

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
    }

    /**
     * hasBoundary DRIVES THE SCREEN. An area with no geometry on file has nothing
     * to replace and nothing to preview: the boundary card offers to ADD one, the
     * heads-up guard is absent (there is nothing to supersede), and the preview
     * is the no-boundary state rather than an empty map.
     */
    public function testAnAreaWithNoBoundaryOffersAddNotReplaceAndCarriesNoGuard(): void
    {
        $this->boot();
        $this->signIn();
        $area = new AreaOfInterest()->setName('Unmapped Reserve');
        $this->em->persist($area);
        $this->em->flush();

        $body = $this->body($this->editUrl($area));

        // It offers to add a boundary, not to replace one...
        self::assertStringContainsString('Add boundary', $body);
        self::assertStringNotContainsString('Replace boundary', $body);
        // ...there is nothing to guard against superseding...
        self::assertStringNotContainsString('HEADS UP', $body);
        // ...and no map is drawn where there is no geometry.
        self::assertStringNotContainsString('data-controller="uhifadhi--atlas-bundle--map-plate"', $body);
        self::assertStringContainsString('no boundary on file', $body);
    }

    /** Adding a boundary to a boundary-less area needs no confirmation — nothing is superseded. */
    public function testAddingABoundaryToABoundarylessAreaNeedsNoConfirmation(): void
    {
        $this->boot();
        $this->signIn();
        $area = new AreaOfInterest()->setName('Unmapped Reserve');
        $this->em->persist($area);
        $this->em->flush();

        $this->postReplacement($area, self::A_REPLACEMENT, 'new.geojson', confirm: false);
        $response = $this->browser()->getResponse();

        self::assertSame(302, $response->getStatusCode());

        $this->em->clear();
        $fresh = $this->em->getRepository(AreaOfInterest::class)->find($area->getId());
        self::assertInstanceOf(AreaOfInterest::class, $fresh);
        self::assertTrue($fresh->hasBoundary());
    }

    /** The Area settings section of the configure page carries the entry to this screen. */
    public function testTheAreaSettingsSectionOffersAnEditAreaEntry(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        $body = $this->body('/areas/'.$area->getUuidString().'/configure/settings');

        self::assertStringContainsString('Edit area', $body);
        self::assertStringContainsString('href="/areas/'.$area->getUuidString().'/edit"', $body);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function closedDoors(): iterable
    {
        yield 'the edit screen needs areas.configure' => [self::READ_ONLY_AREA_PERMISSIONS];
    }

    /**
     * @param list<string> $grants
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('closedDoors')]
    public function testTheEditScreenIsRefusedToSomebodyWhoMayNotConfigureAreas(array $grants): void
    {
        $this->boot($grants);
        $this->signIn();
        $area = $this->anArea('Northern Reserve');

        self::assertContains(
            $this->get($this->editUrl($area))->getStatusCode(),
            [401, 403],
            'a screen without its permission must refuse, not render',
        );
    }

    private function postReplacement(AreaOfInterest $area, string $contents, string $filename, bool $confirm): void
    {
        $path = tempnam(sys_get_temp_dir(), 'area-boundary-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        $parameters = ['_token' => $this->tokenOn($this->editUrl($area), 'area_boundary_replace')];
        if ($confirm) {
            $parameters['confirm'] = '1';
        }

        $this->browser()->request(
            'POST',
            '/areas/'.$area->getUuidString().'/boundary/replace',
            $parameters,
            ['boundary' => new UploadedFile($path, $filename, null, null, true)],
        );
    }

    /** The one line the screen prints a refusal on. */
    private function errorOn(Response $response): string
    {
        $matched = preg_match('#<p class="import-error"[^>]*>(.*?)</p>#s', (string) $response->getContent(), $m);
        self::assertSame(1, $matched, 'The screen printed no .import-error line.');

        return html_entity_decode($m[1]);
    }
}
