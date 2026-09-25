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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Fixtures;

use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FactRequest;
use Uhifadhi\Contracts\Facts\FactSubject;
use Uhifadhi\Contracts\Facts\FactValue;
use Uhifadhi\Contracts\Facts\FigureDefinition;

/**
 * A SECOND MODULE, so `--module=` has something to leave out.
 */
final class TallyFactProvider implements FactProviderInterface
{
    /** @var list<FactRequest> */
    public static array $asked = [];

    public function moduleSlug(): string
    {
        return 'tallies';
    }

    public function figures(): array
    {
        return [new FigureDefinition('tallies.count', FactSubject::INSTALLATION, additive: true)];
    }

    public function compute(FactRequest $request): iterable
    {
        self::$asked[] = $request;

        yield new FactValue(FactSubject::INSTALLATION, FactSubject::INSTALLATION_UUID, 'tallies.count', 7.0);
    }
}
