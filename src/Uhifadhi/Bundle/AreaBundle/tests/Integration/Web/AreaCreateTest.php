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
use Uhifadhi\Bundle\AreaBundle\Controller\AreaCreateController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\AreaCreator;
use Uhifadhi\Bundle\AreaBundle\Service\BoundaryImport;

/**
 * CREATING AN AREA THROUGH THE REAL SCREEN.
 *
 * Every case here goes through the HTTP layer with a real upload and a real
 * PostGIS insert, because the interesting failures are all at the joins: a file
 * that is not GeoJSON, a document with no polygon in it, a viewer without
 * `areas.configure`.
 */
#[CoversClass(AreaCreateController::class)]
#[CoversClass(AreaCreator::class)]
#[CoversClass(BoundaryImport::class)]
final class AreaCreateTest extends WebTestCase
{
    private const string A_POLYGON = '{"type":"Polygon","coordinates":[[[-30.0,-3.6],[-29.0,-3.6],[-29.0,-2.8],[-30.0,-2.8],[-30.0,-3.6]]]}';

    public function testTheFormRendersForSomebodyWhoMayCreate(): void
    {
        $this->boot();
        $this->signIn();

        $response = $this->get('/areas/new');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringContainsString('name="name"', $body);
        self::assertStringContainsString('type="file"', $body);
        self::assertStringContainsString('name="boundary"', $body);
    }

    /** The register's button is now an address, not a literal that 404s. */
    public function testTheRegisterLinksToTheCreateScreen(): void
    {
        $this->boot();
        $this->signIn();

        self::assertStringContainsString('href="/areas/new"', $this->body('/areas'));
    }

    /**
     * THE AFFORDANCE CARRIES THE PERMISSION, not just the screen behind it. A
     * button that opens onto a refusal is a worse answer than no button.
     */
    public function testTheRegisterHidesTheButtonFromSomebodyWhoMayNotCreate(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();

        self::assertStringNotContainsString('href="/areas/new"', $this->body('/areas'));
    }

    public function testTheScreenIsClosedToSomebodyWhoMayNotCreate(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();

        self::assertSame(403, $this->get('/areas/new')->getStatusCode());
    }

    public function testAnUploadedBoundaryBecomesAnAreaAndRedirectsToIt(): void
    {
        $this->boot();
        $this->signIn();

        $response = $this->post('Northern Conservation Reserve', self::A_POLYGON, 'northern.geojson');

        self::assertSame(302, $response->getStatusCode());

        $this->em->clear();
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Conservation Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $area);
        // Where the boundary came from, recorded rather than guessed.
        self::assertSame('upload', $area->getSource());
        self::assertSame('/areas/'.$area->getUuidString(), $response->headers->get('Location'));
    }

    /** The screen offers the choice the create flow is about: add now, or add later. */
    public function testTheFormOffersTheAddNowOrAddLaterChoice(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body('/areas/new');
        self::assertStringContainsString('name="boundary_mode"', $body);
        self::assertStringContainsString('value="now"', $body);
        self::assertStringContainsString('value="later"', $body);
    }

    /**
     * THE THING THAT COULD NOT HAPPEN BEFORE: an area created from its name
     * alone, with no boundary, landing on its own overview. No file is posted,
     * and none is asked for.
     */
    public function testAnAreaIsCreatedWithNoBoundaryWhenAddingItLater(): void
    {
        $this->boot();
        $this->signIn();

        $this->browser()->request('POST', '/areas/new', [
            'name' => 'Olkeju',
            'boundary_mode' => 'later',
            '_token' => $this->tokenOnTheForm(),
        ]);
        $response = $this->browser()->getResponse();

        self::assertSame(302, $response->getStatusCode());

        $this->em->clear();
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Olkeju']);
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertFalse($area->hasBoundary(), 'created with no gazetted edge');
        self::assertNull($area->getSource());
        self::assertSame('/areas/'.$area->getUuidString(), $response->headers->get('Location'));
    }

    /** The gazetted facts typed on the form reach the row. */
    public function testTheGazettedFactsArePersistedFromTheForm(): void
    {
        $this->boot();
        $this->signIn();

        $this->browser()->request('POST', '/areas/new', [
            'name' => 'Northern Conservation Reserve',
            'iucn' => 'VI',
            'established' => '1959',
            'boundary_mode' => 'later',
            '_token' => $this->tokenOnTheForm(),
        ]);

        self::assertSame(302, $this->browser()->getResponse()->getStatusCode());

        $this->em->clear();
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Northern Conservation Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $area);
        self::assertSame('VI', $area->getIucnCategory());
        self::assertSame(1959, $area->getEstablishedYear());
        self::assertFalse($area->hasBoundary());
    }

    /**
     * A NAMELESS AREA IS REFUSED WHETHER OR NOT A BOUNDARY IS ATTACHED — the
     * later path has no file to hide behind, so this pins the name check on the
     * creation itself rather than on the import.
     */
    public function testANamelessAreaIsRefusedWhenAddingLater(): void
    {
        $this->boot();
        $this->signIn();

        $this->browser()->request('POST', '/areas/new', [
            'name' => '   ',
            'boundary_mode' => 'later',
            '_token' => $this->tokenOnTheForm(),
        ]);
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('name', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    /**
     * A LONE POLYGON IS STORED AS A MULTIPOLYGON — the column's shape, and the
     * proof that the normalizer's coercion survives the round trip through
     * PostGIS rather than only passing in isolation.
     */
    public function testALonePolygonIsStoredAsAMultiPolygon(): void
    {
        $this->boot();
        $this->signIn();

        $this->post('Southern Reserve', self::A_POLYGON, 'southern.geojson');

        $this->em->clear();
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'Southern Reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $area);

        $stored = json_decode((string) $area->getGeom(), true);
        self::assertIsArray($stored);
        self::assertSame('MultiPolygon', $stored['type'] ?? null);
    }

    /**
     * THE REFUSAL IS THE SAME PAGE WITH A SENTENCE ON IT, not a redirect and not
     * an error page: whoever picked the wrong file is still standing at the form
     * they have to pick another one in.
     */
    public function testAFileThatIsNotGeoJsonIsRefusedOnTheFormItself(): void
    {
        $this->boot();
        $this->signIn();

        $response = $this->post('Somewhere', "name,lat,lon\nx,1,2\n", 'points.csv');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('GeoJSON', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    public function testADocumentWithNoPolygonInItIsRefused(): void
    {
        $this->boot();
        $this->signIn();

        $response = $this->post('Somewhere', '{"type":"Point","coordinates":[-30.0,-3.6]}', 'point.geojson');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('polygon', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    public function testANamelessAreaIsRefused(): void
    {
        $this->boot();
        $this->signIn();

        $response = $this->post('   ', self::A_POLYGON, 'northern.geojson');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('name', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    /**
     * A POST WITH NOTHING ATTACHED is what the server does when the upload blew
     * past post_max_size: PHP discards the body and the form arrives empty. The
     * screen has to say the limit rather than "please choose a file", which
     * would send somebody back to do exactly what they just did.
     */
    public function testAPostWithNoFileNamesTheServerLimit(): void
    {
        $this->boot();
        $this->signIn();

        $this->browser()->request('POST', '/areas/new', ['name' => 'Northern Reserve', '_token' => $this->tokenOnTheForm()]);
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('upload limit', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    /**
     * THE OTHER LIMIT, AND IT ARRIVES DIFFERENTLY. Past `post_max_size` the body
     * is discarded and no file arrives at all; past `upload_max_filesize` a file
     * DOES arrive — invalid, with an error code and an empty path. Read blindly
     * that becomes "the file is not valid GeoJSON", which sends somebody off to
     * re-export a file that was fine. The upload's own error is the message.
     */
    public function testAFileTooBigForTheUploadLimitNamesTheLimit(): void
    {
        $this->boot();
        $this->signIn();

        $path = tempnam(sys_get_temp_dir(), 'area-boundary-');
        self::assertIsString($path);
        file_put_contents($path, self::A_POLYGON);

        $this->browser()->request(
            'POST',
            '/areas/new',
            ['name' => 'Northern Reserve', '_token' => $this->tokenOnTheForm()],
            ['boundary' => new UploadedFile($path, 'northern.geojson', null, \UPLOAD_ERR_INI_SIZE, true)],
        );
        $response = $this->browser()->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('upload limit', $this->errorOn($response));
        self::assertSame(0, $this->countAreas());
    }

    /**
     * A WRITE IS A WRITE. Creating an area is the most consequential thing on
     * this bundle's screens, so it carries a token like the module shop's three
     * writes do. One POST without one, inside a bundle whose other writes all
     * have one, is the shape a hole usually comes in.
     */
    public function testTheFormMintsATokenAndAPostWithoutOneIsRefused(): void
    {
        $this->boot();
        $this->signIn();

        self::assertStringContainsString('name="_token"', $this->body('/areas/new'));

        $path = tempnam(sys_get_temp_dir(), 'area-boundary-');
        self::assertIsString($path);
        file_put_contents($path, self::A_POLYGON);

        $this->browser()->request(
            'POST',
            '/areas/new',
            ['name' => 'Forged', '_token' => 'forged'],
            ['boundary' => new UploadedFile($path, 'northern.geojson', null, null, true)],
        );

        self::assertSame(403, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(0, $this->countAreas());
    }

    public function testCreatingIsClosedToSomebodyWhoMayNotCreate(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();

        self::assertSame(403, $this->post('Northern Reserve', self::A_POLYGON, 'northern.geojson')->getStatusCode());
        self::assertSame(0, $this->countAreas());
    }

    private function get(string $path): Response
    {
        $this->browser()->request('GET', $path);

        return $this->browser()->getResponse();
    }

    private function body(string $path): string
    {
        return (string) $this->get($path)->getContent();
    }

    private function post(string $name, string $contents, string $filename): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'area-boundary-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        // The token the form itself minted, read back off the page rather than
        // generated here: a test that mints its own proves nothing about the form.
        $this->browser()->request(
            'POST',
            '/areas/new',
            ['name' => $name, '_token' => $this->tokenOnTheForm()],
            ['boundary' => new UploadedFile($path, $filename, null, null, true)],
        );

        return $this->browser()->getResponse();
    }

    private function tokenOnTheForm(): string
    {
        $matched = preg_match('#name="_token" value="([^"]+)"#', $this->body('/areas/new'), $m);

        return 1 === $matched ? $m[1] : '';
    }

    /** The one line the screen prints a refusal on, and nothing else on the page. */
    private function errorOn(Response $response): string
    {
        $matched = preg_match('#<p class="import-error"[^>]*>(.*?)</p>#s', (string) $response->getContent(), $m);
        self::assertSame(1, $matched, 'The screen printed no .import-error line.');

        return html_entity_decode($m[1]);
    }

    private function countAreas(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(AreaOfInterest::class)->findAll());
    }
}
