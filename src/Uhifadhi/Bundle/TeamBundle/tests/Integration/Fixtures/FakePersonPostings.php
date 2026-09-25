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
 * WHERE PEOPLE WORK, answered by a fixture: this kernel installs no area
 * package, so the seam is answered the way an installation's ground answers
 * it. A test states who stands where in {@see $at}, keyed by the person's
 * uuid; everybody else stands nowhere.
 */
final class FakePersonPostings implements PersonPostingProviderInterface
{
    public const string STATION = '019a0000-0000-7000-8000-00000000f1e1';
    public const string AREA = '019a0000-0000-7000-8000-00000000a0ea';

    /** @var array<string, list<PersonPosting>> the standing postings a test has made, by person */
    public static array $at = [];

    /** The one station of the sample area, for a test that needs somebody stationed somewhere. */
    public static function eastgate(bool $leader = true): PersonPosting
    {
        return new PersonPosting(self::STATION, 'Eastgate Post', 'ST-01', self::AREA, 'Sample Area', 'Crater', new \DateTimeImmutable('2024-01-14'), $leader, '/areas/'.self::AREA.'/stations/'.self::STATION);
    }

    public function postingsFor(array $userUuids): array
    {
        $out = [];
        foreach ($userUuids as $uuid) {
            $out[$uuid] = self::$at[$uuid] ?? [];
        }

        return $out;
    }
}
