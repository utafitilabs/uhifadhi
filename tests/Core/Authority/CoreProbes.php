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

/**
 * A PROBE FOR EVERY ROUTE THE CORE MOUNTS, each with the identifiers of the
 * world it is sent into. A route with no probe and no stated reason fails
 * the build, so every route can be called by the table.
 */
final class CoreProbes
{
    /**
     * WHAT IS NOT PROBED YET, and which part of the table brings it. A method
     * listed here covers every route that answers it.
     *
     * @var array<string, string>
     */
    public const array PENDING_METHODS = [
        'POST' => 'writes: a valid form body and its token',
        'PATCH' => 'writes: a valid form body and its token',
        'PUT' => 'writes: a valid form body and its token',
        'DELETE' => 'writes: a valid form body and its token',
    ];

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
    ];

    /**
     * Routes no request can reach, because another route claims the same
     * address first.
     *
     * @var array<string, string>
     */
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
            Probe::get('team_member', $member),
            Probe::get('team_member_configure', $member),
            Probe::get('team_member_delete', $member),
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
            Probe::get('area_live_sheet', ['person' => $world->member], ['areas.read']),
        ];
    }
}
