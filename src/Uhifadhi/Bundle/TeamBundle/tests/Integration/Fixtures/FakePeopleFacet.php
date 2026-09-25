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

use Uhifadhi\Contracts\People\PeopleFacet;
use Uhifadhi\Contracts\People\PeopleFacetProviderInterface;

/**
 * A MODULE'S DROPDOWN ON THE PEOPLE REGISTER, answered by a fixture: a
 * test hands it the facet to contribute, and a test that hands it nothing
 * contributes nothing — which is what the register must survive.
 */
final class FakePeopleFacet implements PeopleFacetProviderInterface
{
    public static ?PeopleFacet $facet = null;

    /** @var list<string> what the register last asked about */
    public static array $askedFor = [];

    public function facetFor(array $userUuids): ?PeopleFacet
    {
        self::$askedFor = $userUuids;

        return self::$facet;
    }
}
