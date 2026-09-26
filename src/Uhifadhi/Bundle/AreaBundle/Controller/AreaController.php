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

namespace Uhifadhi\Bundle\AreaBundle\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionItem;
use Uhifadhi\Bundle\AreaBundle\Overview\AttentionSeverity;
use Uhifadhi\Bundle\AreaBundle\Overview\MapLayer;
use Uhifadhi\Bundle\AreaBundle\Overview\NowTile;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaComposition;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapService;
use Uhifadhi\Bundle\AreaBundle\Service\AreaOverview;
use Uhifadhi\Bundle\AreaBundle\Service\AreaOverviewCatalogue;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPresetLibrary;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceStreamService;
use Uhifadhi\Bundle\AreaBundle\Widget\AreaIndexWidgets;
use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Entity\UserInterface as ModuleUserInterface;

/**
 * THE AREA SCREENS — the register, one area's overview, and its settings.
 *
 * A PLAIN CLASS, extending nothing, with its collaborators handed to it: the
 * reusable-bundle rule, because a bundle installed by other projects must not
 * reach into a container through a base class. See config/services.php.
 *
 * GATED ON CONCERN AND VERB — `areas.read` to open a screen, `areas.configure`
 * to change what it shows — and on nothing else. The pairs are declared by
 * {@see \Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns} and answered at runtime
 * by whichever module an installation trusts with grants; this bundle names them
 * and does not depend on that module, which is what lets an installation swap
 * the answering one without touching a screen.
 *
 * ADDRESSED BY UUID. Every route takes `{uuid}` with the uuid requirement, so
 * `/areas/2` is a 404 rather than a sequential key anybody can walk.
 */
final readonly class AreaController
{
    /**
     * HOW MANY OF THE ATTENTION LIST THE OVERVIEW'S CARD DRAWS before it
     * stops and states the count. A card bounded by a number the template
     * chose would be a number nobody could find; it is the page's, here.
     */
    public const int ATTENTION_SHOWN = 6;

    public function __construct(
        private Environment $twig,
        private ZoneRepository $zones,
        private StationRepository $stations,
        private AreaRegister $register,
        private AreaOverview $overview,
        private AreaMapPayload $mapPayload,
        private AreaMapService $areaMap,
        private AreaPresetLibrary $library,
        private AreaOverviewCatalogue $catalogue,
        private AreaComposition $composition,
        private ModuleCatalogue $modules,
        private WidgetService $widgets,
        private TokenStorageInterface $tokens,
        /** The leave to watch the live marks move: the plate's stream and the cookie. */
        private PresenceStreamService $streams,
        /** The marks the plate draws on load, narrowed to what the viewer may see. */
        private ?PresenceService $presence = null,
    ) {
    }

    /**
     * THE REGISTER — every area this installation manages.
     *
     * Not gated on `areas.configure`: reading which areas exist is for anybody
     * who may see an area at all, and the create affordance inside the page is
     * what carries the stricter verb.
     *
     * IT IS A WIDGET SURFACE, and its five layouts are alternatives rather than
     * additions, so the page draws the ONE the person adopted in the library —
     * read back through the widget framework from the same stored row the library
     * wrote. A layout adopted on one screen and ignored on the other is the whole
     * defect this resolve() call exists to close.
     */
    #[Route('/areas', name: 'area_index', methods: ['GET'])]
    #[IsGranted('areas.read')]
    public function index(): Response
    {
        $catalog = new AreaIndexWidgets()->catalog();

        return new Response($this->twig->render('@Area/area/index.html.twig', [
            'view' => self::adopted($this->widgets->resolve($catalog, $this->signedIn())),
            // Handed to the register once, so every figure on the landing is
            // measured against the same clock: two areas' "6 min ago" then mean
            // the same thing.
            ...$this->library->landing(new \DateTimeImmutable()),
        ]));
    }

    /**
     * ONE AREA'S OVERVIEW. The widgets on it are not written here — every
     * operational one arrives from a module installed in this area, through the
     * contribution contracts in src/Overview. What this action owns is the area's identity and
     * the honest-absent state for everything nobody contributed.
     */
    #[Route('/areas/{uuid}', name: 'area_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        // Handed in once rather than read per widget, so every card on the page
        // is measured at the SAME moment: two counts taken a second apart is how
        // a strip comes to disagree with the list under it.
        $now = new \DateTimeImmutable();
        $mapLayers = $this->overview->mapLayersFor($area, $now);

        /*
         * THE SURFACE IS COMPOSED, and this is where the composing happens:
         * the catalogue this AREA has — its own cells plus one contributor
         * per module switched on here — resolved against whatever layout the
         * person adopted. The template names no module and renders no widget
         * markup; each cell is drawn from its own contributor's partial.
         */
        $catalog = $this->catalogue->for($area);
        $cells = array_values(array_filter(
            $this->widgets->resolve($catalog, $this->signedIn(), $area->getUuid()),
            static fn (array $cell): bool => $cell['on'],
        ));

        /*
         * ONE MAP FOR EVERY CELL, AND THE CONTRACT'S OWN SHAPE. A
         * contributed partial is rendered with `with_context: false` and
         * reads its own figures under `by.<slug>`; the host's own cells read
         * the page's facts from the same map. So the map is assembled here,
         * once, and handed to every cell alike — the page cannot then give
         * a module something it did not ask for, or withhold what it did.
         */
        /*
         * THE LIVE MARKS KEEP MOVING. The plate is handed the hub and this
         * area's topic, and the response carries the subscriber cookie for
         * it — both under the pair this page enforces, both null where the
         * deployment has no hub. A module's cell on this page that draws
         * the same area's marks rides on the same cookie.
         */
        $subscription = $this->streams->forArea($request, $area);
        $plate = $this->areaMap->overview(
            $this->mapPayload->forArea($area),
            $mapLayers,
            $subscription?->stream,
            $this->presence?->liveIn((string) $area->getUuidString(), $now),
        );
        $tiles = $this->overview->nowTilesFor($area, $now);
        $attention = $this->overview->attentionFor($area, $now);

        /*
         * THE SHARED HALF OF THE CONTRACT, in the names the contract
         * publishes: `area`, `now`, `tiles`, `attention`, `layers` and
         * `legend`, alongside each module's own reading under `by.<slug>`.
         * A module template is written against those names, so the host
         * supplies all of them and not merely the ones today's modules
         * happen to read.
         */
        $cellContext = [
            'area' => $area,
            'by' => $this->catalogue->contextFor($area, $now),
            'now' => $now,
            'tiles' => $tiles,
            'layers' => $mapLayers,
            'legend' => $plate->legend(),
            'areaKm2' => $this->register->areaKm2($area),
            'zoneCount' => $this->zones->countFor($area),
            // WHAT STANDS ON THE GROUND AND WHERE THE GROUND IS — the area's
            // own two facts the band was missing.
            'stationCount' => $this->stations->countByArea($area),
            'centroid' => $this->register->centroid($area),
            'map' => $plate,
            'nowTiles' => $tiles,
            'attention' => $attention,
            // WHAT THE ATTENTION COUNT IS MADE OF — how many are urgent, and
            // how many each module raised. A count with no breakdown says
            // "six things somewhere"; the caption is the only place a reader
            // learns whether it is six of one module's or one each.
            'attentionSummary' => self::summarise($attention),
            'installedSlugs' => $this->overview->installedSlugs($area),
            'moduleCards' => $this->composition->moduleLinksFor($area),
            // WHAT EACH MODULE ACTUALLY CONTRIBUTES HERE, and since when —
            // the modules card is a table of that, not a list of names.
            'moduleTable' => $this->contributions($area, $tiles, $attention, $mapLayers),
            'catalogueCount' => $this->modules->count(),
            // HOW MANY OF A BOUNDED LIST A CARD DRAWS.
            'latest' => self::ATTENTION_SHOWN,
        ];

        $response = new Response($this->twig->render('@Area/area/overview.html.twig', [
            ...$cellContext,
            'cells' => $cells,
            'cellContext' => $cellContext,
            'partials' => $this->catalogue->partialsFor($area),
            // WHAT THE CONTRIBUTED CELLS ARE DRESSED IN — each contributing
            // module's own sheet, linked after this bundle's.
            'moduleStylesheets' => $this->catalogue->stylesheetsFor($area),
        ]));
        if (null !== $subscription) {
            $response->headers->setCookie($subscription->cookie);
        }

        return $response;
    }

    /**
     * WHAT EVERY MODULE OF THE CATALOGUE CONTRIBUTES TO THIS AREA'S PAGE.
     *
     * A NAME AND A LINK SAID NOTHING. "Patrols · Open →" is true of a module
     * an area switched on this morning and of one that has published nothing
     * for a year; what a reader is deciding is whether the module is earning
     * its place here, so the row states what it puts on this page — its
     * cells, its tiles, its attention items, its layers — and since when.
     *
     * THE WHOLE CATALOGUE IS LISTED, the area's own first. "Three modules"
     * means nothing without "of nine", and a module that is not installed
     * here is a row that says so rather than a row that is missing.
     *
     * @param list<NowTile>       $tiles
     * @param list<AttentionItem> $attention
     * @param list<MapLayer>      $layers
     *
     * @return list<array{slug: string, name: string, installed: bool, swatch: ?string, parts: list<string>, since: ?\DateTimeImmutable, url: ?string}>
     */
    private function contributions(AreaOfInterest $area, array $tiles, array $attention, array $layers): array
    {
        $widgets = $this->catalogue->widgetCountsFor($area);
        $installedOrder = $this->overview->installedSlugs($area);

        $swatches = [];
        foreach ($layers as $layer) {
            $swatches[$layer->moduleSlug] ??= $layer->swatch;
        }

        $since = [];
        foreach ($this->composition->installedAtBySlug($area) as $slug => $at) {
            $since[$slug] = $at;
        }

        $urls = [];
        foreach ($this->composition->moduleLinksFor($area) as $card) {
            $urls[$card->slug] = $card->url;
        }

        $rows = [];
        foreach ($this->modules->all() as $module) {
            $slug = (string) $module->getSlug();
            $installed = \in_array($slug, $installedOrder, true);

            $parts = [];
            if ($installed) {
                foreach ([
                    'widget' => $widgets[$slug] ?? 0,
                    'now-tile' => self::published($tiles, $slug),
                    'attention item' => self::published($attention, $slug),
                    'map layer' => self::published($layers, $slug),
                ] as $noun => $count) {
                    if ($count > 0) {
                        $parts[] = \sprintf('%d %s%s', $count, $noun, 1 === $count ? '' : 's');
                    }
                }
            }

            $rows[] = [
                'slug' => $slug,
                'name' => (string) $module->getName(),
                'installed' => $installed,
                'swatch' => $swatches[$slug] ?? null,
                'parts' => $parts,
                'since' => $since[$slug] ?? null,
                'url' => $urls[$slug] ?? null,
            ];
        }

        // THE AREA'S OWN FIRST, in the order the area runs them, then the rest
        // of the catalogue in the catalogue's own order.
        usort($rows, static function (array $a, array $b) use ($installedOrder): int {
            $rank = static fn (array $row): int => $row['installed']
                ? (int) array_search($row['slug'], $installedOrder, true)
                : \PHP_INT_MAX;

            return $rank($a) <=> $rank($b);
        });

        return $rows;
    }

    /**
     * HOW MANY OF ONE MODULE'S THINGS ARE IN A LIST. Every contributed value
     * object names the module that published it, which is the one property
     * all three of these lists share.
     *
     * @param list<NowTile|AttentionItem|MapLayer> $items
     */
    private static function published(array $items, string $slug): int
    {
        $n = 0;
        foreach ($items as $item) {
            if ($item->moduleSlug === $slug) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * THE ATTENTION COUNT, BROKEN DOWN — the urgent ones, then one count per
     * module, in the module's own word for its items.
     *
     * @param list<AttentionItem> $attention
     *
     * @return array{urgent: int, byModule: array<string, int>}
     */
    private static function summarise(array $attention): array
    {
        $urgent = 0;
        $byModule = [];
        foreach ($attention as $item) {
            if (AttentionSeverity::Now === $item->severity) {
                ++$urgent;
            }

            $label = $item->moduleLabel;
            $byModule[$label] = ($byModule[$label] ?? 0) + 1;
        }

        return ['urgent' => $urgent, 'byModule' => $byModule];
    }

    /**
     * WHICH OF THE FIVE LAYOUTS IS ON, read off a resolved layout. They are
     * alternatives, so the first one switched on is the answer; a stored row that
     * somehow has none on falls back to the layout this surface ships, because a
     * register that drew nothing would read as a page that failed to load.
     *
     * @param list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}> $resolved
     */
    private static function adopted(array $resolved): string
    {
        foreach ($resolved as $widget) {
            if ($widget['on']) {
                return $widget['id'];
            }
        }

        return AreaIndexWidgets::DEFAULT_PRESET;
    }

    /**
     * The signed-in person as the CONTRACT sees them, which is what the widget
     * framework keeps a layout against — it never type-hints an installation's
     * account class, and this call site is not where that would start.
     *
     * Null is a real answer rather than a guard: the framework hands an anonymous
     * request the catalogue's own layout, which is exactly right for a register
     * nobody is signed in to.
     */
    private function signedIn(): ?ModuleUserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ModuleUserInterface ? $user : null;
    }
}
