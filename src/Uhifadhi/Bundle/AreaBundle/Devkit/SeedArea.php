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

/**
 * THE SEED GROUND, WRITTEN ONCE AND READ BY THREE PROVIDERS — the reserves of
 * the invented organization every seeded installation and every manual shows,
 * the zoning scheme that subdivides each of them, and the posts standing on it.
 *
 * ONE PLACE BECAUSE IT IS ONE MAP. A reserve's boundary, its zones and its
 * stations are three tables in the database and one drawing on the screen: a
 * zone outside the boundary or a post on ground no zone covers is a defect
 * somebody sees rather than a number somebody reads. The boundary and the zones
 * are files drawn once and fixed (`ground/<reserve>/`); the posts stand on them,
 * listed here beside their reserve.
 *
 * REAL TERRAIN, INVENTED NAMES. The reserves are drawn over real country so
 * every map looks like the ground a protected-area authority actually manages,
 * and every name on them — the organization, the reserves, the zones, the
 * posts, the landmarks in `ground/landmarks.geojson` — is invented. Nothing
 * here names the real place, and nothing may: seed content that named one
 * would be a claim about it the moment somebody screenshotted it.
 *
 * FIXED FOR GOOD. The manuals' screenshots are taken on this ground; a boundary
 * that moved would make every earlier picture a different place.
 *
 * NOTHING HERE IS PERSISTED AND NOTHING HERE DECIDES ANYTHING. It is a static
 * table the dev-only providers read; every row it describes is written through
 * the bundle's own services, which are what enforce the rules the geometry has
 * to satisfy.
 */
final readonly class SeedArea
{
    /**
     * @param list<SeedStation> $posts
     */
    private function __construct(
        public string $name,
        public string $iucnCategory,
        public int $establishedYear,
        private string $ground,
        private array $posts,
    ) {
    }

    /**
     * THE SEED REGISTER: more than one reserve, because every screen that lists
     * areas, scopes to one, or compares two is wrong in a way a single area
     * hides — and the two are different kinds of ground, a reserve of plains
     * and hills and one of swamps and a dry lake, so no screen can pass by
     * drawing the same picture twice.
     *
     * A PARK'S POSTS ARE NOT THE SAME SIZE. A main gate holds five people and a
     * roadside marker holds nobody. A seed that gave every post the same two
     * made every screen that ranks, groups or compares posts draw a flat line —
     * a duty board with nothing to read, which is exactly the screen somebody
     * opens the seed to judge. So each reserve has one post of five, the rest
     * between four and nothing, and ONE EMPTY POST: "nobody works out of here"
     * is a state every register, board and plate has to draw.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self('Kilimani Game Reserve', 'IV', 1978, 'kilimani-game-reserve', [
                new SeedStation('Eastgate Post', 'ST-01', 37.04, -2.90, 1480, 'Eastern gate', posted: 5, catchmentM: 1500),
                new SeedStation('Fig Tree Ranger Post', 'ST-02', 36.78, -3.09, 1430, 'Forest edge', posted: 4, catchmentM: 1500),
                new SeedStation('Ridge Outpost', 'ST-03', 36.42, -2.82, 1640, 'Hill ridge', posted: 2, catchmentM: 1000),
                new SeedStation('Escarpment Post', 'ST-04', 36.01, -2.97, 1290, 'Escarpment road', posted: 3, catchmentM: 1500),
                new SeedStation('Lava Plains Camp', 'ST-05', 36.95, -3.08, 1350, 'Lava plains', posted: 3, catchmentM: 1500),
                new SeedStation('Corridor Post', 'ST-06', 36.96, -2.79, 1250, 'Elephant corridor', posted: 2, catchmentM: 1000),
                new SeedStation('Northern Plains Post', 'ST-07', 36.84, -2.95, 1300, 'Northern plains', posted: 3, catchmentM: 1000),
                new SeedStation('Foothills Post', 'ST-08', 36.62, -3.08, 1400, 'Southern foothills', posted: 0, catchmentM: 800),
                new SeedStation('Western Plains Camp', 'ST-09', 36.28, -2.58, 920, 'Western plains', posted: 4, catchmentM: 1500),
                new SeedStation('Dry Flats Post', 'ST-10', 36.38, -3.05, 1210, 'Dry flats', posted: 2, catchmentM: 1000),
            ]),
            new self('Tambarare Game Reserve', 'IV', 1994, 'tambarare-game-reserve', [
                new SeedStation('Plains Camp', 'ST-11', 36.95, -2.38, 1250, 'Open plains', posted: 5, catchmentM: 1500),
                new SeedStation('Swamp Edge Post', 'ST-12', 37.30, -2.68, 1150, 'Swamp edge', posted: 3, catchmentM: 1000),
                new SeedStation('Woodland Post', 'ST-13', 36.62, -2.27, 1350, 'Acacia woodland', posted: 2, catchmentM: 1000),
                new SeedStation('Eastern Ridge Post', 'ST-14', 37.56, -2.47, 1400, 'Eastern ridges', posted: 3, catchmentM: 1500),
                new SeedStation('Sand River Post', 'ST-15', 37.20, -2.23, 1300, 'Sand river crossing', posted: 0, catchmentM: 800),
                new SeedStation('Lakebed Post', 'ST-16', 37.06, -2.60, 1140, 'Dry lakebed', posted: 2, catchmentM: 1000),
            ]),
        ];
    }

    /**
     * The boundary as the column takes it: a MultiPolygon, read from the
     * reserve's fixed file.
     */
    public function boundary(): string
    {
        $boundary = self::read($this->ground.'/boundary.geojson');
        $features = $boundary['features'] ?? null;
        $first = \is_array($features) ? ($features[0] ?? null) : null;
        $geometry = \is_array($first) ? ($first['geometry'] ?? null) : null;
        if (!\is_array($geometry) || !isset($geometry['type'], $geometry['coordinates'])) {
            throw new \LogicException(\sprintf('The seed ground "%s" has no boundary geometry.', $this->ground));
        }

        return self::encode('MultiPolygon' === $geometry['type'] ? $geometry : [
            'type' => 'MultiPolygon',
            'coordinates' => [$geometry['coordinates']],
        ]);
    }

    /**
     * THE WHOLE SCHEME AS ONE FEATURECOLLECTION, one feature per zone — the
     * shape a desktop GIS exports and the only shape the import reads, so the
     * seed arrives the way a real scheme does rather than through a back door.
     */
    public function zoneScheme(): string
    {
        return self::encode(self::read($this->ground.'/zones.geojson'));
    }

    /**
     * The reserve's posts.
     *
     * @return list<SeedStation>
     */
    public function stations(): array
    {
        return $this->posts;
    }

    /** How many people the whole seed ground asks for, counted from the table. */
    public static function headcount(): int
    {
        $people = 0;
        foreach (self::all() as $area) {
            foreach ($area->stations() as $post) {
                $people += $post->posted;
            }
        }

        return $people;
    }

    /** @return array{0: float, 1: float} longitude then latitude, as everything here is */
    public function pointOf(SeedStation $station): array
    {
        return [$station->longitude, $station->latitude];
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/ground/'.$file), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($document)) {
            throw new \LogicException(\sprintf('The seed ground file "%s" is not a GeoJSON document.', $file));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /** @param array<array-key, mixed> $document */
    private static function encode(array $document): string
    {
        return (string) json_encode($document, \JSON_THROW_ON_ERROR);
    }
}
