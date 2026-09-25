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

use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapService;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;

/**
 * THE OVERVIEW PLATE'S GROUND, BYTE FOR BYTE. The area answers what its
 * ground is, from the database; the atlas draws it. What reaches the browser
 * — the legend rows and the layers, in their order — is pinned against a
 * recorded copy, so the boundary row, the zones row and the zones layer come
 * out exactly as the overview has always served them.
 */
final class OverviewPlateGroundTest extends WebTestCase
{
    private const string FIXTURE = __DIR__.'/Fixtures/overview-plate-ground.json';

    public function testTheOverviewPlateServesItsGroundExactlyAsRecorded(): void
    {
        self::assertSame(self::recorded(), $this->served());
    }

    private function served(): string
    {
        $this->boot();
        $area = $this->anArea();
        $this->aZone($area, 'West', self::A_WEST_HALF);
        $this->aZone($area, 'East', self::AN_EAST_HALF);

        /** @var AreaMapPayload $payload */
        $payload = static::getContainer()->get('test_public.area.map_payload');
        /** @var AreaMapService $plates */
        $plates = static::getContainer()->get('test_public.area.map');

        $map = $plates->overview(
            $payload->forArea($area),
            new FakeMapLayers('patrols', 'Patrols')->mapLayersFor($area, new \DateTimeImmutable('2026-09-01 10:00')),
        );

        return json_encode([
            'legend' => array_map(static fn (LegendItem $row): array => [
                'label' => $row->label,
                'swatch' => $row->swatch,
                'shape' => $row->shape->value,
                'group' => $row->group,
                'count' => $row->count,
                'layerId' => $row->layerId,
                'visible' => $row->visible,
            ], $map->legend()),
            'layers' => $map->toArray()['layers'],
            'boundary' => $map->toArray()['boundary'],
            'subject' => $map->toArray()['subject'],
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }

    private static function recorded(): string
    {
        $recorded = file_get_contents(self::FIXTURE);
        self::assertIsString($recorded);

        return $recorded;
    }
}
