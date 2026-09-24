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

use Uhifadhi\Contracts\People\PersonPosting;
use Uhifadhi\Contracts\People\PersonPostingProviderInterface;

/**
 * THE GROUND'S ANSWER TO "WHERE DOES THIS PERSON WORK?", faked: a test names
 * who is stationed, everybody else is stationed nowhere.
 */
final class FakePersonPostings implements PersonPostingProviderInterface
{
    public const string STATION = '019a0000-0000-7000-8000-00000000f1e1';
    public const string AREA = '019a0000-0000-7000-8000-00000000a0ea';

    /** @var list<string> the uuids of the people a test has stationed */
    public static array $stationed = [];

    public function postingsFor(array $userUuids): array
    {
        $out = [];
        foreach ($userUuids as $uuid) {
            $out[$uuid] = \in_array($uuid, self::$stationed, true)
                ? [new PersonPosting(self::STATION, 'Eastgate Post', 'ST-01', self::AREA, 'Sample Area', 'Crater', new \DateTimeImmutable('2024-01-14'), true, '/areas/'.self::AREA.'/stations/'.self::STATION)]
                : [];
        }

        return $out;
    }
}
