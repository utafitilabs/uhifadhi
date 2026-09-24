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

use Symfony\Component\Console\Application;
use Uhifadhi\Bundle\RegistryBundle\Event\ModuleInstalledEvent;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\ShellBundle\Contract\NavigationSourceInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Bundle\TeamBundle\Access\ConcernCatalogue;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Bundle\TeamBundle\Access\TeamConcerns;
use Uhifadhi\Bundle\TeamBundle\Api\State\MeProvider;
use Uhifadhi\Bundle\TeamBundle\ArgumentResolver\AreaValueResolver;
use Uhifadhi\Bundle\TeamBundle\Command\CreateUserCommand;
use Uhifadhi\Bundle\TeamBundle\Command\PerformanceSnapshotCommand;
use Uhifadhi\Bundle\TeamBundle\Controller\ApiAuthController;
use Uhifadhi\Bundle\TeamBundle\Controller\AreaDepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentSectionController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentWidgetsController;
use Uhifadhi\Bundle\TeamBundle\Controller\InviteController;
use Uhifadhi\Bundle\TeamBundle\Controller\MemberController;
use Uhifadhi\Bundle\TeamBundle\Controller\PasswordResetController;
use Uhifadhi\Bundle\TeamBundle\Controller\PerformanceConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\PerformanceController;
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\SecurityController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamPostingsController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamRolesController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamSectionController;
use Uhifadhi\Bundle\TeamBundle\Devkit\TeamContentProvider;
use Uhifadhi\Bundle\TeamBundle\EventListener\ApiErrorListener;
use Uhifadhi\Bundle\TeamBundle\EventListener\ModuleHistoryListener;
use Uhifadhi\Bundle\TeamBundle\People\TeamPersonDirectory;
use Uhifadhi\Bundle\TeamBundle\People\TeamPersonFacets;
use Uhifadhi\Bundle\TeamBundle\Performance\AcrossTopicsMatrix;
use Uhifadhi\Bundle\TeamBundle\Performance\AttentionTopic;
use Uhifadhi\Bundle\TeamBundle\Performance\DepartmentsBand;
use Uhifadhi\Bundle\TeamBundle\Performance\GoalsTopic;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixPlacing;
use Uhifadhi\Bundle\TeamBundle\Performance\MatrixViewBuilder;
use Uhifadhi\Bundle\TeamBundle\Performance\OrganizationBand;
use Uhifadhi\Bundle\TeamBundle\Performance\StaffingTopic;
use Uhifadhi\Bundle\TeamBundle\Performance\TopicCards;
use Uhifadhi\Bundle\TeamBundle\Repository\ApiTokenRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentKindRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentPeriodFigureRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentScopeChangeRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\InstallationPeriodFigureRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\PositionRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Security\ActiveUserChecker;
use Uhifadhi\Bundle\TeamBundle\Security\ApiTokenAuthenticator;
use Uhifadhi\Bundle\TeamBundle\Security\AreaAuthority;
use Uhifadhi\Bundle\TeamBundle\Security\GrantVoter;
use Uhifadhi\Bundle\TeamBundle\Service\ApiTokenManager;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentDirectory;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentKindService;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentMembership;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPalette;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentPerformance;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentSectionOverview;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentService;
use Uhifadhi\Bundle\TeamBundle\Service\FieldSignIn;
use Uhifadhi\Bundle\TeamBundle\Service\Mail;
use Uhifadhi\Bundle\TeamBundle\Service\MemberHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PasswordResetService;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PerformanceTopics;
use Uhifadhi\Bundle\TeamBundle\Service\PositionBoard;
use Uhifadhi\Bundle\TeamBundle\Service\PositionHistory;
use Uhifadhi\Bundle\TeamBundle\Service\PositionService;
use Uhifadhi\Bundle\TeamBundle\Service\PositionVacancy;
use Uhifadhi\Bundle\TeamBundle\Service\PostingBoard;
use Uhifadhi\Bundle\TeamBundle\Service\PostingDoorService;
use Uhifadhi\Bundle\TeamBundle\Service\RolesBoard;
use Uhifadhi\Bundle\TeamBundle\Service\StaffingFigures;
use Uhifadhi\Bundle\TeamBundle\Service\SuperAdminInvariant;
use Uhifadhi\Bundle\TeamBundle\Service\TeamOverview;
use Uhifadhi\Bundle\TeamBundle\Service\TeamSectionOverview;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;
use Uhifadhi\Bundle\TeamBundle\Settings\PeopleFigure;
use Uhifadhi\Bundle\TeamBundle\Settings\PeopleReading;
use Uhifadhi\Bundle\TeamBundle\Settings\PositionFigure;
use Uhifadhi\Bundle\TeamBundle\Settings\TeamSteps;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentAreaNavChildren;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentAreaSections;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\DepartmentSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceNavigation;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\PerformanceSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamNavigation;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionConfiguration;
use Uhifadhi\Bundle\TeamBundle\Shell\TeamSectionTabs;
use Uhifadhi\Bundle\TeamBundle\Shell\UserBadgeSource;
use Uhifadhi\Bundle\TeamBundle\Twig\AreaScopeExtension;
use Uhifadhi\Bundle\TeamBundle\Twig\DoorExtension;
use Uhifadhi\Bundle\TeamBundle\Twig\MatrixExtension;
use Uhifadhi\Bundle\TeamBundle\Twig\MatrixRuntime;
use Uhifadhi\Bundle\TeamBundle\Widget\DepartmentWidgets;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Area\StationDirectoryInterface;
use Uhifadhi\Contracts\Kpi\CurrentPeriodInterface;
use Uhifadhi\Contracts\People\PersonDirectoryProviderInterface;
use Uhifadhi\Contracts\People\PersonFacetProviderInterface;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;
use Uhifadhi\Contracts\People\StationPlateProviderInterface;
use Uhifadhi\Contracts\Performance\DepartmentDirectoryInterface;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Settings\SettingsFigureSourceInterface;
use Uhifadhi\Contracts\Settings\SettingsStepSourceInterface;
use Uhifadhi\Contracts\Shell\AreaNavChildrenInterface;
use Uhifadhi\Contracts\Shell\AreaSectionsInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * an installation, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * TeamBundle::loadExtension(), which keeps only the config-DRIVEN bits.
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(),
 * and ids prefixed with the bundle alias — because this bundle is installed by
 * other projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * The ids are the published surface. They are private, as a reusable bundle's
 * should be; anything that wants one aliases it.
 *
 *   team.api_token.manager      the credential a field client carries: issue, find, note, withdraw
 *   team.api_token.authenticator  the bearer token a field client presents, and the 401 for none
 *   team.api_error_listener     one failure document for everything under /api
 *   team.field_sign_in          identifier + passcode -> the person, or nobody
 *   team.controller.api_auth    where a field client signs in
 *   team.api.me_provider        GET /api/me: the bearer account and its permissions
 *   team.super_admin_invariant  the refusal that keeps one active Super Admin
 *   team.accounts               every way an account comes into being or changes
 *   team.positions              what a position is, and what it grants
 *   team.departments            the org chart's shape, and its audited scope changes
 *   team.password_reset         a recovery link issued, spent, or an invitation accepted
 *   team.user_checker           the sign-in refusal for a deactivated account
 *   team.overview               the roster's counts and its attention rows
 *   team.widget_surface.*       the roster and the matrix, as dashboard surfaces
 *   team.command.create_user    the first administrator, made from the console
 *   team.devkit.content         the demo organization devkit seeds in a dev install
 *   team.controller.security    the sign-in screen
 *   team.controller.team        the roster
 *   team.controller.member      one person's record
 *   team.controller.position    the permission matrix
 *   team.controller.position_widgets  its widget library
 *   team.controller.invite      both ways of adding somebody
 *   team.controller.reset       forgot / reset / accept, on the document rung
 *   team.mail                   the two letters, and whether they can be sent
 *   team.navigation             the Team row in the shell's sidebar, where there is a shell
 *   team.user_badge_source      who the shell's top bar names — aliased to shell.user_badge_source
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    /*
     * TEAM'S ANSWER TO "WHAT IS THIS PERSON, AND WHOSE?" — a position title
     * and a department name, for a list of people somewhere else in the
     * product. Tagged BY HAND: a reusable bundle is not autoconfigured, and an
     * attribute on the contract's interface would be silently dead.
     */
    $services->set('team.person_facets', TeamPersonFacets::class)
        ->args([service(UserRepository::class)])
        ->tag(PersonFacetProviderInterface::TAG);

    /*
     * AND WHO THERE IS AT ALL — the same seam one question further back, so a
     * surface that posts somebody somewhere can offer the people without
     * knowing whose class they are.
     */
    $services->set('team.person_directory', TeamPersonDirectory::class)
        ->args([service(UserRepository::class)])
        ->tag(PersonDirectoryProviderInterface::TAG);

    /*
     * HOW MANY PEOPLE THIS INSTALLATION IS FOR — the settings section's third
     * figure. Active, because that is the number every other figure in the
     * product is about; the closed accounts are the caption's.
     */
    /*
     * HOW MANY PEOPLE, HOW MANY POSTED, AND WHAT IS LEFT TO DO ABOUT IT —
     * one reading behind a figure and a step, so a card saying "18 posted"
     * over a checklist saying "4 to post" is arithmetic a reader can do.
     *
     * WHERE SOMEBODY WORKS IS NOT THIS BUNDLE'S: it arrives through the
     * posting seam, and an installation with no area package answers
     * nothing — which both readings state rather than reading as nought.
     */
    $services->set('team.settings.people', PeopleReading::class)
        ->args([
            service(UserRepository::class),
            tagged_iterator(PersonPostingProviderInterface::TAG),
        ]);

    $services->set('team.settings.figure', PeopleFigure::class)
        ->args([service('team.settings.people')])
        ->tag(SettingsFigureSourceInterface::TAG);

    $services->set('team.settings.position_figure', PositionFigure::class)
        ->args([service(PositionRepository::class)])
        ->tag(SettingsFigureSourceInterface::TAG);

    $services->set('team.settings.steps', TeamSteps::class)
        ->args([
            service('team.settings.people'),
            service(PositionRepository::class),
            service('router'),
        ])
        ->tag(SettingsStepSourceInterface::TAG);

    $services->set(UserRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(PositionRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentScopeChangeRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentGoalRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentKindRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(InstallationPeriodFigureRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentPeriodFigureRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(ApiTokenRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE CREDENTIAL A FIELD CLIENT CARRIES. It lives here because a token is a
     * credential OF A PERSON, kept, rotated and withdrawn beside the account it
     * belongs to — exactly as a password is.
     *
     * THE AUTHENTICATOR IS ITS NEIGHBOUR, not a stranger reaching through an
     * interface: a credential and the thing that reads it belong in one bundle,
     * so the store is injected directly.
     */
    $services->set('team.api_token.manager', ApiTokenManager::class)
        ->args([service(ApiTokenRepository::class), service('doctrine.orm.entity_manager')]);

    /*
     * THE FIRST ADMINISTRATOR, AND THE ONE CONSOLE COMMAND THE CORE SHIPS.
     * Every other command the platform has belongs to devkit, which installs
     * through require-dev; this one cannot, because a production installation is
     * built WITHOUT development packages and the first account is needed exactly
     * there — on the server, once, after the deploy.
     *
     *   "If you can't use PHP attributes, register the command as a service and
     *    tag it with the console.command tag."
     *   — https://symfony.com/doc/current/console.html#registering-the-command
     *
     * A BARE TAG, because the name and the description are on the class: the
     * compiler pass reads the #[AsCommand] attribute whether or not anything was
     * autoconfigured, and registers the service lazily under the name it finds —
     * which is how the framework's own commands are wired.
     * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php
     * @see vendor/symfony/framework-bundle/Resources/config/console.php
     *
     * GUARDED ON THE COMPONENT, as FrameworkBundle guards the file that carries
     * every one of its commands (FrameworkExtension::hasConsole() is
     * class_exists(Application::class), and console.php is loaded only if it
     * holds). A container compiled where there is no console must not carry a
     * service whose class it cannot load.
     * @see vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php
     */
    if (class_exists(Application::class)) {
        $services->set('team.command.create_user', CreateUserCommand::class)
            ->args([service('team.accounts')])
            ->tag('console.command');

        /*
         * AND THE ONE THAT MAKES HISTORY. Scheduled on the first of each
         * period; it writes the period that has just closed.
         */
        $services->set('team.command.performance_snapshot', PerformanceSnapshotCommand::class)
            ->args([
                service(DepartmentRepository::class),
                service('team.staffing_figures'),
                service('team.department_performance'),
                service('team.performance_history'),
                // AND THE INSTALLATION'S OWN FIVE, computed by the page that
                // draws them so the history and the page cannot disagree.
                service('team.section_overview'),
            ])
            ->tag('console.command');
    }

    /*
     * A SMALL ORGANIZATION TO LOOK AT, offered the same way and collected by
     * the same absent tool. It writes through this bundle's own services, so
     * the content it leaves is content somebody could have built by clicking.
     */
    $services->set('team.devkit.content', TeamContentProvider::class)
        ->args([
            service('team.accounts'),
            service('team.positions'),
            service('team.departments'),
            service(UserRepository::class),
            service('team.staffing_figures'),
            service('team.performance_history'),
            service('team.access.catalogue'),
            service(PositionRepository::class),
        ])
        ->tag('uhifadhi.devkit.content_provider');

    /*
     * ONE FAILURE DOCUMENT FOR EVERYTHING UNDER `/api`, whoever refused.
     *
     * Two listeners on one object. The exception pass answers a thrown
     * ApiProblemException verbatim and stops there, at a priority above the
     * firewall's exception listener and the framework's own, so nothing
     * downstream reshapes a code a client switches on. The response pass is the
     * safety net for every other failure — the firewall's 401, routing's 404, a
     * 500 from anywhere — and runs last, once the status is settled, replacing
     * the body and never the status.
     *
     * TAGGED BY HAND, twice: a reusable bundle is not autoconfigured, so the
     * #[AsEventListener] attribute would never be read and the document would
     * silently be whatever each layer felt like.
     */
    /*
     * A MODULE SWITCHED ON TODAY IS ASKED ABOUT THE PERIODS THAT HAVE
     * ALREADY CLOSED, so the page it appears on has something to compare
     * against. Guarded on the event's class: an installation without the
     * registry has no modules to install and nothing to listen for.
     */
    if (class_exists(ModuleInstalledEvent::class)) {
        $services->set('team.module_history_listener', ModuleHistoryListener::class)
            ->args([
                service(DepartmentRepository::class),
                service('team.department_performance'),
                service('team.performance_history'),
            ])
            ->tag('kernel.event_listener', ['event' => ModuleInstalledEvent::class, 'method' => 'onModuleInstalled']);
    }

    $services->set('team.api_error_listener', ApiErrorListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onException', 'priority' => 512])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onResponse', 'priority' => -1024]);

    /*
     * THE BEARER TOKEN A FIELD CLIENT PRESENTS — the machine door's
     * authenticator and its entry point in one class, so a request with NO
     * token is answered 401 rather than the 403 an access listener would give.
     *
     * PUBLIC, AND ALIASED FROM THE CLASS NAME, because the thing that names it
     * is the installation's own security file: a firewall's
     * `custom_authenticators` and `entry_point` are written as class names, and
     * a bundle's services are private by default.
     *
     * nullOnInvalid() KEEPS THE DENY-BY-DEFAULT READING. Where nothing in the
     * container keeps API tokens the store is null, the authenticator claims
     * nothing, and every request falls to the entry point and 401 — which is
     * the safe reading of "nobody can say who this is".
     */
    $services->set('team.api_token.authenticator', ApiTokenAuthenticator::class)
        ->args([service('team.api_token.manager')->nullOnInvalid()]);
    $services->alias(ApiTokenAuthenticator::class, 'team.api_token.authenticator')->public();

    /*
     * WHETHER AN IDENTIFIER AND A PASSCODE NAME SOMEBODY WHO MAY SIGN IN — the
     * three refusals a firewall would make separately, made together, because
     * the field door is reached before any firewall.
     */
    $services->set('team.field_sign_in', FieldSignIn::class)
        ->args([service(UserRepository::class), service('security.user_password_hasher')]);

    /*
     * WHERE A FIELD CLIENT SIGNS IN. The one endpoint that answers without a
     * token, so the installation's security file leaves its path unguarded and
     * the endpoint checks the credentials itself.
     */
    $services->set('team.controller.api_auth', ApiAuthController::class)
        ->args([
            service('team.field_sign_in'),
            service('team.api_token.manager'),
            service('team.access.catalogue'),
            // The two budgets the endpoint spends before it weighs a
            // credential. The ids are the framework's own naming of the
            // limiters this bundle prepends — see TeamBundle::prependExtension.
            service('limiter.team_token_id'),
            service('limiter.team_token_ip'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(ApiAuthController::class, 'team.controller.api_auth')->public();

    /*
     * `GET /api/me` — the bearer account and its permissions, re-read on every
     * sync so a grant made in the web app reaches a handset with no sign-out.
     *
     * TAGGED BY HAND, because a reusable bundle is not autoconfigured: an
     * untagged provider is not in the locator API Platform resolves an
     * operation's `provider:` through, and the endpoint would answer 500 with
     * "Provider not found".
     *
     * THE `key` ATTRIBUTE IS WHAT KEEPS THE ID PREFIXED. The locator is built
     * with `tagged_locator('api_platform.state_provider', 'key')`, so a tag
     * without one is indexed by SERVICE ID and the resource's
     * `provider: MeProvider::class` would only resolve if the id were the class
     * name. Naming the class in `key` satisfies the resource and leaves the id
     * under this bundle's alias, as a reusable bundle's ids must be.
     *
     * @see vendor/api-platform/core/src/Symfony/Bundle/Resources/config/state/state.php — the locator and its index attribute
     * @see vendor/api-platform/core/src/State/CallableProvider.php — the lookup that throws when a provider is not in it
     */
    $services->set('team.api.me_provider', MeProvider::class)
        ->args([
            service('security.token_storage'),
            service('team.access.catalogue'),
        ])
        ->tag('api_platform.state_provider', ['key' => MeProvider::class]);

    /*
     * WHAT THE TEAM SAYS THERE IS TO HAVE A PERMISSION ABOUT. The tag goes on
     * by hand because a reusable bundle is not autoconfigured, and the core
     * declares its concerns through the same seam a module uses — there is no
     * privileged list in the middle of the product.
     */
    $services->set('team.access.concerns', TeamConcerns::class)
        ->tag(ConcernSourceInterface::TAG);

    /*
     * EVERYTHING THERE IS TO HAVE A PERMISSION ABOUT, folded together from
     * whoever declared it. The tagged iterator is the seam: the core's own
     * bundles arrive through it exactly as a module does, so there is no
     * privileged list in the middle of the product.
     */
    $services->set('team.access.catalogue', ConcernCatalogue::class)
        ->args([tagged_iterator(ConcernSourceInterface::TAG)]);
    $services->alias(ConcernCatalogue::class, 'team.access.catalogue');

    /*
     * THE CHECK ITSELF — the three questions, fail-closed. Tagged by hand
     * like every other service here: a voter that missed this tag would deny
     * nothing and grant nothing, which looks exactly like a permission model
     * that does not work.
     *
     * IT RUNS BESIDE THE OLD ONE FOR ONE RELEASE. The two answer different
     * attributes — this one only pairs it can parse and the catalogue
     * declares, the other only the flat values its own catalogue knows — so
     * neither can overrule the other, and the gates move over a controller at
     * a time rather than in one unreviewable sweep.
     */
    $services->set('team.access.voter', GrantVoter::class)
        ->args([service('team.access.catalogue'), service(DepartmentRepository::class)])
        ->tag('security.voter');

    /*
     * THE CONVENIENCE ARGUMENT RESOLVER — turns a `{uuid}` route param into the
     * platform's area so a controller can pass it to the voter
     * (isGranted('patrols.record', $area)). Tagged by hand like everything here;
     * a reusable bundle is not autoconfigured. Priority above the default
     * resolvers so an AreaInterface-typed argument is filled from the route
     * before a generic resolver tries and fails.
     */
    $services->set('team.area_value_resolver', AreaValueResolver::class)
        ->args([service('doctrine.orm.entity_manager')])
        ->tag('controller.argument_value_resolver', ['priority' => 150]);

    /*
     * THE READ SIDE OF AREA-SCOPED team.manage — whether the signed-in
     * administrator is unbounded (a tier or org-level holder) or confined to one
     * area. The department controller refuses the escalation acts (minting an org
     * department, changing scope, reaching another area) by asking this.
     */
    $services->set('team.area_authority', AreaAuthority::class)
        ->args([service('security.token_storage')]);

    /*
     * THE "SCOPED TO <AREA>" BANNER'S ONE INPUT — the area a bounded
     * administrator is confined to, exposed to Twig so the shared partial can be
     * fed from one place rather than every management controller threading the
     * same value. Tagged by hand: a reusable bundle is not autoconfigured, and an
     * untagged Twig extension is one Twig never loads.
     */
    $services->set('team.twig.area_scope', AreaScopeExtension::class)
        ->args([service('team.area_authority')])
        ->tag('twig.extension');

    /*
     * THE ONE HELPER EVERY DRAWN CONTROL ASKS. A door is a link, a button or
     * a section leading somewhere a permission guards, and drawing one the
     * reader cannot walk through is the small daily dishonesty of an
     * administrative product. Going through one named function rather than
     * `is_granted` is what lets a conformance test walk every door and hold
     * it against the routes.
     */
    $services->set('team.access.door', Door::class)
        ->args([service('security.authorization_checker')]);
    $services->alias(Door::class, 'team.access.door');

    $services->set('team.twig.door', DoorExtension::class)
        ->args([service('team.access.door')])
        ->tag('twig.extension');

    /*
     * THE TOPIC MATRIX, AND THE ONE PLACE ITS SHADES ARE DECIDED.
     *
     * A provider publishes figures and says which way is good; where a
     * department stands among the others is the page's to work out, so the
     * placing is a service of the host's and never a module's. The renderer
     * is a RUNTIME behind a function on the extension: a page with no matrix
     * on it builds neither the builder nor the template.
     */
    $services->set('team.performance.matrix_placing', MatrixPlacing::class);

    $services->set('team.performance.matrix_view', MatrixViewBuilder::class)
        ->args([service('team.performance.matrix_placing')]);

    /*
     * AND THE TWO THINGS THE ORGANIZATION'S OWN PAGE IS MADE OF: one
     * card a topic, and one matrix whose columns ARE the topics. Both
     * are built from what the topics publish and never from a query of
     * their own, which is why a module that publishes a topic reaches
     * this page without the host writing a line for it.
     */
    $services->set('team.performance.topic_cards', TopicCards::class);
    $services->alias(TopicCards::class, 'team.performance.topic_cards');

    /*
     * AND THE SIX FIGURES EVERY SCREEN OF THE SECTION OPENS WITH, picked
     * by key from the host's own three topics — ruled, and a module
     * never changes the organization's own band.
     */
    $services->set('team.performance.organization_band', OrganizationBand::class);
    $services->alias(OrganizationBand::class, 'team.performance.organization_band');

    /*
     * AND THE REGISTER'S OWN BAND — what the departments DID rather than
     * how many of them there are, read from the same seam so a figure
     * there and the same figure on the performance page cannot disagree.
     */
    $services->set('team.performance.departments_band', DepartmentsBand::class);
    $services->alias(DepartmentsBand::class, 'team.performance.departments_band');

    $services->set('team.performance.across_topics', AcrossTopicsMatrix::class);
    $services->alias(AcrossTopicsMatrix::class, 'team.performance.across_topics');

    $services->set('team.twig.matrix', MatrixExtension::class)
        ->tag('twig.extension');

    $services->set('team.twig.matrix_runtime', MatrixRuntime::class)
        ->args([
            service('twig'),
            service('team.performance.matrix_view'),
        ])
        ->tag('twig.runtime');

    /*
     * THE ROW IN THE SIDEBAR — the half of "a module registers with the registry and
     * renders in the shell" that is rendering, and the one thing a module can
     * only do by hand.
     *
     * REGISTERED ONLY WHERE THERE IS A SHELL. ShellBundle is a
     * suggestion of this bundle rather than a requirement, and a service whose
     * class implements an interface nobody installed is a container that will
     * not compile. The guard costs nothing — neither ::class constant loads a
     * class — and it is what keeps the shell soft.
     *
     * THE TAG STRING IS WRITTEN OUT, exactly as the registry's is a few lines above,
     * and for the same reason: reading ShellBundle::NAV_TAG would load
     * the shell's bundle class, and this file has to be readable in an
     * installation that has no shell at all.
     */
    if (interface_exists(NavigationSourceInterface::class)) {
        /*
         * PERFORMANCE FILES UNDER OBSERVATORY, beside Areas — a way of
         * LOOKING at the organization rather than a corner of the org
         * chart. Its own source, because a section label is a place in
         * the sidebar and the shell merges two contributors to one
         * heading; keeping it separate keeps the two trees out of each
         * other's file.
         */
        $services->set('team.navigation.performance', PerformanceNavigation::class)
            ->args([
                service('router'),
                service('security.token_storage'),
                service('security.authorization_checker'),
                service('request_stack'),
                // THE SAME COLLECTOR THE REGISTER READS, so the tree and the
                // page cannot list two different sets of topics.
                service('team.performance_topics'),
                service(CurrentPeriodInterface::class)->nullOnInvalid(),
            ])
            ->tag('shell.nav_section');

        /*
         * WHICH CATEGORY EACH DEPARTMENT IS, said once. A department names a
         * category and never a colour; the shell resolves the index to the hue,
         * which is the only way the same department reads the same in both
         * palettes and again on imagery.
         */
        $services->set('team.department_palette', DepartmentPalette::class)
            ->args([service(DepartmentRepository::class)]);
        $services->alias(DepartmentPalette::class, 'team.department_palette');

        $services->set('team.navigation', TeamNavigation::class)
                ->args([
                    service('router'),
                    service('security.token_storage'),
                    service('security.authorization_checker'),
                    service('request_stack'),
                    // The register's own picker lives in the sidebar, so the tree
                    // reads the same list the page draws.
                    service(DepartmentRepository::class),
                    // And the same category each department wears everywhere else,
                    // so the dot in the tree and the mark on the card agree.
                    service('team.department_palette'),
                ])
                ->tag('shell.nav_section');
    }

    /*
     * DEPARTMENTS ON AN AREA'S CONFIGURE STRIP. The contract is in the
     * contracts package, which this bundle already carries, and the area
     * bundle collects whatever is tagged — so an installation with no areas
     * simply has nothing that collects it.
     */
    /*
     * THE ONLY THING IN THE CORE THAT REMEMBERS — what each figure was in
     * each period that has closed, which every movement on the performance
     * page is measured against.
     */
    /*
     * THE FIGURES EVERY DEPARTMENT HAS WHATEVER IT ATTACHES — counted once,
     * for the snapshot, the matrix and the band alike.
     */
    /*
     * SINCE WHEN A POST HAS STOOD EMPTY — written at the moment it becomes
     * true, because the day cannot be recovered afterwards.
     */
    $services->set('team.position_vacancy', PositionVacancy::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(UserRepository::class),
        ]);
    $services->alias(PositionVacancy::class, 'team.position_vacancy');

    /*
     * WHO IS IN A DEPARTMENT, derived from each person's placement. A
     * department is a placement, not an owner, so neither its members nor
     * its positions have a column - and the derivation lives in one service
     * rather than in each screen that needs it.
     */
    $services->set('team.department_membership', DepartmentMembership::class)
        ->args([service(UserRepository::class)]);
    $services->alias(DepartmentMembership::class, 'team.department_membership');

    $services->set('team.staffing_figures', StaffingFigures::class)
        ->args([
            service('team.department_membership'),
            service(UserRepository::class),
        ]);
    $services->alias(StaffingFigures::class, 'team.staffing_figures');

    /*
     * THE HOST'S OWN FIRST TOPIC, published through the very seam a module
     * publishes one through — so one renderer draws both, and the host's
     * cannot quietly acquire an ability a module's lacks.
     */
    /*
     * WHO THE DEPARTMENTS ARE, FOR A TOPIC — the one read that joins this
     * bundle's departments to the registry's area × module ledger, so a
     * module publishing a topic reaches across no boundary at all.
     */
    $services->set('team.department_directory', DepartmentDirectory::class)
        ->args([
            service(DepartmentRepository::class),
            service(AreaModuleRepository::class),
        ]);
    $services->alias(DepartmentDirectory::class, 'team.department_directory');
    /* A MODULE TYPE-HINTS THE CONTRACT, never this class. */
    $services->alias(DepartmentDirectoryInterface::class, 'team.department_directory');

    $services->set('team.performance.staffing_topic', StaffingTopic::class)
        ->args([
            service(DepartmentRepository::class),
            service('team.staffing_figures'),
            service('team.performance_history'),
            service('team.department_membership'),
            service(UserRepository::class),
            service('team.position_vacancy'),
        ])
        ->tag(PerformanceTopicProviderInterface::TAG);
    $services->alias(StaffingTopic::class, 'team.performance.staffing_topic');

    /*
     * AND WHAT EACH DEPARTMENT SAID IT WOULD DO. The second host topic:
     * every department can declare a goal, so every department is a row,
     * and a goal's state is derived from the figure it names at the
     * moment of asking rather than stored — which is why this needs the
     * KPI collector and not a table of verdicts.
     */
    $services->set('team.performance.goals_topic', GoalsTopic::class)
        ->args([
            service(DepartmentRepository::class),
            service(DepartmentGoalRepository::class),
            service('team.department_performance'),
        ])
        ->tag(PerformanceTopicProviderInterface::TAG);
    $services->alias(GoalsTopic::class, 'team.performance.goals_topic');

    /*
     * AND WHAT THE MODULES PUT IN FRONT OF SOMEBODY. The third host
     * topic is the one the host cannot compute: only a module raises an
     * item or writes a record, so this adds up what the other topics
     * publish — found BY ROLE, because a module calls its records
     * "cases" or "sightings" and matching words would be a guess.
     *
     * IT TAKES THE TAGGED ITERATOR AND NOT THE COLLECTOR: the collector
     * holds this topic too, and asking it would be a circle. The
     * iterator is lazy and the host's own topics are skipped by slug.
     */
    $services->set('team.performance.attention_topic', AttentionTopic::class)
        ->args([
            service(DepartmentRepository::class),
            service(DepartmentGoalRepository::class),
            service('team.staffing_figures'),
            tagged_iterator(PerformanceTopicProviderInterface::TAG),
        ])
        ->tag(PerformanceTopicProviderInterface::TAG);
    $services->alias(AttentionTopic::class, 'team.performance.attention_topic');

    /*
     * AND WHAT COLLECTS THEM: the host's topics first, then a module's in
     * the order the organization arranged its modules.
     */
    $services->set('team.performance_topics', PerformanceTopics::class)
        ->args([
            tagged_iterator(PerformanceTopicProviderInterface::TAG),
            service('registry.catalogue'),
        ]);
    $services->alias(PerformanceTopics::class, 'team.performance_topics');

    $services->set('team.performance_history', PerformanceHistory::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(DepartmentPeriodFigureRepository::class),
            service(InstallationPeriodFigureRepository::class),
        ]);
    $services->alias(PerformanceHistory::class, 'team.performance_history');

    $services->set('team.area_sections', DepartmentAreaSections::class)
        ->tag(AreaSectionsInterface::TAG);
    $services->alias(DepartmentAreaSections::class, 'team.area_sections');

    /*
     * AND THE DEPARTMENTS UNDER AN AREA IN THE SIDEBAR — the picker the
     * area's Departments tab does not carry, contributed for the same
     * reason the configure section is.
     */
    $services->set('team.area_nav_children', DepartmentAreaNavChildren::class)
        ->args([
            service('router'),
            service('request_stack'),
            service(DepartmentRepository::class),
        ])
        ->tag(AreaNavChildrenInterface::TAG);
    $services->alias(DepartmentAreaNavChildren::class, 'team.area_nav_children');

    /*
     * WHO THE TOP BAR NAMES — team's answer to the shell's user-badge contract.
     *
     * Team is the core bundle that owns the account, the position and the tier,
     * so it is the bundle that fills the card the shell draws. The interface lives
     * in the contracts (a hard dependency of this bundle), NOT in the shell,
     * which is why this needs no interface_exists guard the way the nav row does
     * and why it is registered unconditionally: implementing the contract costs
     * a contracts dependency this bundle already carries and a security service
     * it already has, never a dependency on the shell.
     *
     * IT TAKES `security.token_storage`, NOT `security.helper`. The helper is a
     * SecurityBundle class; the storage is security-core's, and it is the whole
     * question this source asks — who does the current token name. The service
     * id is SecurityBundle's either way, and this bundle's screens are
     * registered only where a firewall exists.
     *
     * ALIASED, NOT TAGGED. The shell reads one id, `shell.user_badge_source`,
     * and two sources claiming to know who is signed in is the disagreement the
     * contract exists to prevent — so this is an alias, single-claimant by design.
     * A host that wants a different card overrides the alias in its own config,
     * which beats a bundle's; where there is no shell, nothing reads the alias
     * and it is harmlessly inert.
     */
    $services->set('team.user_badge_source', UserBadgeSource::class)
        ->args([service('security.token_storage')]);
    $services->alias('shell.user_badge_source', 'team.user_badge_source');

    /*
     * THE SOLE-ACTIVE-SUPER-ADMIN INVARIANT. Every write path that lowers a
     * tier or deactivates an account asks this first, so the refusal happens
     * before anything is stored rather than after.
     */
    $services->set('team.super_admin_invariant', SuperAdminInvariant::class)
        ->args([service(UserRepository::class)]);

    /*
     * EVERY WAY AN ACCOUNT COMES INTO BEING OR CHANGES. Four callers — the two
     * add-somebody forms, the record page and the console an installation is
     * bootstrapped from — share one set of rules rather than each keeping a
     * copy. It asks nothing about who is signed in, which is what lets the
     * console call it when there is nobody to be.
     */
    $services->set('team.accounts', UserService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.user_password_hasher'),
            service('team.super_admin_invariant'),
            service('team.position_vacancy'),
            service(UserRepository::class),
        ]);

    /*
     * WHAT A POSITION IS AND WHAT IT GRANTS. The catalogue is a collaborator
     * rather than an argument, because what may be granted is a fact about the
     * installation's installed modules and not about the screen that is asking.
     */
    $services->set('team.positions', PositionService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('team.access.catalogue'),
            service(UserRepository::class),
        ]);

    /*
     * THE ORG CHART'S SHAPE. The audit line a scope change leaves is the
     * entity's own doing; this supplies who and why, and stores the result.
     */
    /*
     * WHAT EVERY POSITION SCREEN READS. The register's cards, the record's
     * matrix and the configure page's editor are three renderings of this one
     * derivation: the moment a template counts "12 of 21 concerns" for itself
     * the three disagree and nobody notices until an administrator does.
     */
    $services->set('team.position_board', PositionBoard::class)
        ->args([
            service(PositionRepository::class),
            service(UserRepository::class),
            service('team.access.catalogue'),
        ]);

    /* What this installation can truthfully say happened to a position. */
    $services->set('team.position_history', PositionHistory::class);

    $services->set('team.departments', DepartmentService::class)
        ->args([service('doctrine.orm.entity_manager')]);

    /*
     * A DEPARTMENT'S FIGURES ARE ITS ATTACHED MODULES' FIGURES. The providers
     * arrive as a tagged iterator, and the tag string is written out here rather
     * than read off the contract's constant for the reason the permission
     * catalogue's is: a literal keeps a package off the build classpath of
     * everything that reads it. The collaborator is the tag alone — the
     * department it is asked about comes from the screen.
     */
    $services->set('team.department_performance', DepartmentPerformance::class)
        ->args([tagged_iterator('uhifadhi.department_kpi')]);

    /*
     * THE THREE WRITES BEHIND THE DOORS A STRANGER REACHES. It knows nothing
     * about the request: signing every OTHER session out is a fact about the
     * browser in hand and stays with the screen.
     */
    $services->set('team.password_reset', PasswordResetService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('security.user_password_hasher'),
        ]);

    /*
     * The sign-in refusal for a deactivated account. NOT tagged: a user checker
     * is named by the FIREWALL (`user_checker:`), which is the installation's
     * file — so the service is registered and public here, and the README's
     * security block names its id. A tag would have been this bundle deciding
     * a firewall's shape for every installation that has one.
     */
    $services->set('team.user_checker', ActiveUserChecker::class)->public();

    /*
     * WHAT THE TEAM PAGE KNOWS BEFORE IT DRAWS A ROW — the counts and the
     * decisions waiting on a person, asked once for the whole surface rather
     * than once per widget.
     */
    $services->set('team.overview', TeamOverview::class)
        ->args([
            service(UserRepository::class),
            service(PositionRepository::class),
            service(DepartmentRepository::class),
        ]);

    /*
     * THE TWO DASHBOARD SURFACES. Both team screens ride the widget framework
     * rather than a copy of it, which is why ShellBundle is a hard
     * requirement of this bundle and not a suggestion: the roster and the matrix
     * are widget surfaces, and a surface with no framework under it is a page
     * whose six drawn directions can never be adopted.
     *
     * The tag goes on BY HAND. A reusable bundle is not autoconfigured, and a
     * surface that missed the tag has a working dashboard and an unreachable
     * registry entry — nothing renders differently until the day somebody runs
     * `widget:prune` and it reads their stored layouts as orphans.
     */
    $services->set('team.widget_surface.departments', DepartmentWidgets::class)
        ->tag(WidgetSurfaceInterface::TAG);

    /*
     * THE TEAM PAGE — the roster surface. Gated on team.manage by an attribute
     * on the action, so the gate is a permission this installation's matrix can
     * grant and revoke rather than a tier nobody can audit.
     */
    $services->set('team.controller.team', TeamController::class)
        ->args([
            service('twig'),
            service(UserRepository::class),
            service(PositionRepository::class),
            service(DepartmentRepository::class),
            service('team.overview'),
            service('security.token_storage'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(TeamController::class, 'team.controller.team')->public();

    /*
     * THE ROSTER'S WIDGET LIBRARY — chrome around the shell's shared
     * preset component. It takes the roster controller itself, because the
     * library previews the REAL widgets on REAL data and a second, thinner
     * context for the preview would be the one place the two screens could
     * disagree about what a widget shows.
     */

    /*
     * ONE PERSON'S RECORD, and the writes that change it. There is no delete
     * route and there will not be one: accounts are deactivated, never removed.
     */
    /*
     * WHAT THIS INSTALLATION CAN TRUTHFULLY SAY HAPPENED TO ONE PERSON —
     * derived from the stored facts that carry a date, because there is no
     * audit trail in this release and a card that invented the rest would be
     * a card nobody could act on.
     */
    $services->set('team.member_history', MemberHistory::class);
    $services->alias(MemberHistory::class, 'team.member_history');

    $services->set('team.controller.member', MemberController::class)
        ->args([
            service('twig'),
            service(UserRepository::class),
            service(PositionRepository::class),
            service('team.access.catalogue'),
            service('team.super_admin_invariant'),
            service('team.accounts'),
            service('security.csrf.token_manager'),
            service('router'),
            service('security.token_storage'),
            service('team.area_authority'),
            service('team.member_history'),
            service('team.password_reset'),
            service('team.mail'),
            // WHERE THIS PERSON WORKS, from whoever owns the ground.
            tagged_iterator(PersonPostingProviderInterface::TAG),
            service('team.posting_door'),
            tagged_iterator(StationPlateProviderInterface::TAG),
            service('team.position_board'),
            service(DepartmentRepository::class),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(MemberController::class, 'team.controller.member')->public();

    /*
     * WHERE A POSTING IS MADE. This bundle holds no areas, so the door is
     * resolved from the shell's scope source — the one place the areas, the
     * account and the voters are folded together — and is route-tolerant,
     * because the addresses belong to the application.
     */
    $services->set('team.posting_door', PostingDoorService::class)
        ->args([service('router'), service('shell.scopes')]);

    /*
     * POSITIONS AND PERMISSIONS — the matrix surface, and the one screen that
     * writes a grant.
     */
    $services->set('team.controller.position', PositionController::class)
        ->args([
            service('twig'),
            service(PositionRepository::class),
            service(UserRepository::class),
            service('team.access.catalogue'),
            service('team.position_board'),
            service('team.position_history'),
            service('team.positions'),
            service('security.csrf.token_manager'),
            service('router'),
            service('team.area_authority'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(PositionController::class, 'team.controller.position')->public();

    /*
     * THE ORG CHART'S HOME — departments, and the three writes that shape them.
     * The matrix groups by a department and the roster bands by one, so this is
     * the screen where a department is made in the first place.
     *
     * NOT A WIDGET SURFACE, so no widget service and no surface tag. The roster
     * and the matrix ride the framework because directions were DRAWN for them;
     * nothing was drawn for this one, and six invented renderings would be a
     * design made by the implementation.
     */
    $services->set('team.controller.department', DepartmentController::class)
        ->args([
            service('twig'),
            service(DepartmentRepository::class),
            service(PositionRepository::class),
            service('team.department_membership'),
            service(UserRepository::class),
            service('doctrine.orm.entity_manager'),
            service('team.departments'),
            service(DepartmentGoalRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
            service('security.token_storage'),
            service('team.area_authority'),
            service('registry.catalogue'),
            service('team.department_performance'),
            service('team.performance_topics'),
            service('team.performance.departments_band'),
            service('team.department_palette'),
            service(CurrentPeriodInterface::class)->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');
    $services->alias(DepartmentController::class, 'team.controller.department')->public();

    /*
     * PERFORMANCE — the organization's own surface, and the only page in
     * the product whose every figure belongs to somebody else. It reads
     * the topic collector, the department directory and nothing of its
     * own; the one decision it makes about another party's figures —
     * where a department stands — is the placing's, made once.
     */
    $services->set('team.controller.performance', PerformanceController::class)
        ->args([
            service('twig'),
            service('team.performance_topics'),
            service('team.department_directory'),
            service('team.performance.across_topics'),
            service('team.performance.topic_cards'),
            service('team.performance.organization_band'),
            service('router'),
            service('doctrine.orm.entity_manager'),
            service(CurrentPeriodInterface::class)->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');
    $services->alias(PerformanceController::class, 'team.controller.performance')->public();

    /*
     * THE DEPARTMENTS SECTION'S FRAME — its tab strip and its configure
     * sections, declared through the SAME contracts a module's are. A section
     * wears the area idiom, and the cheapest way to mean that is to reuse the
     * contract rather than to grow a second one: the shell resolves the strip,
     * the header and the one Configure action from the surface marker the
     * section's routes carry.
     *
     * NEITHER IS GUARDED BY interface_exists. Both contracts live in the
     * contracts package, which this bundle already carries; it is the SHELL
     * that is optional, and an installation without one simply has nothing
     * collecting these tags.
     */
    $services->set('team.department_section_tabs', DepartmentSectionTabs::class)
        ->tag(ModuleTabsInterface::TAG);
    $services->alias(DepartmentSectionTabs::class, 'team.department_section_tabs');

    $services->set('team.department_section_configuration', DepartmentSectionConfiguration::class)
        ->tag(ConfigurationSectionsInterface::TAG);
    $services->alias(DepartmentSectionConfiguration::class, 'team.department_section_configuration');

    /*
     * AND THE PERFORMANCE SECTION'S OWN, on the same two contracts. Its
     * pages were drawing a strip of their own and therefore never got the
     * frame's one Configure action; declaring the surface buys both and
     * costs the page the code it was using to fake one of them.
     */
    $services->set('team.performance_section_tabs', PerformanceSectionTabs::class)
        ->args([service('request_stack')])
        ->tag(ModuleTabsInterface::TAG);
    $services->alias(PerformanceSectionTabs::class, 'team.performance_section_tabs');

    $services->set('team.performance_section_configuration', PerformanceSectionConfiguration::class)
        ->tag(ConfigurationSectionsInterface::TAG);
    $services->alias(PerformanceSectionConfiguration::class, 'team.performance_section_configuration');

    $services->set('team.controller.performance_configure', PerformanceConfigureController::class)
        ->args([
            service('twig'),
            service('team.performance_topics'),
            service(CurrentPeriodInterface::class)->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');
    $services->alias(PerformanceConfigureController::class, 'team.controller.performance_configure')->public();

    /*
     * WHAT THE SECTION'S OVERVIEW READS. It owns no figure on that page: every
     * one of them is the register's, Team's or Performance's, which is what
     * makes a reading screen safe to open first.
     */
    $services->set('team.department_section_overview', DepartmentSectionOverview::class)
        ->args([
            service(DepartmentRepository::class),
            service(PositionRepository::class),
            service('team.department_membership'),
            service(UserRepository::class),
            service(DepartmentGoalRepository::class),
            service('registry.catalogue'),
        ]);
    $services->alias(DepartmentSectionOverview::class, 'team.department_section_overview');

    /*
     * WHO IS POSTED WHERE, ACROSS EVERY AREA. The stations and the uuids
     * standing at them come from whoever owns the ground, through the tag;
     * this bundle says who those people are. An installation with no ground
     * package yields no provider and the board says the installation has no
     * station, which is true.
     */
    $services->set('team.posting_board', PostingBoard::class)
        ->args([
            tagged_iterator(StationDirectoryInterface::TAG),
            service(UserRepository::class),
            service('router'),
        ]);
    $services->alias(PostingBoard::class, 'team.posting_board');

    $services->set('team.controller.postings', TeamPostingsController::class)
        ->args([service('twig'), service('team.posting_board')])
        ->tag('controller.service_arguments');
    $services->alias(TeamPostingsController::class, 'team.controller.postings')->public();

    /*
     * WHAT AUTHORITY EXISTS AND WHO HOLDS IT — the tier and the permission,
     * aggregated. There is no Role entity and this asks for none.
     */
    $services->set('team.roles_board', RolesBoard::class)
        ->args([
            service('team.access.catalogue'),
            service(PositionRepository::class),
            service(UserRepository::class),
            // THE INSTALLED MODULES, for the name on each band and for the
            // ones that declare nothing: a module drawn with no rows says
            // "installed and grants nothing", which is a different fact from
            // being absent. The tag string is the registry's, written out
            // rather than imported, because this bundle must boot in an
            // installation that has no registry at all.
            tagged_iterator('uhifadhi.module'),
        ]);
    $services->alias(RolesBoard::class, 'team.roles_board');

    $services->set('team.controller.roles', TeamRolesController::class)
        ->args([service('twig'), service('team.roles_board')])
        ->tag('controller.service_arguments');
    $services->alias(TeamRolesController::class, 'team.controller.roles')->public();

    /*
     * WHAT THE SECTION'S OVERVIEW READS. It owns no figure on that page: every
     * one of them is the register's, Positions' or the area's.
     */
    $services->set('team.section_overview', TeamSectionOverview::class)
        ->args([
            service(UserRepository::class),
            service(PositionRepository::class),
            service(DepartmentRepository::class),
            service('team.posting_board'),
            // THE ONLY THING IN THE CORE THAT REMEMBERS: a closed period
            // cannot be recomputed, so a movement is read and never worked
            // out again.
            service('team.performance_history'),
        ]);
    $services->alias(TeamSectionOverview::class, 'team.section_overview');

    $services->set('team.controller.section', TeamSectionController::class)
        ->args([service('twig'), service('team.section_overview')])
        ->tag('controller.service_arguments');
    $services->alias(TeamSectionController::class, 'team.controller.section')->public();

    $services->set('team.controller.configure', TeamConfigureController::class)
        ->args([
            service('twig'),
            service(UserRepository::class),
            service(DepartmentRepository::class),
            service('security.csrf.token_manager'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(TeamConfigureController::class, 'team.controller.configure')->public();

    /*
     * TEAM WEARS THE AREA IDIOM: the strip, the header and the one Configure
     * action come from the same two contracts a module's tabs use, so nothing
     * here is a second implementation of a strip.
     */
    $services->set('team.section_tabs', TeamSectionTabs::class)
        ->tag(ModuleTabsInterface::TAG);
    $services->set('team.section_configuration', TeamSectionConfiguration::class)
        ->tag(ConfigurationSectionsInterface::TAG);

    $services->set('team.controller.department_section', DepartmentSectionController::class)
        ->args([
            service('twig'),
            service('team.department_section_overview'),
            service(DepartmentRepository::class),
            service('registry.catalogue'),
            service('shell.widget.service'),
            service('shell.widget.endpoint'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(DepartmentSectionController::class, 'team.controller.department_section')->public();

    /*
     * THE DEPARTMENTS OVERVIEW'S OWN LIBRARY — the same framework the roster
     * and the positions matrix ride, handed this surface's catalogue.
     */
    $services->set('team.controller.department_widgets', DepartmentWidgetsController::class)
        ->args([
            service('twig'),
            service('router'),
            service('shell.widget.service'),
            service('shell.widget.endpoint'),
            service('team.controller.department_section'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(DepartmentWidgetsController::class, 'team.controller.department_widgets')->public();

    /*
     * THE ONE DOOR A KIND IS WRITTEN THROUGH — trimmed, not empty, unique.
     * Rules about a word live beside the word and not in the screen that
     * happens to ask, so a second caller gets the same answer.
     */
    $services->set('team.department_kind_service', DepartmentKindService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(DepartmentKindRepository::class),
        ]);
    $services->alias(DepartmentKindService::class, 'team.department_kind_service');

    $services->set('team.controller.department_configure', DepartmentConfigureController::class)
        ->args([
            service('twig'),
            service(DepartmentRepository::class),
            service(DepartmentKindRepository::class),
            service(DepartmentGoalRepository::class),
            service('team.department_kind_service'),
            service('security.csrf.token_manager'),
            service('router'),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(DepartmentConfigureController::class, 'team.controller.department_configure')->public();

    /*
     * ONE AREA'S DEPARTMENTS — the tab and its configure section. Team's
     * routes, because a department already knows which area it belongs to;
     * the area's tab strip picks them up by name, tolerantly.
     */
    $services->set('team.controller.area_department', AreaDepartmentController::class)
        ->args([
            service('twig'),
            service(DepartmentRepository::class),
            service(PositionRepository::class),
            service('team.department_membership'),
            service(UserRepository::class),
            service('doctrine.orm.entity_manager'),
            service('team.department_performance'),
            service('security.csrf.token_manager'),
            service('router'),
            service('registry.catalogue'),
            service('team.department_palette'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(AreaDepartmentController::class, 'team.controller.area_department')->public();

    /*
     * THE TWO LETTERS THIS BUNDLE SENDS, and the one question every screen that
     * offers to send one asks first.
     *
     * THE MAILER IS OPTIONAL AND nullOnInvalid() IS THE WHOLE MECHANISM:
     * symfony/mailer is a suggestion rather than a requirement, so an
     * installation that never sends mail does not carry it — and where the
     * package is absent, OR present with no transport configured, the service
     * simply is not in the container and this is constructed with null. One
     * check covers both, which is right, because from a screen's point of view
     * they are the same fact.
     */
    $services->set('team.mail', Mail::class)
        ->args([
            service('mailer.mailer')->nullOnInvalid(),
            param('team.mail_from'),
            param('team.installation_name'),
        ]);

    /*
     * ADDING SOMEBODY — both ways, side by side. One needs nothing from the
     * deployment; the other is offered and refused where there is no mailer.
     */
    $services->set('team.controller.invite', InviteController::class)
        ->args([
            service('twig'),
            service(UserRepository::class),
            service(PositionRepository::class),
            service('team.accounts'),
            service('security.csrf.token_manager'),
            service('router'),
            service('security.token_storage'),
            service('team.mail'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(InviteController::class, 'team.controller.invite')->public();

    /*
     * THE SELF-SERVICE SCREENS. Public by route and by design: they are the
     * three a stranger reaches with nobody to ask, so they are the one part of
     * this bundle that must work with no session at all.
     */
    $services->set('team.controller.reset', PasswordResetController::class)
        ->args([
            service('twig'),
            service(UserRepository::class),
            service('team.password_reset'),
            service('security.csrf.token_manager'),
            service('router'),
            service('security.token_storage'),
            service('team.mail'),
            param('team.after_sign_in_path'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(PasswordResetController::class, 'team.controller.reset')->public();

    /*
     * The sign-in screen. Registered unconditionally: this bundle requires
     * symfony/security-bundle outright, unlike a module that merely benefits
     * from one — a team bundle in an installation with no firewall would be a
     * user table nobody can ever become.
     *
     * The alias is what makes `SecurityController::login` resolvable from the
     * attribute route: Symfony's controller resolver looks the class name up in
     * the container, and a bundle's own services are private by default.
     */
    $services->set('team.controller.security', SecurityController::class)
        ->args([
            service('twig'),
            service('security.authentication_utils'),
            service('security.token_storage'),
            param('team.after_sign_in_path'),
            param('team.sign_in_lede'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(SecurityController::class, 'team.controller.security')->public();
};
