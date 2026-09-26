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

namespace Uhifadhi\Bundle\AreaBundle\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE GROUND'S CONCERNS: the areas, the zones they are divided into, the
 * stations somebody is stationed at, the assignments that put them there,
 * and the duty a handset reports.
 *
 * A VERB IS DECLARED WHERE SOMETHING ENFORCES IT. The six exist for every
 * concern to choose from, and these choose only what this bundle's routes and
 * doors actually gate: there is no delete for an area because nothing deletes
 * one, and no export for a station because nothing exports them. A declared
 * power nothing enforces is a box an administrator can tick that changes
 * nothing, which is worse than a missing one - and the build test that walks
 * the router holds this honest in both directions.
 *
 * THEY OFFER ORGANIZATION OR AREA, AND NOT DEPARTMENT, because ground is
 * where it is. A department runs modules over ground; it does not own the
 * ground, so there is no sense in which a boundary belongs to Ecology and
 * not to Protection.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, which is why these are here and
 * not in the bundle that owns people.
 */
final readonly class AreaConcerns implements ConcernSourceInterface
{
    /** The keys, spelt once, so a gate, a door and a test cannot disagree. */
    public const string AREAS = 'areas';
    public const string ZONES = 'zones';
    public const string STATIONS = 'stations';
    public const string ASSIGNMENTS = 'assignments';
    public const string DUTY = 'duty';
    public const string LOCATIONS = 'locations';

    public function declaredBy(): string
    {
        return 'Areas';
    }

    public function concerns(): iterable
    {
        $ground = [ScopeKind::Organization, ScopeKind::Area];

        yield new Concern(
            key: self::AREAS,
            label: 'Areas',
            description: 'The areas this installation manages, their boundaries and their settings.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $ground,
        );

        yield new Concern(
            key: self::ZONES,
            label: 'Zones',
            description: 'The zones an area is divided into, and the boundary file they are imported from.',
            verbs: [Verb::Read, Verb::Configure, Verb::Delete, Verb::Export],
            scopeKinds: $ground,
        );

        yield new Concern(
            key: self::STATIONS,
            label: 'Stations',
            description: 'The stations in an area - gates, ranger stations, headquarters, outposts - and the ground each one covers.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $ground,
        );

        yield new Concern(
            key: self::ASSIGNMENTS,
            label: 'Assignments',
            description: 'Who is stationed where: assigning somebody to a station, appointing a lead, and ending an assignment.',
            // NO READ, because nothing reads assignments as a thing of their
            // own: the station's record shows who stands there under
            // `stations.read`, and the team's board shows where its people
            // are under `directory.read`. Declaring one would be a row an
            // administrator could tick that took nothing away and gave
            // nothing. It arrives with the first screen that needs it.
            verbs: [Verb::Manage],
            scopeKinds: $ground,
        );

        /*
         * THE HANDSET'S OWN CONCERN. Nobody checks in from a desk: this
         * reaches the phone in the token and is why the Duty tab is there at
         * all. An account without it reads the park and does not report a
         * day.
         */
        yield new Concern(
            key: self::DUTY,
            label: 'Duty',
            description: 'Report the day from a handset - the status, the station and the positions that go with it.',
            // ONE VERB, because the handset's reads and its writes are one
            // permission today: the endpoints that hand a ranger their
            // roster and their stations ask the same question the check-in
            // does, since a phone that cannot report a day has no use for
            // either. Splitting them is a change to the handset, not to a
            // declaration.
            verbs: [Verb::Record],
            scopeKinds: $ground,
        );

        /*
         * THE CONTROL ROOM'S SIGHT. Without it a person sees the live
         * position of those junior to them in rank and nobody else - no
         * peer, no senior, and nobody at all without a rank. With it, the
         * seat sees everybody on the ground it may read, because the seat
         * carries the reason: coordinating a rescue, answering a radio call.
         * Enforced by {@see \Uhifadhi\Bundle\AreaBundle\Service\LiveVisibility}
         * on every drawn plate and on the hub's own subscription.
         */
        yield new Concern(
            key: self::LOCATIONS,
            label: 'Live locations',
            description: 'See the live position of everybody on the ground, whatever their rank - the control room. Without it a person sees only those junior to them.',
            verbs: [Verb::Read],
            scopeKinds: $ground,
        );
    }
}
