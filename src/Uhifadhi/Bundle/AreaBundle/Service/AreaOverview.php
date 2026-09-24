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
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionItem;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayer;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayerProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTileProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Overview\PulseProviderInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Contracts\Shell\Scope;

/**
 * WHAT AN AREA'S OVERVIEW IS MADE OF — gathered from every module installed in
 * the area, and from nothing else.
 *
 * THE AREA PAGE OWNS THE SURFACE AND WRITES NONE OF THE OPERATIONAL CONTENT. This
 * class asks each contributor for its parts, keeps the ones whose module is
 * actually switched on here, and orders them. It does not know what a patrol is
 * and must not: the day it does, uninstalling a module leaves a hard-coded row
 * behind.
 *
 * ASKED PER AREA, ON EVERY RENDER. Nothing is stored and nothing is cached, so
 * an attention item leaves the list when the thing that raised it is dealt with
 * and nobody dismisses one by hand.
 *
 * ABSENT IS NOT ZERO, AND THAT IS THE WHOLE DISCIPLINE. An empty strip means no
 * installed module had anything to say — it does not mean every count is 0, and
 * the page says the former rather than drawing the latter.
 */
final readonly class AreaOverview
{
    /**
     * @param iterable<NowTileProviderInterface>   $nowTiles
     * @param iterable<AttentionProviderInterface> $attention
     * @param iterable<MapLayerProviderInterface>  $mapLayers
     * @param iterable<PulseProviderInterface>     $pulse
     */
    public function __construct(
        private iterable $nowTiles,
        private iterable $attention,
        private iterable $mapLayers,
        private iterable $pulse,
        private AreaModuleRepository $areaModules,
        /**
         * THE AREAS, so the queue can be read one scope wider. Nothing else
         * here needs them: every other read is handed the area it is about.
         */
        private AreaOfInterestRepository $areas,
    ) {
    }

    /**
     * The right-now strip, in provider priority order then registration order.
     *
     * @return list<NowTile>
     */
    public function nowTilesFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $installed = $this->installedSlugs($area);

        $tiles = [];
        foreach ($this->nowTiles as $provider) {
            if (!\in_array($provider->moduleSlug(), $installed, true)) {
                continue;
            }
            foreach ($provider->nowTilesFor($area, $now) as $tile) {
                $tiles[] = $tile;
            }
        }

        // A stable sort: equal priorities keep the order the providers were
        // asked in, so a strip does not reshuffle itself between renders.
        usort($tiles, static fn (NowTile $a, NowTile $b): int => $a->priority <=> $b->priority);

        return $tiles;
    }

    /**
     * Everything asking for attention, SORTED BY URGENCY AND NEVER BY MODULE —
     * then oldest first inside a severity, because a thing that has been waiting
     * longer is the more embarrassing one.
     *
     * @return list<AttentionItem>
     */
    public function attentionFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $installed = $this->installedSlugs($area);

        $items = [];
        foreach ($this->attention as $provider) {
            if (!\in_array($provider->moduleSlug(), $installed, true)) {
                continue;
            }
            foreach ($provider->attentionFor($area, $now) as $item) {
                $items[] = $item;
            }
        }

        return self::byUrgency($items);
    }

    /**
     * THE SAME QUEUE ONE SCOPE WIDER — the organization, or one area of it.
     *
     * NOT A SECOND AGGREGATE. This walks the very loop {@see attentionFor()}
     * walks, once per area, and sorts the result by the same rule; there is
     * no org-level provider call and no other place an item can come from.
     * The organization's queue IS the areas' queues, which is what lets a
     * test assert it by construction rather than by hoping two derivations
     * agree.
     *
     * SORTED ACROSS AREAS, NEVER GROUPED BY THEM. Somebody reading it is
     * asking "what do I have to do", and a list arranged by where it
     * happened makes them read all of it to find out. Which area an item is
     * in is a fact on the row.
     *
     * @return list<AttentionItem>
     */
    public function attentionForScope(Scope $scope, \DateTimeImmutable $now): array
    {
        if (!$scope->isOrganization()) {
            $area = $this->areas->findOneBy(['uuid' => $scope->areaUuid]);

            return null === $area ? [] : $this->attentionFor($area, $now);
        }

        $items = [];
        foreach ($this->areas->findAllOrdered() as $area) {
            foreach ($this->attentionFor($area, $now) as $item) {
                $items[] = $item;
            }
        }

        return self::byUrgency($items);
    }

    /**
     * URGENCY FIRST, THEN OLDEST — because a thing that has been waiting
     * longer is the more embarrassing one. One rule, so a queue read at one
     * scope orders the same way as a queue read at another.
     *
     * @param list<AttentionItem> $items
     *
     * @return list<AttentionItem>
     */
    private static function byUrgency(array $items): array
    {
        usort($items, static fn (AttentionItem $a, AttentionItem $b): int => [$a->severity->rank(), -$a->ageSeconds] <=> [$b->severity->rank(), -$b->ageSeconds]);

        return $items;
    }

    /**
     * EVERY MODULE LAYER FOR THE AREA PAGE'S ONE PLATE, in provider order then the
     * order each provider lists its own layers — never sorted, because a layer's
     * place in the legend is the module's to decide and a stable order is what
     * lets a person find the same row twice.
     *
     * Asked only where the module is switched on, exactly like the tiles and the
     * attention rows: a layer from a module that is off here is not gathered, so
     * its legend group leaves the plate rather than lingering empty. The area page
     * draws these from their own colour and style and knows nothing of what a
     * patrol track or an incident point IS — the day it does, the registry is broken.
     *
     * @return list<MapLayer>
     */
    public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $installed = $this->installedSlugs($area);

        $layers = [];
        foreach ($this->mapLayers as $provider) {
            if (!\in_array($provider->moduleSlug(), $installed, true)) {
                continue;
            }
            foreach ($provider->mapLayersFor($area, $now) as $layer) {
                $layers[] = $layer;
            }
        }

        return $layers;
    }

    /**
     * WHEN THE AREA WAS LAST TOUCHED — the most recent move any installed module
     * made in it, over the window between `$since` and `$now`, or null where
     * nothing moved.
     *
     * The register card's "last check-in" and its "last activity" sort read this.
     * It is the pulse contribution asked the same way as everything else — only where a
     * module is switched on — so the area page learns WHEN an area last did something
     * without knowing WHAT: the day a module leaves, its moves stop counting
     * toward the area's recency with no edit to the area page. Null is a legitimate answer
     * and the card says "no recent activity" rather than inventing a time.
     */
    public function latestActivityFor(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $installed = $this->installedSlugs($area);

        $latest = null;
        foreach ($this->pulse as $provider) {
            if (!\in_array($provider->moduleSlug(), $installed, true)) {
                continue;
            }
            foreach ($provider->pulseFor($area, $since, $now) as $event) {
                if (null === $latest || $event->at > $latest) {
                    $latest = $event->at;
                }
            }
        }

        return $latest;
    }

    /**
     * The slugs of the modules this area has switched on.
     *
     * READ FROM THE REGISTRY'S LEDGER, never from what happens to be installed in
     * the container: a module present in the application but switched off for
     * THIS area contributes nothing here, which is what makes the per-area
     * switch mean anything.
     *
     * @return list<string>
     */
    public function installedSlugs(AreaOfInterest $area): array
    {
        $slugs = [];
        foreach ($this->areaModules->activeForArea($area) as $areaModule) {
            $slug = $areaModule->getModule()?->getSlug();
            if (\is_string($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
