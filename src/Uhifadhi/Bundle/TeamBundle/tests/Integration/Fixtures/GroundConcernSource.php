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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE GROUND'S CONCERNS, PLAYED BY A FIXTURE — the installation's end of a
 * resolution this bundle cannot make itself.
 *
 * WHY IT IS HERE. A person's record draws a door to where a posting is made,
 * and that page belongs to the area bundle: the door names `stations.read`
 * and `assignments.manage`, which are the AREA's declarations. This bundle
 * must not require the area bundle to be testable, so its kernel answers for
 * the installation, exactly as it does for a module's concern
 * ({@see DeclaringConcernSource}).
 *
 * IT MIRRORS THE REAL DECLARATION AND IS ONLY AS TRUE AS THE DAY IT WAS
 * WRITTEN — the debt every stub carries. What holds the two together is the
 * core's own suite, where the real `AreaConcerns` is in the kernel and the
 * same door is drawn.
 */
final readonly class GroundConcernSource implements ConcernSourceInterface
{
    public function declaredBy(): string
    {
        return 'Ground';
    }

    public function concerns(): iterable
    {
        $ground = [ScopeKind::Organization, ScopeKind::Area];

        yield new Concern(
            key: 'stations',
            label: 'Stations',
            description: 'The places on the ground people are posted to.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $ground,
        );

        // The control room's grant: the one exception to the rank rule.
        yield new Concern(
            key: 'locations',
            label: 'Live locations',
            description: 'See the live position of everybody on the ground, whatever their rank.',
            verbs: [Verb::Read],
            scopeKinds: $ground,
            sensitive: true,
            lifts: 'the rank rule',
        );

        yield new Concern(
            key: 'assignments',
            label: 'Assignments',
            description: 'Who stands at which station, and who leads there.',
            verbs: [Verb::Manage],
            scopeKinds: $ground,
        );
    }
}
