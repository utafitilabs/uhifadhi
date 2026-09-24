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

namespace Uhifadhi\Bundle\AreaBundle\Model;

use Symfony\Component\Security\Core\User\UserInterface as SecurityUserInterface;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * WHO DID IT, AS A LOG LINE NAMES THEM.
 *
 * PROVENANCE IS A STRING, on purpose: it has to survive the account being
 * removed, so it is written down at the time rather than pointed at. What
 * gets written down is therefore the last chance to write something a person
 * will recognise — and "n.kileo@uca.example imported 11 zones" is a sentence
 * about a login, where "N. Kileo imported 11 zones" is a sentence about a
 * colleague.
 *
 * THE NAME COMES FROM THE PUBLISHED CONTRACT, which every installation's
 * account class answers; the identifier is the fallback for an account that
 * answers only Symfony's. Resolved in one place because four screens record
 * an actor and four copies of this rule would be three chances to keep the
 * email.
 */
final readonly class Actor
{
    public static function of(?object $user): ?string
    {
        if ($user instanceof UserInterface) {
            $name = trim($user->getFullName());
            if ('' !== $name) {
                return $name;
            }
        }

        return $user instanceof SecurityUserInterface ? $user->getUserIdentifier() : null;
    }
}
