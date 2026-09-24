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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\AreaBundle\Devkit\AreaContentProvider;
use Uhifadhi\Bundle\AreaBundle\Devkit\StationContentProvider;
use Uhifadhi\Bundle\AreaBundle\Devkit\ZoneContentProvider;
use Uhifadhi\Bundle\AreaBundle\Overview\OverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\People\AreaPersonPostings;
use Uhifadhi\Bundle\AreaBundle\People\AreaStationDirectory;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInCorrectionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInStatusRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationEventRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneEventRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaComposition;
use Uhifadhi\Bundle\AreaBundle\Service\AreaCreator;
use Uhifadhi\Bundle\AreaBundle\Service\AreaIdentity;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapPayload;
use Uhifadhi\Bundle\AreaBundle\Service\AreaMapService;
use Uhifadhi\Bundle\AreaBundle\Service\AreaOverview;
use Uhifadhi\Bundle\AreaBundle\Service\AreaOverviewCatalogue;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPresetLibrary;
use Uhifadhi\Bundle\AreaBundle\Service\AreaRegister;
use Uhifadhi\Bundle\AreaBundle\Service\AreaThumbnailer;
use Uhifadhi\Bundle\AreaBundle\Service\BoundaryImport;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\ModuleSettingsDoors;
use Uhifadhi\Bundle\AreaBundle\Service\PersonDirectoryService;
use Uhifadhi\Bundle\AreaBundle\Service\PersonFacetService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingBoardService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceService;
use Uhifadhi\Bundle\AreaBundle\Service\StationEventService;
use Uhifadhi\Bundle\AreaBundle\Service\StationFigureService;
use Uhifadhi\Bundle\AreaBundle\Service\StationRegisterService;
use Uhifadhi\Bundle\AreaBundle\Service\StationSectionService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneEventService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneExportService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneFigureService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneImportService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneListService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneOverlapService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneStationService;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaFigure;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaModuleMatrix;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetup;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetupCheck;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSetupDecision;
use Uhifadhi\Bundle\AreaBundle\Settings\AreaSteps;
use Uhifadhi\Bundle\AreaBundle\Widget\AreaIndexWidgets;
use Uhifadhi\Bundle\AreaBundle\Widget\AreaOverviewWidgets;
use Uhifadhi\Bundle\AtlasBundle\Map\MapBuilderInterface;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Contracts\Area\StationDirectoryInterface;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\People\PersonDirectoryProviderInterface;
use Uhifadhi\Contracts\People\PersonFacetProviderInterface;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Contracts\Settings\ModuleMatrixSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsCheckSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsDecisionSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * an installation, and FQCN references stay refactor-safe and phpstan-checked.
 * Imported by AreaBundle::loadExtension().
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure() —
 * because this bundle is installed by other projects via Composer, which is what
 * Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * The repository keeps its FQCN id — the one place the bundle-alias prefix
     * cannot be used: ServiceRepositoryCompilerPass keys its locator by SERVICE
     * ID over findTaggedServiceIds(), while ContainerRepositoryFactory looks a
     * repository up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    $services->set(AreaOfInterestRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * WHAT RUNS WHERE, AND HOW MANY AREAS THERE ARE — the settings section's
     * two area-shaped answers.
     *
     * THE MATRIX IS AN ALIAS, NOT A TAGGED CONTRIBUTION. There is one answer
     * to "which modules run in which areas", and two things claiming to know
     * it would be a disagreement with nothing to settle it — so the section
     * looks the implementation up by the id the contract publishes, and an
     * installation with no areas bundle simply has no matrix.
     *
     * THE FIGURE READS THE MATRIX rather than counting areas again: the table
     * and the card are on the same screen, and two counts made a query apart
     * is how one comes to disagree with the other.
     *
     * THE SETUP CHECK IS ONE FACT WITH TWO READINGS — a health row and a
     * queue item — which is why it is one service carrying both tags.
     */
    $services->set('area.settings.module_matrix', AreaModuleMatrix::class)
        ->args([
            service(AreaOfInterestRepository::class),
            service(ZoneRepository::class),
            service('registry.catalogue'),
            service('registry.area_module_ledger'),
        ]);
    $services->alias(ModuleMatrixSourceInterface::SERVICE, 'area.settings.module_matrix');

    $services->set('area.settings.figure', AreaFigure::class)
        ->args([service('area.settings.module_matrix')])
        ->tag(SettingsFigureSourceInterface::TAG);

    /*
     * TWO SOURCES OVER ONE READING. A class cannot implement two contract
     * interfaces that each publish a `TAG` constant — PHP refuses it — and
     * sharing the reading is the better shape anyway: the check and the queue
     * item are the same fact, so they are built from the same answer and the
     * matrix is read once.
     */
    $services->set('area.settings.setup', AreaSetup::class)
        ->args([service('area.settings.module_matrix')]);

    $services->set('area.settings.setup_check', AreaSetupCheck::class)
        ->args([service('area.settings.setup')])
        ->tag(SettingsCheckSourceInterface::TAG);

    $services->set('area.settings.setup_decision', AreaSetupDecision::class)
        ->args([service('area.settings.setup')])
        ->tag(SettingsDecisionSourceInterface::TAG);

    /*
     * THE THREE STEPS THAT ARE ABOUT THE GROUND. They come off the same
     * matrix the tables do, so the checklist and the Installation tab cannot
     * disagree about how much of this installation is set up.
     */
    $services->set('area.settings.steps', AreaSteps::class)
        ->args([service('area.settings.module_matrix'), service('router')])
        ->tag(SettingsStepSourceInterface::TAG);

    $services->set(ZoneRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(ZoneEventRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(StationRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(PostingRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(StationEventRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /* The day, and the pings that prove it — API-CONTRACT.md §13. */
    $services->set(CheckInRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(CheckInStatusRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(CheckInCorrectionRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(PersonPositionRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE ONLY SUPPORTED WAY A ZONE GETS A GEOMETRY. The invariant sibling zones
     * are held to — never sharing interior — is not expressible as a column
     * constraint, so it lives here; anything that writes a zone around this
     * service writes an overlap nobody will notice until a point falls in two
     * zones at once.
     */
    /*
     * THE ZONE LOG, WRITTEN IN ONE VOCABULARY. Every screen and command that
     * changes a set hands its facts here and the sentence is composed once, so
     * two callers cannot log the same event in two different words.
     */
    $services->set('area.zone_events', ZoneEventService::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(ZoneEventService::class, 'area.zone_events');

    /*
     * WHEN SHARED GROUND IS A SLIVER. A stateless rule with no collaborators,
     * so the same sentence decides for an import, for a redrawn ring and for
     * anything that writes a zone later.
     */
    $services->set('area.zone_overlaps', ZoneOverlapService::class);
    $services->alias(ZoneOverlapService::class, 'area.zone_overlaps');

    /*
     * THE ONLY SUPPORTED WAY A STATION GETS A POINT, and therefore a zone: the
     * zone is derived from the point and cached, and every write that can move
     * the answer comes through here. Beside the entity rather than with the
     * screens, because a fixture loader and a console importer place stations
     * too and neither has twig.
     */
    /*
     * A STATION'S LOG, WRITTEN IN ONE VOCABULARY — the station page and the
     * configure section both change stations, and two screens wording one
     * event differently is how a log stops being readable.
     */
    $services->set('area.station_events', StationEventService::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(StationEventService::class, 'area.station_events');

    $services->set('area.stations', StationService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(StationRepository::class),
            service('area.station_events'),
            service('area.postings'),
        ]);
    $services->alias(StationService::class, 'area.stations');

    /*
     * THE ONLY SUPPORTED WAY SOMEBODY IS POSTED, UNPOSTED OR PUT IN CHARGE.
     * One leader per station is a transaction here rather than a database
     * constraint, because the constraint that would express it is a partial
     * unique index the ORM mapping cannot declare.
     */
    $services->set('area.postings', PostingService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PostingRepository::class),
            service('area.station_events'),
        ]);
    $services->alias(PostingService::class, 'area.postings');

    /*
     * THE PEOPLE POSTED SOMEWHERE, AS A BOARD — the lines, what the filters
     * offer and how many each would leave, out of one pass so the two cannot
     * disagree about what "the board" is.
     */
    $services->set('area.posting_board', PostingBoardService::class)
        ->args([
            service(PostingRepository::class),
            service('area.person_facets'),
        ]);
    $services->alias(PostingBoardService::class, 'area.posting_board');

    /*
     * THE AREA'S ANSWER TO "WHERE DOES THIS PERSON WORK?" — tagged BY HAND,
     * because a reusable bundle is not autoconfigured and an attribute on the
     * interface would be silently dead. Whoever draws a person's page reads
     * the tag; nothing in either bundle names a class in the other.
     */
    $services->set('area.person_postings', AreaPersonPostings::class)
        ->args([service(PostingRepository::class), service('router')])
        ->tag(PersonPostingProviderInterface::TAG);

    /*
     * AND THE STATION-SHAPED HALF OF IT: every station and who stands at each,
     * across every area at once, which is what a cross-area postings board
     * reads and what no per-area read can answer.
     */
    $services->set('area.station_directory', AreaStationDirectory::class)
        ->args([service(StationRepository::class), service(PostingRepository::class), service(AreaOfInterestRepository::class)])
        ->tag(StationDirectoryInterface::TAG);

    /*
     * AND THE OTHER DIRECTION: what a postings board knows about the people on
     * it — a position and a department, which belong to whoever owns people.
     */
    $services->set('area.person_facets', PersonFacetService::class)
        ->args([tagged_iterator(PersonFacetProviderInterface::TAG)]);
    $services->alias(PersonFacetService::class, 'area.person_facets');

    /*
     * THE AREA'S POSTS AS ONE FLAT, FILTERED, PAGED LIST — the register the
     * stations section is, with every facet counted against the other filters.
     */
    $services->set('area.station_register', StationRegisterService::class)
        ->args([
            service(StationRepository::class),
            service(PostingRepository::class),
            service('area.zone_set'),
        ]);
    $services->alias(StationRegisterService::class, 'area.station_register');

    /*
     * AND THE AREA'S ZONES AS THE SAME KIND OF LIST — the table the zones tab
     * reads them in, which is the stations register's twin: same card, same
     * dropdowns, same pager, filtered and paged on the server.
     */
    $services->set('area.zone_list', ZoneListService::class)
        ->args([
            service('area.zone_set'),
            service(StationRepository::class),
            service(PostingRepository::class),
            service('area.zone_figures'),
            service('registry.area_modules'),
            service('registry.catalogue'),
            service('atlas.periods'),
        ]);
    $services->alias(ZoneListService::class, 'area.zone_list');

    /*
     * THE AREA OVERVIEW'S CATALOGUE, ASSEMBLED PER AREA — the one surface in
     * the product whose widgets are not written by whoever owns the page.
     * The tag is read here and no module is named: a module that puts a card
     * on an area's overview tags a contributor in its own extension.
     */
    $services->set('area.overview_catalogue', AreaOverviewCatalogue::class)
        ->args([
            tagged_iterator(OverviewContributorInterface::TAG),
            service('area.overview'),
        ]);
    $services->alias(AreaOverviewCatalogue::class, 'area.overview_catalogue');

    /*
     * AND THE AREA'S OWN CELLS, contributed the same way a module's are: the
     * page has one way of putting a card on its grid, not two.
     */
    $services->set('area.overview_widgets', AreaOverviewWidgets::class)
        ->tag(OverviewContributorInterface::TAG);

    /*
     * WHAT A ZONE KNOWS ABOUT THE POSTS ON ITS GROUND — the two numbers a shut
     * card states for the whole area at once, and the full board of the one
     * card somebody opened.
     */
    $services->set('area.zone_stations', ZoneStationService::class)
        ->args([
            service(StationRepository::class),
            service(PostingRepository::class),
            service('area.posting_board'),
        ]);
    $services->alias(ZoneStationService::class, 'area.zone_stations');

    /*
     * AND WHO THERE IS AT ALL, for the row that posts somebody to a station.
     * The same seam one question further back, read the same way: by tag, and
     * with nobody named here either.
     */
    $services->set('area.person_directory', PersonDirectoryService::class)
        ->args([
            tagged_iterator(PersonDirectoryProviderInterface::TAG),
            service('doctrine.orm.entity_manager'),
        ]);
    $services->alias(PersonDirectoryService::class, 'area.person_directory');

    /*
     * WHAT THE MODULES SAY ABOUT A ZONE — every tagged provider, asked once for
     * the whole set, and only where the area runs that module.
     *
     * THE TAG IS READ HERE AND NO MODULE IS NAMED. A module that reports by
     * zone tags a provider in its own extension; nothing in the core has to
     * learn its name for its figures to appear on every zone surface at once.
     */
    $services->set('area.zone_figures', ZoneFigureService::class)
        ->args([tagged_iterator(ZoneFigureProviderInterface::TAG)]);
    $services->alias(ZoneFigureService::class, 'area.zone_figures');

    /*
     * AND WHAT THE MODULES SAY ABOUT A POST — the same shape one rung down:
     * every tagged provider, asked once for the whole set, and only where the
     * area runs that module. The dock on a station record is four rows from
     * four modules, and no module is named here either.
     */
    $services->set('area.station_figures', StationFigureService::class)
        ->args([tagged_iterator(StationFigureProviderInterface::TAG)]);
    $services->alias(StationFigureService::class, 'area.station_figures');

    /*
     * AND WHAT THE MODULES PUT ON A POST AS A SECTION rather than as a
     * figure: the roster's watch band on the record, its block on the
     * configure card. Read off the tag, in registration order, and asked
     * only where the area runs the module — the same rule the figures
     * follow, for the same reason.
     */
    $services->set('area.station_sections', StationSectionService::class)
        ->args([tagged_iterator(StationSectionsInterface::TAG)]);
    $services->alias(StationSectionService::class, 'area.station_sections');

    $services->set('area.zones', ZoneService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ZoneRepository::class),
            service('area.zone_overlaps'),
            service('area.stations'),
            service(StationRepository::class),
            service('area.zone_events'),
        ]);
    $services->alias(ZoneService::class, 'area.zones');

    /*
     * A WHOLE ZONING SCHEME, FROM ONE FILE. Beside the model rather than with
     * the screens, exactly like the boundary import: an installer, a fixture
     * loader or an API with no twig in it must be able to import a scheme too.
     * It writes through the zone service above, so a scheme is held to the same
     * invariant a hand-drawn zone is.
     */
    $services->set('area.zone_import', ZoneImportService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('area.zones'),
            service(ZoneRepository::class),
            service('area.zone_overlaps'),
            service('area.zone_events'),
        ]);
    $services->alias(ZoneImportService::class, 'area.zone_import');

    /*
     * THE SET, BACK OUT. Beside the importer for the reason the importer is
     * beside the entity: a console command that dumps an area's zones has no
     * twig either, and the export is the only copy of a set that will ever
     * exist — nothing keeps superseded geometry.
     */
    $services->set('area.zone_export', ZoneExportService::class)
        ->args([service(ZoneRepository::class)]);
    $services->alias(ZoneExportService::class, 'area.zone_export');

    /*
     * WHAT THE CONFIGURE PAGE READS ABOUT A SET — the rows, the totals and the
     * plate, from one walk over one list, so the colour on a card, the colour
     * in the key and the colour of the ring are the same colour.
     */
    /*
     * THE DAY A RANGER REPORTS — API-CONTRACT.md §13. The words their area
     * lets them report it in, seeded with the four the handset already
     * speaks so an installation that has configured nothing still works.
     */
    $services->set('area.checkin_statuses', CheckInStatusService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(CheckInStatusRepository::class),
        ]);
    $services->alias(CheckInStatusService::class, 'area.checkin_statuses');

    /*
     * AND WHAT THE AREA ENFORCES that the host's own enum does not name.
     * Whoever enforces a permission is who declares it.
     */
    /*
     * HOW A DAY READS — the one derivation, published so the roster
     * module, the overview and a department's page cannot each compute
     * "at post, verified" differently.
     */
    $services->set('area.presence', PresenceService::class)
        ->args([
            // WHAT TIME IT IS, FROM SOMETHING A TEST CAN SET. This service
            // decides whether a rostered watch is over, and it used to ask
            // the wall clock: a suite pinning a clock at half past ten
            // passed all morning and emptied the live plate after six.
            service('clock'),
            service(AreaOfInterestRepository::class),
            service(CheckInRepository::class),
            service(PersonPositionRepository::class),
            // WHO IS ROSTERED WHEN is the roster's, a module this platform
            // has not written yet; an installation without one answers
            // nothing, and a day with no watch is a rest day.
            tagged_iterator(WatchProviderInterface::TAG),
        ]);
    $services->alias(PresenceService::class, 'area.presence');
    /* A module type-hints the contract, never this class. */
    $services->alias(PresenceProviderInterface::class, 'area.presence');
    /*
     * AND WHERE EVERYBODY IS NOW — the same service, because the state
     * on a live marker must be the state on the day board and one
     * derivation is how that stays true. A second contract rather than
     * a second method, because a day is settled once it is over and an
     * instant never is.
     */
    $services->alias(LivePositionsInterface::class, 'area.presence');

    /*
     * WHAT THE GROUND SAYS THERE IS TO HAVE A PERMISSION ABOUT — areas,
     * zones, stations and assignments. Tagged by hand, as a reusable
     * bundle's services must be; the core declares its concerns through the
     * same seam a module uses.
     */
    $services->set('area.access.concerns', AreaConcerns::class)
        ->tag(ConcernSourceInterface::TAG);

    $services->set('area.zone_set', ZoneSetService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ZoneRepository::class),
        ]);
    $services->alias(ZoneSetService::class, 'area.zone_set');

    /*
     * THE PLATE, WHICH NEEDS THE ATLAS — registered beside the set rather than
     * with the screens, because a console command that renders a map payload
     * is a thing an installation may want and a twig engine is not what it
     * would need for it.
     */
    $services->set('area.zone_plate', AreaPlateService::class)
        ->args([
            service(ZoneRepository::class),
            service(MapBuilderInterface::class),
        ]);
    $services->alias(AreaPlateService::class, 'area.zone_plate');

    /*
     * What the register knows about each area — the measurements PostGIS makes
     * and the live count the registry keeps, read once here rather than assembled in
     * a template.
     */
    $services->set('area.register', AreaRegister::class)
        ->args([
            service(AreaOfInterestRepository::class),
            service(AreaModuleRepository::class),
            service(AreaOverview::class),
            service(AreaThumbnailer::class),
        ]);
    $services->alias(AreaRegister::class, 'area.register');

    /*
     * THE OVERVIEW'S CONTRIBUTED CONTENT. The tag strings are written out rather
     * than read from the interfaces' own constants, because they are the platform's
     * published contract and a module bundle writes them the same way — see
     * tests/Unit/Overview/ContributionContractTest.php, which keeps the two
     * spellings equal.
     */
    /*
     * THE OPERATIONAL MAP'S BASE CONTENT — the area's own boundary and zones, as
     * the GeoJSON the browser plate draws. Kept apart from the overview's
     * contributed content above because it is the area's, not a module's:
     * the boundary is always drawn and the zones are the area's own polygons.
     */
    $services->set('area.map_payload', AreaMapPayload::class)
        ->args([service(ZoneRepository::class)]);
    $services->alias(AreaMapPayload::class, 'area.map_payload');

    /*
     * THE AREA'S PLATES. What the area states about its two maps — the overview's
     * and the register's — handed to the atlas to draw. The area writes no
     * JavaScript: this builds the map, and render_map() puts it on the page.
     */
    $services->set('area.map', AreaMapService::class)
        ->args([service(MapBuilderInterface::class)]);
    $services->alias(AreaMapService::class, 'area.map');

    /*
     * WHAT THE FIVE AREAS-INDEX LAYOUTS READ — every fact any of them draws,
     * gathered once at one clock, for the landing and for the library alike.
     * Reads the same overview contributions the register does, so it names no module's
     * content.
     */
    $services->set('area.preset_library', AreaPresetLibrary::class)
        ->args([
            service(AreaOverview::class),
            service(ZoneRepository::class),
            service(AreaRegister::class),
            service(AreaMapService::class),
            service('router'),
        ]);
    $services->alias(AreaPresetLibrary::class, 'area.preset_library');

    /*
     * THE /areas SURFACE'S CATALOGUE — the five layouts the landing ships, as the
     * widget framework's own declaration, so the adopted one is a stored
     * preference row the register and its library both read.
     *
     * TAGGED BY HAND: a reusable bundle wires its services explicitly, and a
     * surface that forgot the tag has a working dashboard and an unreachable
     * registry entry.
     */
    $services->set('area.widget_surface', AreaIndexWidgets::class)
        ->tag(WidgetSurfaceInterface::TAG);

    $services->set('area.overview', AreaOverview::class)
        ->args([
            tagged_iterator('uhifadhi.overview.now_tile'),
            tagged_iterator('uhifadhi.overview.attention'),
            tagged_iterator('uhifadhi.map.layer'),
            tagged_iterator('uhifadhi.overview.pulse'),
            service(AreaModuleRepository::class),
            // THE AREAS, so the attention queue can be read one scope wider.
            service(AreaOfInterestRepository::class),
        ]);
    $services->alias(AreaOverview::class, 'area.overview');

    /*
     * THE REGISTER CARD'S FACE — the area's boundary, simplified in the database
     * and projected into the card's viewBox. The satellite raster the design's
     * card face also asks for is deferred; see AreaThumbnailer's docblock.
     */
    $services->set('area.thumbnailer', AreaThumbnailer::class)
        ->args([service(AreaOfInterestRepository::class)]);
    $services->alias(AreaThumbnailer::class, 'area.thumbnailer');

    /*
     * AN AREA, CREATED FROM ITS IDENTITY. Beside the entity rather than with the
     * screens for the same reason as the import: a console importer or a fixture
     * loader with no twig must be able to make an area too.
     */
    $services->set('area.creator', AreaCreator::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(AreaCreator::class, 'area.creator');

    /*
     * AN AREA'S IDENTITY, EDITED IN PLACE. The sibling of the creator, and beside
     * the entity for the same reason: editing an area's name or gazetted facts is
     * a model concern a console command or a fixture loader with no twig would
     * want too. The boundary is not its business — that is BoundaryImport's.
     */
    $services->set('area.identity', AreaIdentity::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(AreaIdentity::class, 'area.identity');

    /*
     * THE ONLY SUPPORTED WAY AN AREA GETS A BOUNDARY FROM OUTSIDE — added or
     * replaced onto an area that already exists. Registered beside the entity
     * rather than with the screens, because it is not a screen's: a console
     * importer or a fixture loader in an installation with no twig must be able
     * to ask for it too.
     */
    $services->set('area.boundary_import', BoundaryImport::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(BoundaryImport::class, 'area.boundary_import');

    /*
     * AN AREA'S COMPOSITION, READ FROM THE REGISTRY'S OWN SERVICES. The three ids
     * asked for here are PRIVATE in the registry and that is fine — private means
     * "not fetchable from the container at runtime", never "not injectable". The
     * registry publishes no aliases deliberately, so a consumer names the ids;
     * they are its published surface either way.
     *
     * REGISTERED HERE RATHER THAN WITH THE SCREENS because the reading is not a
     * screen's: an installation that carries the model and mounts no page reads
     * its own composition too, and has no twig.
     */
    /*
     * WHERE A MODULE'S OWN SETTINGS ARE, for the modules register's door
     * column: asked of the shell's sections registry, the same declaration
     * the shell draws a module's configure page from.
     */
    $services->set('area.module_settings_doors', ModuleSettingsDoors::class)
        ->args([
            service('shell.frame.configuration_sections'),
            service('router'),
        ]);
    $services->alias(ModuleSettingsDoors::class, 'area.module_settings_doors');

    $services->set('area.composition', AreaComposition::class)
        ->args([
            service('registry.catalogue'),
            service('registry.area_modules'),
            service('registry.entry_routes'),
            service('router'),
            service('area.module_settings_doors'),
        ]);
    $services->alias(AreaComposition::class, 'area.composition');

    /*
     * THE DEMO GROUND, OFFERED TO A TOOL THAT IS NOT INSTALLED HERE. Three
     * slices — the areas, the scheme that subdivides each of them, the posts
     * standing on it and who works out of them — each an ordinary tagged
     * service nothing in this bundle ever asks anything of.
     *
     * THE TAG IS A LITERAL STRING because devkit ships through `require-dev`
     * and naming a constant of its would load a class a production build does
     * not have. The contract both ends agree on is
     * {@see \Uhifadhi\Contracts\Devkit\ContentProviderInterface}, which names
     * the tag.
     */
    $services->set('area.devkit.areas', AreaContentProvider::class)
        ->args([
            service('area.creator'),
            service(AreaOfInterestRepository::class),
        ])
        ->tag('uhifadhi.devkit.content_provider');

    $services->set('area.devkit.zones', ZoneContentProvider::class)
        ->args([
            service(AreaOfInterestRepository::class),
            service(ZoneRepository::class),
            service('area.zone_import'),
        ])
        ->tag('uhifadhi.devkit.content_provider');

    $services->set('area.devkit.stations', StationContentProvider::class)
        ->args([
            service(AreaOfInterestRepository::class),
            service(StationRepository::class),
            service('area.stations'),
            service('area.postings'),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('uhifadhi.devkit.content_provider');
};
