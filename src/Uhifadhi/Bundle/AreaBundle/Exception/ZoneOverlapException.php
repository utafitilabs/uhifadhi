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

namespace Uhifadhi\Bundle\AreaBundle\Exception;

use Uhifadhi\Bundle\AreaBundle\Entity\Zone;

/**
 * The zone invariant was broken: the geometry would share MORE THAN A SLIVER
 * of interior with a sibling zone.
 *
 * THE MESSAGE NAMES THE OTHER ZONE AND THE SIZE. "It overlaps something" is
 * useless to whoever is drawing it — one of the two has to be fixed — and
 * "overlaps Lone Hills" without a number does not say whether the file is wrong or
 * the stored zone is. The size answers that in four words.
 *
 * HOW MUCH IS A SLIVER IS THE AREA'S OWN — see {@see \Uhifadhi\Bundle\AreaBundle\Service\ZoneOverlapService}.
 */
final class ZoneOverlapException extends \RuntimeException
{
    public static function between(string $name, Zone $conflicting, int $km2): self
    {
        return self::betweenNames($name, $conflicting->getName() ?? '(unnamed)', $km2);
    }

    /** For an import, where the thing collided with is a feature in the file rather than a row. */
    public static function betweenNames(string $name, string $conflictingName, int $km2): self
    {
        return new self(\sprintf('"%s" overlaps "%s" by %s km²', $name, $conflictingName, number_format($km2)));
    }
}
