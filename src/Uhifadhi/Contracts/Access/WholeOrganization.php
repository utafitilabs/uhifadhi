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

namespace Uhifadhi\Contracts\Access;

/**
 * THE WHOLE ORGANIZATION, AS A SUBJECT. An act that reaches beyond any one
 * area — creating an area, changing a list the whole organization uses — is
 * asked with it: `isGranted('areas.configure', new WholeOrganization())`.
 * The pair must be held, and the person's authority must reach the whole
 * organization: an Admin or a Super Admin, or somebody placed across it.
 * Somebody placed at one area holds the pair there and nowhere beyond it.
 *
 * It is a contract so that any bundle asks the same question by the same
 * name, without knowing who answers it.
 *
 * @see https://symfony.com/doc/current/security/voters.html — "the second argument (if any) is passed as $subject"; a subject may be any value
 */
final readonly class WholeOrganization
{
}
