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

namespace Uhifadhi\Bundle\AreaBundle\Devkit;

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaCreator;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;

/**
 * THE GROUND EVERYTHING ELSE HANGS ON — a couple of areas, each with a boundary,
 * so a developer's first register is a populated one and every screen scoped to
 * an area has one to be scoped to.
 *
 * IT GOES THROUGH {@see AreaCreator}, the same service the create screen and
 * the console importer use, with the boundary handed in already read — which is
 * exactly what that service asks of every caller.
 *
 * IT SEEDS ONCE, PER AREA. An area whose name is already in the register is
 * left alone, so a second `fixtures:seed` adds nothing and a developer who
 * removed one of them gets it back without the other being duplicated.
 *
 * IT IS COLLECTED, NOT RUN — devkit installs through `require-dev`, so in a
 * production build nothing asks this anything.
 *
 * @see SeedArea for every coordinate, and for why they are where they are
 */
final readonly class AreaContentProvider implements ContentProviderInterface
{
    /** What the provenance column says, where a real boundary would name a file. */
    private const string SOURCE = 'Seed content shipped with the core.';

    public function __construct(
        private AreaCreator $creator,
        private AreaOfInterestRepository $register,
    ) {
    }

    public function key(): string
    {
        return 'area';
    }

    public function label(): string
    {
        return 'Areas';
    }

    public function description(): string
    {
        return 'A short register of areas, each with a boundary to draw.';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function load(): void
    {
        foreach (SeedArea::all() as $seed) {
            if (null !== $this->register->findOneBy(['name' => $seed->name])) {
                continue;
            }

            $this->creator->create(
                $seed->name,
                $seed->iucnCategory,
                $seed->establishedYear,
                $seed->boundary(),
                self::SOURCE,
            );
        }
    }
}
