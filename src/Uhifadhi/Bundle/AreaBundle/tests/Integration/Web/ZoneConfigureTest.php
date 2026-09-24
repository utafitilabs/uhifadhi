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
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneConfigureController;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneEditController;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneImportController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\Web\Fixtures\HostUser;

/**
 * THE ZONES SECTION OF THE CONFIGURE PAGE, OVER REAL HTTP.
 *
 * A SECTION WITH AN ADDRESS OF ITS OWN still wears the configure frame: the
 * shell puts the section strip where a data page's tabs go and lights the
 * Configure action, because the frame recognises a section's own route.
 *
 * EVERY WRITE ANSWERS WITH A REDIRECT, so a refresh cannot repeat it, and every
 * write leaves a line in the log.
 */
#[CoversClass(ZoneConfigureController::class)]
#[CoversClass(ZoneImportController::class)]
#[CoversClass(ZoneEditController::class)]
final class ZoneConfigureTest extends WebTestCase
{
    public function testTheSectionListsTheZonesTheAreaHasAndOffersTheSetsActions(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $body = $this->body($this->section($area));

        self::assertStringContainsString('Western Sector', $body);
        self::assertStringContainsString('Import zones', $body);
        self::assertStringContainsString('Export the set', $body);
        self::assertStringContainsString('Remove all 1 zone', $body);
        // The plate is the atlas's, wearing the house contract, with the key below it.
        self::assertStringContainsString('map-plate', $body);
        self::assertStringContainsString('map-legend', $body);
    }

    /** An area with no zones says so in its own words rather than showing an empty stack. */
    public function testAnAreaWithNoZonesDrawsTheEmptySet(): void
    {
        $this->boot();
        $this->signIn();

        $body = $this->body($this->section($this->anArea()));

        self::assertStringContainsString('No zones yet', $body);
        self::assertStringContainsString('One polygon feature, one zone', $body);
        self::assertStringNotContainsString('Remove all', $body);
    }

    public function testThePreviewStatesAVerdictPerFeatureAndWritesNothing(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $this->preview($area, $this->twoHalves());

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        $body = $this->body($this->section($area));

        self::assertStringContainsString('arriving', $body);
        self::assertStringContainsString('flagged', $body);
        self::assertStringContainsString('name already here', $body);
        // Nothing was written, and the file was not kept.
        self::assertSame(1, $this->countZones($area));
    }

    public function testConfirmingThePreviewWritesTheSubsetAndLogsIt(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->preview($area, $this->twoHalves());
        $token = $this->tokenOn($this->body($this->section($area)), '/zones/import/confirm');
        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/zones/import/confirm', [
            '_token' => $token,
            'arriving' => ['Western Sector'],
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(1, $this->countZones($area));

        $body = $this->body($this->section($area));
        // The outcome the confirm redirected with, and the line the log gained.
        self::assertStringContainsString('1 zone added', $body);
        self::assertStringContainsString('names from Name', $body);
    }

    public function testAProjectedFileIsRefusedWholeAndTheReasonIsTheWholeSentence(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->preview($area, (string) json_encode([
            'type' => 'FeatureCollection',
            'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:EPSG::32736']],
            'features' => [$this->feature('Western Sector', self::A_WEST_HALF_RING)],
        ], \JSON_THROW_ON_ERROR), 'wards_utm.geojson');

        $body = $this->body($this->section($area));
        self::assertStringContainsString('not read', $body);
        self::assertStringContainsString('coordinates are projected', $body);
        // A refusal is an event too: somebody tried, and nothing changed.
        self::assertStringContainsString('File refused', $body);
    }

    public function testTheSetIsExportedAsOneFeatureCollectionAttachment(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea('Northern Reserve');
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $this->browser()->request('GET', '/areas/'.$area->getUuidString().'/zones/export.geojson');
        $response = $this->browser()->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/geo+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('northern-reserve-zones.geojson', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('"FeatureCollection"', (string) $response->getContent());
    }

    public function testARenameTouchesNoGeometryAndLeavesALine(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Oldean', self::A_WEST_HALF);

        $token = $this->tokenOn($this->body($this->section($area)), '/rename');
        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString().'/rename', [
            '_token' => $token,
            'name' => 'Oldiani',
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        $body = $this->body($this->section($area));
        self::assertStringContainsString('Oldiani', $body);
        self::assertStringContainsString('renamed to', $body);
    }

    public function testRemovingTheWholeSetTakesOnlyTheZones(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $token = $this->tokenOn($this->body($this->section($area)), '/zones/clear');
        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/zones/clear', ['_token' => $token]);

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(0, $this->countZones($area));
        self::assertStringContainsString('1 zone removed', $this->body($this->section($area)));
    }

    public function testRemovingOneZoneLeavesItsGroundUnzoned(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $token = $this->tokenOn($this->body($this->section($area).'?open='.$zone->getUuidString()), '/remove');
        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString().'/remove', [
            '_token' => $token,
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(0, $this->countZones($area));
        self::assertStringContainsString('removed', $this->body($this->section($area)));
    }

    /** A single-feature file goes through the import's own checks. */
    public function testTheRingIsReplacedFromASingleFeatureFile(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $token = $this->tokenOn($this->body($this->section($area).'?open='.$zone->getUuidString()), '/ring');
        $this->browser()->request(
            'POST',
            '/areas/'.$area->getUuidString().'/zones/'.$zone->getUuidString().'/ring',
            ['_token' => $token],
            ['zones' => $this->upload($this->collection([$this->feature('anything', self::A_EAST_HALF_RING)]), 'ring.geojson')],
        );

        self::assertSame(Response::HTTP_FOUND, $this->browser()->getResponse()->getStatusCode());
        $this->em->clear();
        /** @var Zone $stored */
        $stored = $this->em->getRepository(Zone::class)->findOneBy(['area' => $area, 'name' => 'Western Sector']);
        self::assertStringContainsString('-29', (string) $stored->getGeom());
        self::assertStringContainsString('redrawn', $this->body($this->section($area)));
    }

    /** Reading how an area is divided is a lens; changing it is an edit of the area. */
    public function testAViewerWhoMayNotEditIsRefusedEveryWriteAndStillSeesThePage(): void
    {
        $this->boot(self::READ_ONLY_AREA_PERMISSIONS);
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $this->browser()->request('GET', $this->section($area));
        self::assertSame(Response::HTTP_OK, $this->browser()->getResponse()->getStatusCode());

        // The write is refused before the token is even looked at: the
        // permission is the gate, and a viewer never reaches the form.
        $this->browser()->request('POST', '/areas/'.$area->getUuidString().'/zones/clear', ['_token' => 'whatever']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->browser()->getResponse()->getStatusCode());
    }

    /**
     * A ZONE HAS NO PEOPLE OF ITS OWN; it has the ground its stations stand
     * on. The run says how many of each so the card can be read shut.
     */
    public function testAZoneCardRunsItsStationsAndThePeoplePostedInThem(): void
    {
        $this->boot();
        $this->signIn();
        [$area] = $this->aZoneWithAStaffedPost();

        $body = $this->body($this->section($area));

        self::assertMatchesRegularExpression('#<b>1</b> st#', $body);
        self::assertMatchesRegularExpression('#<b>2</b> stationed#', $body);
    }

    /** Nought is stated in words, because "0 st" reads as a measurement. */
    public function testAZoneWithNoStationSaysSoRatherThanCountingNought(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $body = $this->body($this->section($area));

        self::assertStringContainsString('no station', $body);
        self::assertStringContainsString('nobody stationed', $body);
    }

    public function testAnOpenZoneCardDrawsAStationCardForEachPostInIt(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aZoneWithAStaffedPost();

        $body = $this->body($this->section($area).'?open='.$zone->getUuidString());

        self::assertStringContainsString('Eastgate Post', $body);
        self::assertStringContainsString('ST-01', $body);
        self::assertStringContainsString('J. Mollel leads', $body);
        // The card links to the post's own record, which is where people are read.
        self::assertStringContainsString('/stations/', $body);
        // BOUNDED, AND IT SAYS SO: the footer states the whole against the drawn.
        self::assertStringContainsString('1 of 1 station', $body);
    }

    /** A zone with no post in it is an ordinary state, and the card says which. */
    public function testAnOpenZoneWithNoPostSaysSoInsteadOfAnEmptyGrid(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $body = $this->body($this->section($area).'?open='.$zone->getUuidString());

        self::assertStringContainsString('No station stands in this zone', $body);
        self::assertStringNotContainsString('Manage people', $body);
    }

    /**
     * NO MODULE PUBLISHES YET, so the open card draws no figures row at all
     * rather than a row of dashes — the same honesty the station dock keeps.
     */
    public function testAnOpenCardDrawsNoFiguresRowWhileNobodyPublishes(): void
    {
        $this->boot();
        $this->signIn();
        [$area, $zone] = $this->aZoneWithAStaffedPost();

        $body = $this->body($this->section($area).'?open='.$zone->getUuidString());

        self::assertStringNotContainsString('zcfigs', $body);
        self::assertStringNotContainsString('% covered', $body);
    }

    /**
     * ONE ZONE, ONE POST IN IT, TWO PEOPLE ON THE POST — the shape every
     * assertion above reads, built through the verbs so the derived zone is
     * derived rather than asserted into place.
     *
     * @return array{0: AreaOfInterest, 1: Zone}
     */
    private function aZoneWithAStaffedPost(): array
    {
        $area = $this->anArea();
        $zone = $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        /** @var StationService $stations */
        $stations = static::getContainer()->get('test_public.area.stations');
        /** @var PostingService $postings */
        $postings = static::getContainer()->get('test_public.area.postings');

        $station = $stations->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-01');
        $lead = $postings->post($station, $this->aPerson('J.', 'Mollel'), PostingSource::WrittenHere);
        $postings->appointLeader($lead);
        $postings->post($station, $this->aPerson('T.', 'Ndosi'), PostingSource::FromTheirPage);

        return [$area, $zone];
    }

    private function aPerson(string $first, string $last): HostUser
    {
        $person = new HostUser()->named($first, $last);
        $this->em->persist($person);
        $this->em->flush();

        return $person;
    }

    // ---------------------------------------------------------------- fixtures

    private const array A_WEST_HALF_RING = [[[-30.0, -3.6], [-29.5, -3.6], [-29.5, -2.8], [-30.0, -2.8], [-30.0, -3.6]]];
    private const array A_EAST_HALF_RING = [[[-29.5, -3.6], [-29.0, -3.6], [-29.0, -2.8], [-29.5, -2.8], [-29.5, -3.6]]];

    private function section(AreaOfInterest $area): string
    {
        return '/areas/'.$area->getUuidString().'/zones/settings';
    }

    private function body(string $url): string
    {
        $this->browser()->request('GET', $url);

        return (string) $this->browser()->getResponse()->getContent();
    }

    private function preview(AreaOfInterest $area, string $document, string $fileName = 'zones.geojson'): void
    {
        $token = $this->tokenOn($this->body($this->section($area)), '/zones/import/preview');

        $this->browser()->request(
            'POST',
            '/areas/'.$area->getUuidString().'/zones/import/preview',
            ['_token' => $token],
            ['zones' => $this->upload($document, $fileName)],
        );
    }

    private function upload(string $document, string $fileName): UploadedFile
    {
        $directory = sys_get_temp_dir().'/zone-web-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $path = $directory.'/'.$fileName;
        file_put_contents($path, $document);

        return new UploadedFile($path, $fileName, 'application/geo+json', test: true);
    }

    /**
     * THE TOKEN THE PAGE ACTUALLY MINTED, read off the form that carries it.
     *
     * A token is minted into the viewer's session, so a test that asks the
     * manager for one outside a request gets a token from a different session
     * and the write is refused. Reading it off the rendered form is also the
     * only way the assertion covers the seam: the name the template writes and
     * the id the controller checks have to be the same string.
     */
    private function tokenOn(string $body, string $action): string
    {
        self::assertMatchesRegularExpression(
            '#<form[^>]*action="[^"]*'.preg_quote($action, '#').'[^"]*"#',
            $body,
            \sprintf('The page carries no form assignment to "%s".', $action),
        );

        preg_match(
            '#<form[^>]*action="[^"]*'.preg_quote($action, '#').'[^"]*".*?name="_token" value="([^"]+)"#s',
            $body,
            $matches,
        );

        return $matches[1] ?? '';
    }

    private function countZones(AreaOfInterest $area): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(Zone::class)->findBy(['area' => $area]));
    }

    /** @return array<string, mixed> */
    private function feature(string $name, mixed $ring): array
    {
        return ['type' => 'Feature', 'properties' => ['Name' => $name], 'geometry' => ['type' => 'Polygon', 'coordinates' => $ring]];
    }

    /** @param list<array<string, mixed>> $features */
    private function collection(array $features): string
    {
        return (string) json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR);
    }

    /**
     * A NAME THE IMPORTER READ DIFFERENTLY SAYS SO IN THE PREVIEW.
     *
     * A shouted name is title-cased on the way in, and the preview is the one
     * place somebody still has a chance to object to that before anything is
     * written. It is shown rather than stored: a column holding the file's
     * spelling would be a second name nobody reads, and the file itself is
     * not kept.
     */
    public function testThePreviewSaysWhatTheFileSaidWhereTheNameWasRetitled(): void
    {
        $this->boot();
        $this->signIn();
        $area = $this->anArea();

        $this->preview($area, $this->collection([
            $this->feature('WESTERN SECTOR', self::A_WEST_HALF_RING),
            $this->feature('Eastern Sector', self::A_EAST_HALF_RING),
        ]));

        $body = $this->body($this->section($area));

        self::assertStringContainsString('Western Sector', $body);
        self::assertStringContainsString('file said WESTERN SECTOR', $body);
        // And a name nobody changed says nothing at all.
        self::assertStringNotContainsString('file said Eastern Sector', $body);
    }

    private function twoHalves(): string
    {
        return $this->collection([
            $this->feature('Western Sector', self::A_WEST_HALF_RING),
            $this->feature('Eastern Sector', self::A_EAST_HALF_RING),
        ]);
    }
}
