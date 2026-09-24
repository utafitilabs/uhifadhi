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

namespace Uhifadhi\Contracts\Shell;

/**
 * WHO THE TOP BAR NAMES — the card at the right of the frame's top bar.
 *
 * Note the shape of the question, and that it mirrors the area contract's: the shell
 * passes nothing. It holds no security service, has no token to read and asks
 * nothing about the viewer, so the source resolves the current request itself —
 * which a host or a team-aware bundle is already positioned to do — and hands
 * back an already-composed {@see UserBadge}: a name, its initials and an
 * optional context line like "UCA · operator".
 *
 * IT HANDS OVER STRINGS, NOT AN ACCOUNT. The shell requires no module and above
 * all not the package that defines a user, so this contract cannot traffic in a
 * UserInterface. Whoever knows who is signed in folds the account, its
 * organization and its role into the three fields the card draws — a reading for
 * a person on a page, which is the job of whichever bundle owns the account by
 * the same argument the navigation contract uses.
 *
 * WHERE THE RICHER LINE COMES FROM. A bare source that knows only a name builds
 * a badge with {@see UserBadge::fromName()} and gets derived initials and no
 * context line. The organization-and-role line ("UCA · operator"), a chosen
 * avatar, a tier label — that is team/host knowledge, and a team-aware source
 * supplies it by building the value object directly. The shell draws whatever
 * arrives and knows none of it.
 *
 * A host or a core bundle points the shell at its implementation by aliasing
 * the id the shell looks for — an ALIAS, not a tagged collection, because two
 * things claiming to know who is signed in is exactly the kind of disagreement
 * this contract exists to prevent:
 *
 *     $services->alias('shell.user_badge_source', Uhifadhi\Team\Shell\UserBadgeSource::class);
 *
 * The alias is OPTIONAL. A fresh installation with no team yet, a sign-in page,
 * a print view — none declare it, and the shell renders a top bar with no card
 * rather than refusing to boot. Return null for the same reason on a request
 * that has no viewer even where a source is registered.
 */
interface UserBadgeSourceInterface
{
    /**
     * The viewer's card, or null when there is no viewer to name — anonymous
     * requests, and installations that have grown no team yet.
     */
    public function badge(): ?UserBadge;
}
