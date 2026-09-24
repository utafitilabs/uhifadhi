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

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;

/**
 * A module, dialled to whatever a given specification needs. Fictional on
 * purpose — the registry knows no real module by name, and neither does its suite.
 *
 * @phpstan-type Overrides array{
 *     name?: string, category?: string, status?: string, source?: ?string,
 *     pinned?: bool, base?: bool, position?: int, icon?: ?string,
 *     entryRoute?: ?string,
 * }
 */
final class SpecModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    /**
     * @param ?array<string, mixed> $overrides handed over directly by the specifications
     *                                         that need no container at all; null means
     *                                         "read them from the booted installation"
     */
    public function __construct(
        private readonly string $slug,
        private readonly ?array $overrides = null,
    ) {
    }

    /**
     * The answers this specification dialled in.
     *
     * TWO WAYS IN, because there are two kinds of specification here. A pure
     * mapping test constructs this provider itself and passes its overrides
     * straight to the constructor. A test that boots an installation cannot: the
     * container builds the provider, and a service definition argument can carry
     * only scalars, parameters and references — while a permission declaration is
     * an object. So that path passes the slug alone and the answers are read back
     * from the installation that was booted.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return $this->overrides ?? HostKernel::$modules[$this->slug] ?? [];
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return \is_string($this->overrides()['name'] ?? null)
            ? $this->overrides()['name']
            : ucfirst($this->slug);
    }

    public function category(): string
    {
        return \is_string($this->overrides()['category'] ?? null) ? $this->overrides()['category'] : 'operations';
    }

    public function status(): string
    {
        return \is_string($this->overrides()['status'] ?? null) ? $this->overrides()['status'] : 'live';
    }

    public function description(): ?string
    {
        $description = $this->overrides()['description'] ?? null;

        return \is_string($description) ? $description : null;
    }

    public function dataSource(): ?string
    {
        $source = $this->overrides()['source'] ?? null;

        return \is_string($source) ? $source : null;
    }

    public function pinned(): bool
    {
        return true === ($this->overrides()['pinned'] ?? false);
    }

    public function base(): bool
    {
        return true === ($this->overrides()['base'] ?? false);
    }

    public function position(): int
    {
        return \is_int($this->overrides()['position'] ?? null) ? $this->overrides()['position'] : 0;
    }

    public function icon(): ?string
    {
        $icon = $this->overrides()['icon'] ?? null;

        return \is_string($icon) ? $icon : null;
    }

    public function entryRoute(): ?string
    {
        $route = $this->overrides()['entryRoute'] ?? null;

        return \is_string($route) ? $route : null;
    }
}
