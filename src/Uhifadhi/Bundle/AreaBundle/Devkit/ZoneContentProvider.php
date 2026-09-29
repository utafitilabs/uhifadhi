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

namespace Uhifadhi\Bundle\AreaBundle\Devkit;

use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * EVERY SEEDED RESERVE GETS ITS ZONING SCHEME — the reserve's fixed zones,
 * which tile the ground its boundary encloses: eleven in one reserve, six in
 * the other, so no screen can pass by drawing the same count twice.
 *
 * IT ARRIVES AS A FILE, THROUGH THE IMPORT. A zoning scheme has exactly one
 * supported way in — one GeoJSON FeatureCollection, one feature per zone,
 * through {@see ZoneImportService} — and seed content that wrote the zones
 * straight through the zone service would be seed content exempt from the
 * checks every real scheme passes: the names, the boundary, the invariant that
 * two zones share no interior. So the table is written out as the file it
 * would have been exported as, imported, and let go.
 *
 * THE FILE IS TEMPORARY AND THE PROVENANCE IS NOT. What the import keeps is a
 * row saying a scheme arrived and under what name; the document behind it is
 * deleted here as it is discarded everywhere else.
 *
 * NOBODY IS RECORDED AS THE IMPORTER, for the reason provenance exists:
 * a person is named where one is known, and a seeder is not one.
 *
 * IT SEEDS ONCE, PER AREA. An area that already has zones is left exactly as it
 * is — an import ADDS, so a second run would otherwise be the moment a
 * developer's own zone found itself beside a seeded one.
 */
final readonly class ZoneContentProvider implements ContentProviderInterface
{
    /** What the provenance row is named after, since no real document was uploaded. */
    private const string FILE_NAME = 'seed-zoning-scheme.geojson';

    public function __construct(
        private AreaOfInterestRepository $register,
        private ZoneRepository $zones,
        private ZoneImportService $import,
    ) {
    }

    public function key(): string
    {
        return 'zone';
    }

    public function label(): string
    {
        return 'Zones';
    }

    public function description(): string
    {
        return 'The zoning scheme of every seeded reserve, imported as one FeatureCollection.';
    }

    public function dependsOn(): array
    {
        return ['area'];
    }

    public function load(): void
    {
        foreach (SeedArea::all() as $seed) {
            $area = $this->register->findOneBy(['name' => $seed->name]);

            if (null === $area || $this->zones->countFor($area) > 0) {
                continue;
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'uhifadhi-seed-zones');
            file_put_contents($path, $seed->zoneScheme());

            try {
                // THE IMPORT WRITES ITS OWN LOG LINE. Nothing here says so
                // twice: the verb is what knows the scheme arrived, so an
                // area seeded this way reads exactly as one imported from the
                // screen does.
                $this->import->importInto($area, new File($path), self::FILE_NAME);
            } finally {
                unlink($path);
            }
        }
    }
}
