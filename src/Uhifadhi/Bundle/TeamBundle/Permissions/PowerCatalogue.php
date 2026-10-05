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

namespace Uhifadhi\Bundle\TeamBundle\Permissions;

use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerSourceInterface;

/**
 * EVERY POWER THE INSTALLATION'S PACKAGES DECLARE, folded together for the
 * Permissions page, each remembered with the source that knows its subjects.
 */
final readonly class PowerCatalogue
{
    /**
     * @param iterable<PowerSourceInterface> $sources
     */
    public function __construct(
        private iterable $sources,
    ) {
    }

    /** @return list<Power> */
    public function all(): array
    {
        return array_map(static fn (array $declared): Power => $declared[0], $this->declared());
    }

    /** @return list<string> every question any power asks */
    public function questions(): array
    {
        $questions = [];
        foreach ($this->all() as $power) {
            $questions = [...$questions, ...$power->questions];
        }

        return array_values(array_unique($questions));
    }

    public function sourceOf(Power $power): PowerSourceInterface
    {
        foreach ($this->declared() as [$declared, $source]) {
            if ($declared->key === $power->key) {
                return $source;
            }
        }

        throw new \LogicException(\sprintf('No source declares the power "%s".', $power->key));
    }

    /** @return list<array{Power, PowerSourceInterface}> */
    private function declared(): array
    {
        $declared = [];
        foreach ($this->sources as $source) {
            foreach ($source->powers() as $power) {
                $declared[] = [$power, $source];
            }
        }

        return $declared;
    }
}
