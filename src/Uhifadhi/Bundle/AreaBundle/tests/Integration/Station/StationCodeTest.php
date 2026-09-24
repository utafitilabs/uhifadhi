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

namespace Uhifadhi\Bundle\AreaBundle\Tests\Integration\Station;

use PHPUnit\Framework\Attributes\CoversClass;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Bundle\AreaBundle\Tests\Integration\IntegrationTestCase;

/**
 * A CODE IS ISSUED ON SAVE, and it is the area's own sequence.
 *
 * WHAT A CODE IS FOR. A radio call and a paper form say ST-04, not "the
 * ranger post on the crater rim road", so every post gets a short identifier
 * the moment it is recorded — without anybody being asked to invent one, and
 * without two areas having to agree about numbering they share nothing else
 * with.
 *
 * IT COUNTS UP, NEVER BACK INTO A GAP. Reissuing a retired post's code would
 * make two different places in the archive read as one place.
 */
#[CoversClass(StationService::class)]
final class StationCodeTest extends IntegrationTestCase
{
    public function testThefirstPostOfAnAreaIsIssuedTheFirstCode(): void
    {
        $station = $this->stations()->add($this->anArea(), 'Eastgate Post', -29.75, -3.2);

        self::assertSame('ST-01', $station->getCode());
    }

    public function testEachPostTakesTheNextCodeInItsOwnAreasSequence(): void
    {
        $first = $this->anArea('First Reserve');
        $second = $this->anArea('Second Reserve');

        $this->stations()->add($first, 'Eastgate Post', -29.75, -3.2);
        $next = $this->stations()->add($first, 'Fig Tree Ranger Station', -29.8, -3.2);
        $elsewhere = $this->stations()->add($second, 'Munge Camp', -29.6, -3.2);

        self::assertSame('ST-02', $next->getCode());
        self::assertSame('ST-01', $elsewhere->getCode(), 'Each area numbers its own stations.');
    }

    /** A code somebody typed is theirs, and the sequence follows it rather than fighting it. */
    public function testACodeGivenByHandIsKeptAndTheSequenceContinuesPastIt(): void
    {
        $area = $this->anArea();

        $given = $this->stations()->add($area, 'Eastgate Post', -29.75, -3.2, 'ST-07');
        $next = $this->stations()->add($area, 'Fig Tree Ranger Station', -29.8, -3.2);

        self::assertSame('ST-07', $given->getCode());
        self::assertSame('ST-08', $next->getCode());
    }

    private function stations(): StationService
    {
        /** @var StationService $service */
        $service = static::getContainer()->get('test_public.area.stations');

        return $service;
    }
}
