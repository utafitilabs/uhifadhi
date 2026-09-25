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

use Uhifadhi\Bundle\AreaBundle\Api\DutyApiContext;
use Uhifadhi\Bundle\AreaBundle\Api\FieldRoster;
use Uhifadhi\Bundle\AreaBundle\Api\State\AreasMineProvider;
use Uhifadhi\Bundle\AreaBundle\Api\State\CreateCheckInProcessor;
use Uhifadhi\Bundle\AreaBundle\Api\State\DutyStationsProvider;
use Uhifadhi\Bundle\AreaBundle\Api\State\MyRosterProvider;
use Uhifadhi\Bundle\AreaBundle\Api\State\UpdateCheckInProcessor;
use Uhifadhi\Bundle\AreaBundle\Api\State\UploadPositionsProcessor;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInCorrectionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PersonPositionRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\DutyRosterService;
use Uhifadhi\Bundle\AreaBundle\Service\DutyStationService;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;

/*
 * WHAT THIS BUNDLE SERVES A FIELD CLIENT: `GET /api/areas/mine`, the offline
 * cache a handset fills at sign-in, and the duty surface a ranger reports
 * the day through — the claim, its amendments and the batched pings.
 *
 * IMPORTED ONLY WHERE BOTH HALVES ARE PRESENT — see AreaBundle::loadExtension().
 * Without api-platform there is no `/api` to attach to; without security there is
 * no authorization checker, and an endpoint that hands out an installation's whole
 * estate must never be able to fall back to "grant everything" because the thing
 * that narrows it was missing. An installation lacking either gets the area model
 * and no field API rather than an unnarrowed one.
 *
 * Everything here is defined EXPLICITLY, with ids prefixed by the bundle alias, as
 * everywhere else in this bundle:
 *
 *   area.api.roster            the people a client may name on a record
 *   area.api.areas_provider    the areas the bearer account may work in
 *   area.api.duty              request, token and area, resolved once per call
 *   area.checkins              the writes behind the duty surface
 *   area.presence_publisher    one mark on the wire after each of them
 *   area.api.checkin_create    POST   /areas/{areaUuid}/checkins
 *   area.api.checkin_update    PATCH  /areas/{areaUuid}/checkins/{clientRef}
 *   area.api.positions_upload  POST   /areas/{areaUuid}/positions
 *   area.api.my_roster         GET    /areas/{areaUuid}/me/roster
 *   area.api.duty_stations     GET    /areas/{areaUuid}/stations
 *
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('area.api.roster', FieldRoster::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * TAGGED BY HAND, because a reusable bundle is not autoconfigured: an
     * untagged provider is not in the locator API Platform resolves an
     * operation's `provider:` through, and the endpoint would answer 500 with
     * "Provider not found".
     *
     * THE `key` ATTRIBUTE IS WHAT KEEPS THE ID PREFIXED. The locator is built
     * with `tagged_locator('api_platform.state_provider', 'key')`, so a tag
     * without one is indexed by SERVICE ID and the resource's
     * `provider: AreasMineProvider::class` would only resolve if the id were the
     * class name. Naming the class in `key` satisfies the resource and leaves the
     * id under this bundle's alias, as a reusable bundle's ids must be.
     *
     * @see vendor/api-platform/core/src/Symfony/Bundle/Resources/config/state/state.php — the locator and its index attribute
     * @see vendor/api-platform/core/src/State/CallableProvider.php — the lookup that throws when a provider is not in it
     */
    $services->set('area.api.areas_provider', AreasMineProvider::class)
        ->args([
            service(AreaOfInterestRepository::class),
            service('area.api.roster'),
            service('security.authorization_checker'),
            service('area.duty_stations'),
            service(PostingRepository::class),
            service('security.token_storage'),
        ])
        ->tag('api_platform.state_provider', ['key' => AreasMineProvider::class]);

    /*
     * THE DUTY SURFACE — API-CONTRACT.md §13.
     *
     * The context is the one place the request, the bearer's account and the
     * area in the URI are resolved, so that the three processors agree on who
     * is reporting and where: the person is read off the TOKEN and never off
     * the body, which is why no processor takes a person as input.
     *
     * The writes sit in their own service rather than in the processors,
     * because the check-in an operator amends from the web and the check-in a
     * handset claims are the same rules — one upsert, one append-only
     * correction trail — and a second copy of them would drift.
     *
     * Each processor is tagged BY HAND with the `key` attribute, for the reason
     * spelt out above the provider.
     */
    /*
     * THE WIRE — one person's mark published to the area's private Mercure
     * topic after each write. THE HUB IS OPTIONAL: the core suggests
     * symfony/mercure-bundle and an installation requires it (the starter
     * does), so `mercure.hub.default` is named with the documented behaviour
     * for a dependency that may be absent — "You can use the `null` strategy
     * to explicitly set the argument to `null` if the service does not
     * exist" — and the publisher reads null as "publish nothing", exactly as
     * it reads a hub whose ADDRESS the deployment left empty. The clock is
     * the same one the presence reading uses, so the frame and the plate
     * agree about now.
     *
     * @see https://symfony.com/doc/current/service_container/optional_dependencies.html — "Setting Missing Dependencies to null"
     * @see vendor/symfony/dependency-injection/Loader/Configurator/ReferenceConfigurator.php — `nullOnInvalid()`
     * @see vendor/symfony/mercure-bundle/src/DependencyInjection/MercureExtension.php — `mercure.hub.<name>`
     */
    $services->set('area.presence_publisher', PresencePublisher::class)
        ->args([
            service('mercure.hub.default')->nullOnInvalid(),
            service('area.presence'),
            service('clock'),
            service('logger'),
        ]);
    $services->alias(PresencePublisher::class, 'area.presence_publisher');

    $services->set('area.checkins', CheckInService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(CheckInRepository::class),
            service(CheckInCorrectionRepository::class),
            service(PersonPositionRepository::class),
            service(StationRepository::class),
            service(CheckInStatusService::class),
            service('area.presence_facts'),
            service('area.presence_publisher'),
        ]);
    $services->alias(CheckInService::class, 'area.checkins');

    $services->set('area.api.duty', DutyApiContext::class)
        ->args([
            service('request_stack'),
            service('security.token_storage'),
            service('security.authorization_checker'),
            service(AreaOfInterestRepository::class),
        ]);

    $services->set('area.api.checkin_create', CreateCheckInProcessor::class)
        ->args([
            service('area.api.duty'),
            service('area.checkins'),
        ])
        ->tag('api_platform.state_processor', ['key' => CreateCheckInProcessor::class]);

    $services->set('area.api.checkin_update', UpdateCheckInProcessor::class)
        ->args([
            service('area.api.duty'),
            service(CheckInRepository::class),
            service('area.checkins'),
        ])
        ->tag('api_platform.state_processor', ['key' => UpdateCheckInProcessor::class]);

    $services->set('area.api.positions_upload', UploadPositionsProcessor::class)
        ->args([
            service('area.api.duty'),
            service('area.checkins'),
        ])
        ->tag('api_platform.state_processor', ['key' => UploadPositionsProcessor::class]);

    /*
     * AND THE TWO READS THE DUTY TAB LIVES ON. Both must work from the
     * phone's cache, so both are small whole documents rather than
     * anything paged: the month, and the posts with their rings.
     *
     * THE ROSTER SERVICE IS DEFINED HERE rather than beside the area's
     * other services because it is this endpoint's answer and nothing
     * else reads it yet — and because the watches it folds in arrive
     * through a seam an installation may have no implementor for, which
     * is a legitimate state and not a missing dependency.
     */
    $services->set('area.duty_roster', DutyRosterService::class)
        ->args([
            service(CheckInStatusService::class),
            service('area.ping_interval'),
            tagged_iterator(WatchProviderInterface::TAG),
        ]);
    $services->alias(DutyRosterService::class, 'area.duty_roster');

    $services->set('area.duty_stations', DutyStationService::class)
        ->args([service(StationRepository::class)]);
    $services->alias(DutyStationService::class, 'area.duty_stations');

    $services->set('area.api.my_roster', MyRosterProvider::class)
        ->args([
            service('area.api.duty'),
            service('area.duty_roster'),
        ])
        ->tag('api_platform.state_provider', ['key' => MyRosterProvider::class]);

    $services->set('area.api.duty_stations', DutyStationsProvider::class)
        ->args([
            service('area.api.duty'),
            service('area.duty_stations'),
        ])
        ->tag('api_platform.state_provider', ['key' => DutyStationsProvider::class]);
};
