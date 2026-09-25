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

namespace Uhifadhi\Bundle\TeamBundle\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE TEAM'S CONCERNS: the directory, personal details, positions and
 * departments.
 *
 * PERSONAL DETAILS ARE THEIR OWN CONCERN, and that is the point of the
 * split. Knowing that somebody is on the team is what a colleague needs;
 * their contact details, their sign-in and their employment are not, and an
 * organization will reasonably want somebody to read the first without the
 * second. Declaring them separately is what lets the page stay open while
 * the half of it that is about a person stays shut.
 *
 * ADMINISTERING THE TEAM IS NOT A SEVENTH VERB. It is these concerns with
 * manage and configure on them.
 *
 * THERE IS NO DELETE HERE, AND THAT IS THE MODEL RATHER THAN AN OMISSION.
 * Nothing in this bundle destroys anything: a person who leaves is
 * deactivated so that everything they recorded keeps its author, and a
 * department that winds down is deactivated so its scope history stays on the
 * ledger. A declared delete nothing enforces would be a box an administrator
 * could tick that took nothing away, and the build test that walks the router
 * refuses it.
 *
 * THEY OFFER ORGANIZATION OR DEPARTMENT, department here meaning that
 * department's own members - never an area, because a person is not ground.
 */
final readonly class TeamConcerns implements ConcernSourceInterface
{
    /** The keys, spelt once, so a gate, a door and a test cannot disagree. */
    public const string DIRECTORY = 'directory';
    public const string PERSONAL_DETAILS = 'personal-details';
    public const string POSITIONS = 'positions';
    public const string DEPARTMENTS = 'departments';
    public const string RANKS = 'ranks';

    public function declaredBy(): string
    {
        return 'Team';
    }

    public function concerns(): iterable
    {
        $people = [ScopeKind::Organization, ScopeKind::Department];

        yield new Concern(
            key: self::DIRECTORY,
            label: 'Directory',
            description: 'Who is on the team: their name, their position and where they are placed.',
            verbs: [Verb::Read, Verb::Manage, Verb::Export],
            scopeKinds: $people,
        );

        yield new Concern(
            key: self::PERSONAL_DETAILS,
            label: 'Personal details',
            description: 'A person\'s contact details, sign-in and employment - distinct from knowing they are on the team.',
            verbs: [Verb::Read, Verb::Manage],
            scopeKinds: $people,
            sensitive: true,
        );

        yield new Concern(
            key: self::POSITIONS,
            label: 'Positions',
            description: 'The positions the organization has written, what each one grants, and who holds it.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $people,
        );

        yield new Concern(
            key: self::DEPARTMENTS,
            label: 'Departments',
            description: 'The departments the organization is arranged into, and the modules each one runs.',
            verbs: [Verb::Read, Verb::Configure],
            scopeKinds: $people,
        );

        yield new Concern(
            key: self::RANKS,
            label: 'Ranks',
            description: 'The ranks the organization keeps, in seniority order, and who holds each. A rank grants nothing.',
            verbs: [Verb::Read, Verb::Configure, Verb::Export],
            scopeKinds: $people,
        );
    }
}
