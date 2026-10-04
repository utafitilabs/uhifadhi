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

namespace Uhifadhi\Core\Tests\Core\Authority;

use Uhifadhi\Bundle\AreaBundle\Controller\AreaCreateController;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaEditController;
use Uhifadhi\Bundle\AreaBundle\Controller\StationEditController;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneEditController;
use Uhifadhi\Bundle\AreaBundle\Controller\ZoneImportController;
use Uhifadhi\Bundle\AreaBundle\Service\OrgOverviewCatalogue;
use Uhifadhi\Bundle\AreaBundle\Widget\AreaIndexWidgets;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\DepartmentController;
use Uhifadhi\Bundle\TeamBundle\Controller\InviteController;
use Uhifadhi\Bundle\TeamBundle\Controller\MemberController;
use Uhifadhi\Bundle\TeamBundle\Controller\PasswordResetController;
use Uhifadhi\Bundle\TeamBundle\Controller\PositionController;
use Uhifadhi\Bundle\TeamBundle\Controller\RankConfigureController;
use Uhifadhi\Bundle\TeamBundle\Controller\TeamConfigureController;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionPage;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Widget\DepartmentWidgets;
use Uhifadhi\Test\Authority\Probe;
use Uhifadhi\Test\Authority\World;

/**
 * A PROBE FOR EVERY ROUTE THE CORE MOUNTS, each with the identifiers of the
 * world it is sent into. A route with no probe and no stated reason fails
 * the build, so every route can be called by the table.
 */
final class CoreProbes
{
    /**
     * Routes not probed yet, each with the reason.
     *
     * @var array<string, string>
     */
    public const array PENDING = [
        'GET shell_module_configure' => 'a module surface: the module that contributes it probes it',
        'GET team_performance_topic' => 'a topic is contributed by a module, which probes its own',
        'GET api_doc' => 'the handset API, sent with a field token',
        'GET api_entrypoint' => 'the handset API, sent with a field token',
        'GET api_genid' => 'the handset API, sent with a field token',
        'GET api_validation_errors' => 'the handset API, sent with a field token',
        'GET _api_errors' => 'the handset API, sent with a field token',
        'GET _api_validation_errors_problem' => 'the handset API, sent with a field token',
        'GET _api_validation_errors_hydra' => 'the handset API, sent with a field token',
        'GET _api_validation_errors_jsonapi' => 'the handset API, sent with a field token',
        'GET _api_validation_errors_xml' => 'the handset API, sent with a field token',
        'GET _api_/areas/mine_get' => 'the handset API, sent with a field token',
        'GET _api_/areas/{areaUuid}/me/roster_get' => 'the handset API, sent with a field token',
        'GET _api_/areas/{areaUuid}/stations_get' => 'the handset API, sent with a field token',
        'GET _api_/me_get' => 'the handset API, sent with a field token',
        'POST team_api_auth_token' => 'the handset API: how a phone becomes somebody',
        'POST _api_/areas/{areaUuid}/checkins_post' => 'the handset API, sent with a field token',
        'PATCH _api_/areas/{areaUuid}/checkins/{clientRef}_patch' => 'the handset API, sent with a field token',
        'POST _api_/areas/{areaUuid}/positions_post' => 'the handset API, sent with a field token',
        'POST area_modules_toggle' => 'switches a module: the module that is switched probes it',
        'POST team_department_module_toggle' => 'switches a module: the module that is switched probes it',
    ];

    /**
     * Routes no request can reach, because another route claims the same
     * address first.
     *
     * @var array<string, string>
     */
    /**
     * WRITES NO SENDER HERE CAN SAVE, each with the reason. They are probed
     * all the same: the gate still answers before the reason does, so the
     * row still says who is refused, sent to sign-in or let through.
     *
     * @var array<string, string>
     */
    public const array NOT_SAVABLE_HERE = [
        'POST team_invite_send' => 'it mails the link, and the test application has no mail transport',
        'POST team_member_reset_link' => 'it mails the link, and the test application has no mail transport',
        'POST team_member_invite_again' => 'it mails the link, and the test application has no mail transport',
        'POST team_profile_email' => 'it mails the confirming link, and the test application has no mail transport',
        'POST organization_widgets_save' => "it edits the sender's own layout, and nobody here is on one",
        'POST department_widgets_save' => "it edits the sender's own layout, and nobody here is on one",
    ];

    public const array SHADOWED = [
        'GET organization_dashboard' => 'the test application mounts its own page at /, as an installation may; the welcome route answers there',
    ];

    /**
     * @return list<Probe>
     */
    public static function all(World $world): array
    {
        $area = ['uuid' => $world->kilimani];
        $department = ['uuid' => $world->operationsUuid];
        $member = ['uuid' => $world->member];
        $position = ['uuid' => $world->position];

        return [
            Probe::get('welcome'),
            Probe::get('liveness'),
            Probe::get('team_login'),
            Probe::get('team_logout'),
            Probe::get('team_reset_request'),
            Probe::get('team_reset', ['token' => $world->reset]),
            Probe::get('team_invite_accept', ['token' => $world->invitation]),

            Probe::get('my_dashboard'),
            Probe::get('me_station'),
            Probe::get('me_duty_log'),
            Probe::get('team_profile'),
            Probe::get('team_profile_email_confirm', ['token' => $world->emailChange]),
            Probe::get('organization_widgets'),

            Probe::get('settings', [], ['settings.read']),
            Probe::get('team_settings_deletions'),

            Probe::get('team_index'),
            Probe::get('team_overview'),
            Probe::get('team_assignments'),
            Probe::get('team_roles'),
            Probe::get('team_people_export'),
            Probe::get('team_invite'),
            ...Probe::aboutEachPerson(Probe::get('team_member', $member), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::get('team_member_configure', $member), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::get('team_member_delete', $member), 'uuid', $world),
            Probe::get('team_configure_people'),
            Probe::get('team_configure_positions'),
            Probe::get('team_configure_assignments'),
            Probe::get('team_configure_ranks'),
            Probe::get('team_ranks'),
            Probe::get('team_ranks_export'),
            Probe::get('team_positions'),
            Probe::get('team_position_show', $position),
            Probe::get('team_position_configure', $position),
            Probe::get('team_position_delete', $position),

            Probe::get('team_departments'),
            Probe::get('team_departments_overview'),
            Probe::get('team_departments_modules'),
            Probe::get('team_departments_configure'),
            Probe::get('team_departments_configure_lists'),
            Probe::get('department_widgets'),
            Probe::get('team_department_show', $department),
            Probe::get('team_department_configure', $department),
            Probe::get('team_department_configure', ['uuid' => $world->fieldPatrol]),
            Probe::get('team_department_delete', $department),
            Probe::get('team_performance'),
            Probe::get('team_performance_topics'),
            Probe::get('team_performance_briefing'),
            Probe::get('team_performance_configure'),

            Probe::get('area_index'),
            Probe::get('area_new'),
            Probe::get('area_widgets'),
            Probe::get('area_show', $area),
            Probe::get('area_edit', $area),
            Probe::get('area_delete', $area),
            Probe::get('area_modules', $area),
            Probe::get('area_departments', $area),
            Probe::get('shell_area_configure', $area),
            Probe::get('area_departments_configure', $area),
            Probe::get('area_modules_configure', $area),
            Probe::get('area_stations_configure', $area),
            Probe::get('area_zones_configure', $area),
            Probe::get('area_stations', $area),
            Probe::get('area_station_show', $area + ['station' => $world->station]),
            Probe::get('area_station_delete', $area + ['station' => $world->station]),
            Probe::get('area_zones', $area),
            Probe::get('area_zone_show', $area + ['zone' => $world->zone]),
            Probe::get('area_zones_export', $area),
            ...Probe::aboutEachPerson(Probe::get('area_live_sheet', ['person' => $world->member], ['areas.read']), 'person', $world),

            ...self::writes($world),
        ];
    }

    /**
     * EVERY WRITE, with the form it would send. The token is minted in the
     * sender's own session, so a refusal is the rules' and never the token's.
     *
     * @return list<Probe>
     */
    private static function writes(World $world): array
    {
        $area = ['uuid' => $world->kilimani];
        $station = $area + ['station' => $world->station];
        $zone = $area + ['zone' => $world->zone];
        $department = ['uuid' => $world->operationsUuid];
        $member = ['uuid' => $world->member];
        $position = ['uuid' => $world->position];
        $delete = DeletionPage::CSRF_ID;
        $person = MemberController::CSRF_ID;
        $seat = PositionController::CSRF_ID;
        $ranks = RankConfigureController::CSRF_ID;
        $team = DepartmentController::CSRF_ID;
        $boundary = __DIR__.'/kilimani.geojson';

        return [
            Probe::post('team_login', [], 'authenticate', ['_username' => 'nobody@unr.example', '_password' => 'not a password'], tokenField: '_csrf_token'),
            Probe::post('team_reset_send', [], PasswordResetController::CSRF_REQUEST, ['email' => 'rehema.kimaro@unr.example']),
            Probe::post('team_reset_submit', ['token' => $world->reset], PasswordResetController::CSRF_RESET, ['password' => 'a long new passphrase', 'password_confirm' => 'a long new passphrase']),
            Probe::post('team_invite_accept_submit', ['token' => $world->invitation], PasswordResetController::CSRF_ACCEPT, ['name' => 'Baraka Mollel', 'password' => 'a long new passphrase']),

            Probe::post('team_profile_details', [], $person, ['firstName' => 'Person', 'lastName' => 'Renamed', 'phone' => '+255 700 000 000']),
            Probe::post('team_profile_password', [], $person, ['currentPassword' => World::PASSPHRASE, 'newPassword' => 'a long new passphrase', 'again' => 'a long new passphrase']),
            Probe::post('team_profile_email', [], $person, ['email' => 'moved@unr.example']),
            Probe::post('team_profile_handset_sign_out', ['id' => (string) $world->handset], $person),

            Probe::post('team_member_create', [], InviteController::CSRF_CREATE, ['email' => 'zawadi.mushi@unr.example', 'password' => 'a long new passphrase', 'firstName' => 'Zawadi', 'lastName' => 'Mushi', 'position' => '', 'rangerCode' => '']),
            Probe::post('team_invite_send', [], InviteController::CSRF_INVITE, ['email' => 'juma.ally@unr.example', 'position' => '']),
            ...Probe::aboutEachPerson(Probe::post('team_member_update', $member, $person, ['firstName' => 'Naserian', 'lastName' => 'Lekishon', 'email' => 'naserian.l@unr.example', 'rangerCode' => '']), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::post('team_member_tier', $member, $person, ['tier' => TeamRoleEnum::Admin->value]), 'uuid', $world),
            // NOBODY RAISES THEMSELVES OR ANYBODY ELSE ABOVE WHAT THEY MAY GIVE:
            // only a Super Admin makes a Super Admin, an Admin of their own
            // record included.
            Probe::post('team_member_tier', $member, $person, ['tier' => TeamRoleEnum::SuperAdmin->value])->about('own record, to Super Admin', 'uuid', Probe::OWN),
            Probe::post('team_member_tier', $member, $person, ['tier' => TeamRoleEnum::SuperAdmin->value])->about('a colleague, to Super Admin', 'uuid', $world->member),
            ...Probe::aboutEachPerson(Probe::post('team_member_position', $member, $person, ['position' => $world->position, 'where' => 'areas', 'areas' => [$world->kilimani], 'department' => $world->operationsUuid]), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::post('team_member_reset_link', $member, $person), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::post('team_member_one_time_password', $member, $person), 'uuid', $world),
            Probe::post('team_member_invite_again', ['uuid' => $world->invited], $person),
            ...Probe::aboutEachPerson(Probe::post('team_member_deactivate', $member, $person), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::post('team_member_reactivate', $member, $person), 'uuid', $world),
            ...Probe::aboutEachPerson(Probe::post('team_member_delete', $member, $delete), 'uuid', $world, 'reference'),

            Probe::post('team_configure_people_save', [], TeamConfigureController::CSRF_ID, ['validAmount' => '7', 'validUnit' => 'days', 'uses' => '1', 'withPassword' => 'allowed']),
            Probe::post('team_configure_assignments_save', [], TeamConfigureController::CSRF_ID, ['twoStations' => 'allowed', 'leaders' => '1', 'emptyStation' => 'allowed']),

            Probe::post('team_position_create', [], $seat, ['name' => 'Radio Operator']),
            Probe::post('team_position_identity', $position, $seat, ['name' => 'Senior Ranger', 'allows' => ['area', 'organization']]),
            Probe::post('team_position_rename', $position, $seat, ['name' => 'Senior Ranger']),
            Probe::post('team_position_permissions', $position, $seat, ['grants' => ['areas.read']]),
            Probe::post('team_position_exception_give', $position + ['pair' => $world->exception], $seat, ['reason' => 'The control room watches every ranger on duty.']),
            Probe::post('team_position_exception_revoke', ['uuid' => $world->controlRoom, 'pair' => $world->exception], $seat),
            Probe::post('team_position_retire', ['uuid' => $world->controlRoom], $seat),
            Probe::post('team_position_delete', $position, $delete, ['reference' => 'Ranger']),

            Probe::post('team_configure_ranks_switch', [], $ranks, ['usesRanks' => '1']),
            Probe::post('team_configure_ranks_save', ['uuid' => $world->scale], $ranks, ['scaleName' => 'Rangers', 'newName' => 'Ranger II', 'newCode' => 'R2']),
            Probe::post('team_configure_ranks_remove', ['uuid' => $world->rank], $ranks),
            Probe::post('team_configure_ranks_move', ['uuid' => $world->rank, 'scaleUuid' => $world->otherScale], $ranks),
            Probe::post('team_configure_ranks_scale_add', [], $ranks, ['name' => 'Wardens', 'firstScaleName' => 'Rangers']),
            Probe::post('team_configure_ranks_scale_remove', ['uuid' => $world->otherScale], $ranks),

            Probe::post('team_department_create', [], $team, ['name' => 'Ecology', 'scope' => 'org']),
            Probe::post('team_department_rename', $department, $team, ['name' => 'Field Operations']),
            Probe::post('team_department_rename', ['uuid' => $world->fieldPatrol], $team, ['name' => 'Kilimani Patrol']),
            Probe::post('team_department_scope', $department, $team, ['reason' => 'It works in one reserve now.', 'area' => $world->kilimani]),
            Probe::post('team_department_goal_declare', $department, $team, ['statement' => 'Record every snare found', 'target' => '20', 'unit' => 'snares', 'direction' => 'at_least', 'owner' => '', 'kpiRef' => '']),
            Probe::post('team_department_goal_withdraw', $department + ['goal' => $world->goal], $team),
            Probe::post('team_department_deactivate', $department, $team),
            Probe::post('team_department_reactivate', $department, $team),
            Probe::post('team_department_delete', $department, $delete, ['reference' => World::OPERATIONS]),
            Probe::post('team_department_kind_create', [], DepartmentConfigureController::CSRF_ID, ['name' => 'Office', 'meaning' => 'Works from headquarters']),
            Probe::post('team_department_kind_rename', ['uuid' => $world->kind], DepartmentConfigureController::CSRF_ID, ['name' => 'Field work', 'meaning' => 'Works out on the ground']),

            ...self::widgets('organization_widgets', OrgOverviewCatalogue::SURFACE, $world->orgPreset, 'a'),
            ...self::widgets('department_widgets', DepartmentWidgets::SURFACE, $world->departmentPreset, 'default'),
            Probe::post('area_widgets_preset', ['presetId' => 'wall'], WidgetEndpoint::csrfTokenId(AreaIndexWidgets::SURFACE)),
            Probe::post('area_widgets_reset', [], WidgetEndpoint::csrfTokenId(AreaIndexWidgets::SURFACE)),

            Probe::post('area_new', [], AreaCreateController::TOKEN_ID, ['name' => 'Ziwa Game Reserve', 'boundary_mode' => 'later']),
            Probe::post('area_edit', $area, AreaEditController::IDENTITY_TOKEN, ['name' => World::KILIMANI]),
            Probe::post('area_boundary_replace', $area, AreaEditController::BOUNDARY_TOKEN, ['confirm' => '1'], ['boundary' => $boundary]),
            Probe::post('area_delete', $area, $delete, ['reference' => World::KILIMANI]),
            Probe::post('area_modules_reorder', $area, 'area_modules_'.$world->kilimani, ['order' => []]),

            Probe::post('area_station_add', $area, StationEditController::ADD_TOKEN, ['name' => 'Tembo Station', 'point' => '37.15,-2.85', 'elevation' => '', 'locality' => '', 'opened' => '']),
            Probe::post('area_station_rename', $station, StationEditController::EDIT_TOKEN, ['name' => 'Mlima Ranger Station']),
            Probe::post('area_station_move', $station, StationEditController::EDIT_TOKEN, ['point' => '37.12,-2.81']),
            Probe::post('area_station_describe', $station, StationEditController::EDIT_TOKEN, ['elevation' => '1400', 'locality' => 'On the ridge']),
            Probe::post('area_station_catchment', $station, StationEditController::EDIT_TOKEN, ['catchment' => '500']),
            Probe::post('area_station_activity', $station, StationEditController::EDIT_TOKEN, ['active' => '0', 'on' => '']),
            Probe::post('area_station_post', $station, StationEditController::POSTING_TOKEN, ['person' => $world->member]),
            Probe::post('area_posting_end', $area + ['posting' => $world->posting], StationEditController::POSTING_TOKEN),
            Probe::post('area_posting_lead', $area + ['posting' => $world->posting], StationEditController::POSTING_TOKEN),
            Probe::post('area_station_delete', $station, $delete, ['reference' => 'Mlima Station']),

            Probe::post('area_zone_rename', $zone, ZoneEditController::RENAME_TOKEN, ['name' => 'Lone Hills North']),
            Probe::post('area_zone_ring', $zone, ZoneEditController::RING_TOKEN),
            Probe::post('area_zone_remove', $zone, ZoneEditController::REMOVE_TOKEN),
            Probe::post('area_zones_clear', $area, ZoneEditController::CLEAR_TOKEN),
            Probe::post('area_zones_import_preview', $area, ZoneImportController::TOKEN, ['nameProperty' => 'name'], ['file' => __DIR__.'/zones.geojson']),
            Probe::post('area_zones_import_confirm', $area, ZoneImportController::TOKEN),
        ];
    }

    /**
     * The writes of one widget surface. They are the person's own layout,
     * so the custom layout they act on is the member's and nobody else's.
     *
     * @return list<Probe>
     */
    private static function widgets(string $prefix, string $surface, string $preset, string $design): array
    {
        $token = WidgetEndpoint::csrfTokenId($surface);
        $mine = ['presetUuid' => $preset];

        return [
            Probe::json($prefix.'_save', [], $token, '{"order":[],"widgets":{}}', WidgetDom::CSRF_HEADER),
            Probe::post($prefix.'_reset', [], $token),
            Probe::post($prefix.'_preset', ['presetId' => $design], $token),
            Probe::post($prefix.'_preset_copy', ['presetId' => $design], $token, ['name' => 'My morning']),
            Probe::post($prefix.'_preset_create', [], $token, ['name' => 'My evening']),
            Probe::post($prefix.'_preset_apply', $mine, $token),
            Probe::post($prefix.'_preset_rename', $mine, $token, ['name' => 'My afternoon']),
            Probe::post($prefix.'_preset_delete', $mine, $token),
        ];
    }
}
