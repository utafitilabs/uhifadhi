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
 * A MODULE THAT COMPUTES TWO FIGURES ON A SCHEDULE, for two areas: metres
 * surveyed, which a quarter adds up, and the share surveyed, which it does not.
 *
 * Its answers are a function of the period, so a specification can say what
 * a month should hold without a database of surveys; {@see $scale} stands in
 * for a rule change an operator rebuilds after. Every request it is handed is
 * kept, in order, so a specification can say what it was asked.
 */
final class SurveyFactProvider implements FactProviderInterface
{
    public const string NORTH = '0199a1a0-0000-7000-8000-00000000000a';
    public const string SOUTH = '0199a1a0-0000-7000-8000-00000000000b';

    /** @var list<FactRequest> */
    public static array $asked = [];

    public static float $scale = 1.0;

    public static function reset(): void
    {
        self::$asked = [];
        self::$scale = 1.0;
    }

    public function moduleSlug(): string
    {
        return 'surveys';
    }

    public function figures(): array
    {
        return [
            new FigureDefinition('surveys.metres', FactSubject::AREA, additive: true),
            new FigureDefinition('surveys.share', FactSubject::AREA, additive: false),
        ];
    }

    public function compute(FactRequest $request): iterable
    {
        self::$asked[] = $request;

        foreach ([self::NORTH => 1.0, self::SOUTH => 2.0] as $area => $weight) {
            if (!$request->covers($area)) {
                continue;
            }

            if ($request->asks('surveys.metres')) {
                yield new FactValue(FactSubject::AREA, $area, 'surveys.metres', $weight * (float) $request->period->from->format('n') * 100.0 * self::$scale);
            }
            if ($request->asks('surveys.share')) {
                yield new FactValue(FactSubject::AREA, $area, 'surveys.share', 0.25 * $weight * self::$scale);
            }
        }
    }
}
