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

namespace Uhifadhi\Bundle\AreaBundle\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AtlasBundle\Model\Thumbnail;

/**
 * THE REGISTER CARD'S FACE, PER AREA — the boundary framed to its own bounding
 * box, drawn as an outline over a real satellite snippet.
 *
 * A STATIC SATELLITE SNIPPET, THE CHEAP WAY. The graduated design's card face is
 * a satellite snippet framed to the boundary bbox with the boundary outlined over
 * it — deliberately not a live Leaflet per card, so a wall of forty stays cheap.
 * Both halves are real:
 *
 *   - THE BOUNDARY OUTLINE. The stored geometry is simplified in the database (a
 *     card face resolves nothing finer) and projected into the card's viewBox in
 *     {@see Thumbnail}. It is the actual gazetted shape, so it already changes
 *     the day the boundary is replaced.
 *   - THE SATELLITE RASTER GROUND. Not a static-tile stitch and not a cache — the
 *     bbox of the same simplified boundary becomes a keyless Esri World Imagery
 *     export URL ({@see Thumbnail::ESRI_EXPORT}), which the browser renders as
 *     a plain `<img>` on demand. This is the area page's own AreaCardService technique,
 *     ported: no server round-trip, no imagery service needed.
 *
 * A boundary-less area carries neither and falls back to the neutral ground.
 */
final readonly class AreaThumbnailer
{
    /**
     * How hard the boundary is simplified before it becomes a path, in degrees.
     * A register face is ~320px wide over a whole park; a tolerance this coarse
     * drops the vertices that would never resolve while keeping the silhouette,
     * which is what keeps the inline path small on a wall of cards.
     */
    private const float TOLERANCE = 0.01;

    public function __construct(private AreaOfInterestRepository $areas)
    {
    }

    public function forArea(AreaOfInterest $area): Thumbnail
    {
        $id = $area->getId();
        if (!$area->hasBoundary() || null === $id) {
            return Thumbnail::neutral();
        }

        return Thumbnail::fromGeoJson($this->areas->stSimplifiedBoundary($id, self::TOLERANCE));
    }
}
