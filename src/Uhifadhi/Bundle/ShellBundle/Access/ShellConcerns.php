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

namespace Uhifadhi\Bundle\ShellBundle\Access;

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE SHELL'S ONE CONCERN: THE SETTINGS SECTION.
 *
 * Settings reads what the installation is — its organization, its modules,
 * the health of what it runs on — and that is an administrator's reading,
 * not a ranger's. Held by a position, never by being signed in.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, and the shell enforces this one:
 * the section's source withholds its row and the section's controller refuses
 * the address. The renderer still asks nothing — {@see \Uhifadhi\Bundle\ShellBundle\Service\Navigation}
 * draws what the sources hand it.
 *
 * READ ONLY, because nothing on the section changes anything of its own:
 * each screen's figures and doors belong to whoever contributes them, and
 * each of those is gated by its own pair.
 *
 * ORGANIZATION ONLY: there is one installation, and no area of it has
 * settings of its own here.
 */
final readonly class ShellConcerns implements ConcernSourceInterface
{
    /** The key, spelt once, so a gate, a door and a test cannot disagree. */
    public const string SETTINGS = 'settings';

    /** The pair the section asks for. */
    public const string SETTINGS_READ = self::SETTINGS.'.read';

    public function declaredBy(): string
    {
        return 'Settings';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::SETTINGS,
            label: 'Settings',
            description: 'The settings section: the organization, the installation, and the modules it runs.',
            verbs: [Verb::Read],
            scopeKinds: [ScopeKind::Organization],
        );
    }
}
