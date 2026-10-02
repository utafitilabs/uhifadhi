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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneImportPlan;
use Uhifadhi\Bundle\AreaBundle\Model\ZoneSetView;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsResolver;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * WHAT A ZONE MAY DO THAT IT COULD NOT BEFORE, AND WHAT IT STILL MAY NOT.
 *
 * A ZONE MAY EXTEND BEYOND THE AREA BOUNDARY. A gazetted edge and an
 * operational subdivision are drawn by different people from different
 * sources, and a patrol sector that runs a kilometre past the line is a fact
 * about the ground rather than a mistake in the file. So containment is no
 * longer a refusal: the feature arrives, and the row SAYS how far past the
 * line it goes, because somebody should know.
 *
 * NOTHING READS OVER A HUNDRED PERCENT. Once a zone may lie outside, "zoned of
 * area" is the wrong fraction — so the denominator is the BOUNDARY AND THE
 * ZONES TOGETHER, the whole of the ground this area accounts for.
 *
 * SHARING INTERIOR IS STILL A REFUSAL, with the area's own tolerance for the
 * slivers two hand-drawn rings leave between them.
 */
#[CoversClass(ZoneImportService::class)]
#[CoversClass(ZoneSetService::class)]
final class ZoneToleranceTest extends IntegrationTestCase
{
    // ------------------------------------------- a zone may cross the line

    public function testAFeaturePartlyOutsideTheBoundaryArrivesAndSaysHowFar(): void
    {
        $plan = $this->plan($this->anArea(), $this->collection([
            $this->feature('Border Sector', self::A_RING_ACROSS_THE_EDGE),
        ]));

        self::assertSame(['Border Sector'], $plan->arrivingNames());
        self::assertMatchesRegularExpression(
            '/^extends [\d,]+ km² beyond the boundary$/u',
            $plan->features[0]->note,
        );
    }

    /** A ring that lies wholly outside is still a zone: the area says where it is, not what it may cover. */
    public function testAFeatureWhollyOutsideTheBoundaryArrives(): void
    {
        $plan = $this->plan($this->anArea(), $this->collection([
            $this->feature('Elsewhere', self::A_FAR_AWAY_RING),
        ]));

        self::assertSame(['Elsewhere'], $plan->arrivingNames());
        self::assertStringContainsString('beyond the boundary', $plan->features[0]->note);
    }

    /** A ring rounded off the boundary's own edge says nothing: that is arithmetic, not ground. */
    public function testARingRoundedOffTheBoundarySaysNothing(): void
    {
        $plan = $this->plan($this->anArea(), $this->collection([
            $this->feature('The whole area', [[
                [-30.000000001, -3.600000001], [-29.0, -3.6], [-29.0, -2.8], [-30.0, -2.800000001], [-30.000000001, -3.600000001],
            ]]),
        ]));

        self::assertSame('', $plan->features[0]->note);
    }

    public function testAnAreaWithNoBoundaryTakesZonesWithNothingToSayAboutThem(): void
    {
        $area = new AreaOfInterest()->setName('Unmapped Reserve')->setSource('drawn');
        $this->em->persist($area);
        $this->em->flush();

        $plan = $this->plan($area, $this->collection([$this->feature('Western Sector', self::A_WEST_HALF_RING)]));

        self::assertSame(['Western Sector'], $plan->arrivingNames());
        self::assertSame('', $plan->features[0]->note);
    }

    // ------------------------------------------------- coverage is the union

    public function testCoverageIsMeasuredAgainstTheBoundaryAndTheZonesTogether(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $this->importAll($area, $this->collection([$this->feature('Border Sector', self::A_RING_ACROSS_THE_EDGE)]));

        $view = $this->view($area);

        self::assertNotNull($view->groundKm2);
        // The ground is bigger than the boundary alone, because a zone left it.
        self::assertGreaterThan(0, $view->groundKm2);
        self::assertLessThanOrEqual($view->groundKm2, $view->zonedKm2);

        foreach ($view->rows as $row) {
            self::assertNotNull($row->shareOfArea);
            self::assertLessThanOrEqual(100.0, $row->shareOfArea);
        }
    }

    // ------------------------------------------------ slivers and overlaps

    /** 0.4 % of the smaller ring: accepted, both zones stored, neither clipped. */
    public function testASliverIsAcceptedAndBothRingsAreStoredWhole(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);

        $result = $this->importAll($area, $this->collection([
            $this->feature('Eastern Sector', self::A_RING_OVERLAPPING_BY_A_SLIVER),
        ]));

        self::assertSame(['Eastern Sector'], $result->added);
        self::assertCount(2, $this->em->getRepository(Zone::class)->findBy(['area' => $area]));
    }

    /**
     * AND THE SHARED GROUND HAS ONE ANSWER. Neither ring was clipped, so a
     * point inside the sliver is covered by both; the tie-break names one of
     * them and goes on naming the same one.
     */
    public function testAPointInAnAcceptedSliverBelongsToExactlyOneZone(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Western Sector', self::A_WEST_HALF);
        $this->importAll($area, $this->collection([
            $this->feature('Eastern Sector', self::A_RING_OVERLAPPING_BY_A_SLIVER),
        ]));

        /** @var \Uhifadhi\Bundle\AreaBundle\Service\ZoneService $zones */
        $zones = static::getContainer()->get('test_public.area.zones');

        $first = $zones->zoneOf($area, -29.4995, -3.2);
        self::assertNotNull($first);
        self::assertSame($first->getName(), $zones->zoneOf($area, -29.4995, -3.2)?->getName());
    }

    public function testAnOverlapPastTheToleranceIsFlaggedWithItsSize(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Crater', self::A_WEST_HALF);

        $plan = $this->plan($area, $this->collection([
            $this->feature('Crater North', self::A_STRADDLING_RING),
        ]));

        self::assertSame([], $plan->arrivingNames());
        self::assertMatchesRegularExpression('/^overlaps Crater by [\d,]+ km²$/u', $plan->flagged()[0]->why());
    }

    /**
     * The tolerance in force for the area decides: a ring sharing five percent
     * of the smaller zone is an overlap at the default one percent, and is
     * taken once an Admin allows ten for this area in Settings › Core.
     */
    public function testTheToleranceIsTheOneInForceForTheArea(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'Crater', self::A_WEST_HALF);
        $ring = $this->collection([$this->feature('Crater East', self::A_RING_OVERLAPPING_BY_FIVE_PERCENT)]);

        self::assertSame([], $this->plan($area, $ring)->arrivingNames(), 'At one percent, five is an overlap.');

        $this->em->persist(new SettingValue(CoreSettings::ZONE_OVERLAP_TOLERANCE, SettingDepth::Area, (string) $area->getUuidString(), 10.0, 'admin@example.test', new \DateTimeImmutable()));
        $this->em->flush();
        $this->settingsReader()->reset();

        self::assertSame(['Crater East'], $this->plan($area, $ring)->arrivingNames(), 'At ten percent, it is taken.');
    }

    /** Two features of one file are held to the same rule, and the reason says the size. */
    public function testTwoFeaturesOfOneFileThatOverlapPastTheToleranceFlagTheSecond(): void
    {
        $plan = $this->plan($this->anArea(), $this->collection([
            $this->feature('Western Sector', self::A_WEST_HALF_RING),
            $this->feature('Straddler', self::A_STRADDLING_RING),
        ]));

        self::assertSame(['Western Sector'], $plan->arrivingNames());
        self::assertMatchesRegularExpression(
            '/^overlaps Western Sector in this file by [\d,]+ km²$/u',
            $plan->flagged()[0]->why(),
        );
    }

    // ---------------------------------------------------------------- fixtures

    private const array A_WEST_HALF_RING = [[[-30.0, -3.6], [-29.5, -3.6], [-29.5, -2.8], [-30.0, -2.8], [-30.0, -3.6]]];
    private const array A_STRADDLING_RING = [[[-29.75, -3.6], [-29.25, -3.6], [-29.25, -2.8], [-29.75, -2.8], [-29.75, -3.6]]];
    private const array A_FAR_AWAY_RING = [[[10.0, 10.0], [11.0, 10.0], [11.0, 11.0], [10.0, 11.0], [10.0, 10.0]]];

    /** The eastern half pushed a quarter-degree past the area's eastern edge. */
    private const array A_RING_ACROSS_THE_EDGE = [[[-29.5, -3.6], [-28.75, -3.6], [-28.75, -2.8], [-29.5, -2.8], [-29.5, -3.6]]];

    /** The eastern half reaching one ten-thousandth of a degree into the western one. */
    /** Five percent of the west half's ground: 0.025° of its 0.5° width, the full height. */
    private const array A_RING_OVERLAPPING_BY_FIVE_PERCENT = [[[-29.525, -3.6], [-29.0, -3.6], [-29.0, -2.8], [-29.525, -2.8], [-29.525, -3.6]]];

    private const array A_RING_OVERLAPPING_BY_A_SLIVER = [[[-29.5001, -3.6], [-29.0, -3.6], [-29.0, -2.8], [-29.5001, -2.8], [-29.5001, -3.6]]];

    private function importer(): ZoneImportService
    {
        /** @var ZoneImportService $service */
        $service = static::getContainer()->get('test_public.area.zone_import');

        return $service;
    }

    private function view(AreaOfInterest $area): ZoneSetView
    {
        /** @var ZoneSetService $set */
        $set = static::getContainer()->get('test_public.area.zone_set');

        return $set->view($area);
    }

    private function plan(AreaOfInterest $area, string $document): ZoneImportPlan
    {
        $directory = sys_get_temp_dir().'/zone-tolerance-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $path = $directory.'/zones.geojson';
        file_put_contents($path, $document);

        return $this->importer()->plan($area, new File($path), 'zones.geojson');
    }

    private function importAll(AreaOfInterest $area, string $document): \Uhifadhi\Bundle\AreaBundle\Model\ZoneImportResult
    {
        $plan = $this->plan($area, $document);

        return $this->importer()->apply($area, $plan, $plan->arrivingNames());
    }

    private function settingsReader(): SettingsResolver
    {
        $reader = static::getContainer()->get(SettingsReaderInterface::class);
        \assert($reader instanceof SettingsResolver);

        return $reader;
    }

    /**
     * @param list<list<list<float|int>>> $ring
     *
     * @return array<string, mixed>
     */
    private function feature(string $name, array $ring): array
    {
        return ['type' => 'Feature', 'properties' => ['Name' => $name], 'geometry' => ['type' => 'Polygon', 'coordinates' => $ring]];
    }

    /** @param list<array<string, mixed>> $features */
    private function collection(array $features): string
    {
        return (string) json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR);
    }
}
