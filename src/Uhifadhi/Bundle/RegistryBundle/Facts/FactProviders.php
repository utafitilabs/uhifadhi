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

namespace Uhifadhi\Bundle\RegistryBundle\Facts;

use Uhifadhi\Contracts\Facts\FactProviderInterface;
use Uhifadhi\Contracts\Facts\FigureDefinition;

/**
 * EVERY MODULE THAT COMPUTES FACTS, collected from the `uhifadhi.facts` tag
 * in registration order, and every figure they declared.
 *
 * ONE FIGURE, ONE OWNER. Two providers declaring the same key would write
 * over each other's rows on every run, so a second declaration is refused
 * the first time the set is read — at the first run or the first page, not
 * silently at the thousandth.
 */
final class FactProviders
{
    /** @var array<string, FigureDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param iterable<FactProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    /**
     * The providers, or one module's.
     *
     * @return list<FactProviderInterface>
     */
    public function all(?string $moduleSlug = null): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            if (null === $moduleSlug || $provider->moduleSlug() === $moduleSlug) {
                $all[] = $provider;
            }
        }

        return $all;
    }

    /** The declaration of one figure, or null for a figure nobody computes. */
    public function definition(string $figureKey): ?FigureDefinition
    {
        return $this->definitions()[$figureKey] ?? null;
    }

    /**
     * @return array<string, FigureDefinition>
     */
    public function definitions(): array
    {
        if (null !== $this->definitions) {
            return $this->definitions;
        }

        $definitions = [];
        $owners = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->figures() as $figure) {
                if (isset($owners[$figure->key])) {
                    throw new \LogicException(\sprintf('The figure "%s" is declared by both "%s" and "%s"; a figure has one owner.', $figure->key, $owners[$figure->key], $provider->moduleSlug()));
                }
                $owners[$figure->key] = $provider->moduleSlug();
                $definitions[$figure->key] = $figure;
            }
        }

        return $this->definitions = $definitions;
    }
}
