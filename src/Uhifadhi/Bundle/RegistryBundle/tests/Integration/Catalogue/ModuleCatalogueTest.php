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

namespace Uhifadhi\Bundle\RegistryBundle\Tests\Integration\Catalogue;

use Uhifadhi\Bundle\RegistryBundle\Service\ModuleCatalogue;
use Uhifadhi\Bundle\RegistryBundle\Tests\Integration\InstallationTestCase;

/**
 * SPEC 1 & 2 — REGISTRATION, AND ZERO.
 *
 * "Install the bundle and you are in the catalogue" is two claims. The first —
 * that a tagged provider reaches the registry — is already green in
 * tests/Integration, because the tag and its autoconfiguration moved here with
 * the bundle. This is the second: that what reached the registry is then READABLE
 * as a catalogue, by every surface that wants to draw one, without any of them
 * walking the tag themselves.
 *
 * That single reading is the point. Two surfaces querying the module tables a
 * query apart is exactly how a card comes to say "8 in the catalogue" over two
 * rows that list seven; the host's ledger exists because that happened. One
 * catalogue, read once.
 */
final class ModuleCatalogueTest extends InstallationTestCase
{
    private function catalogue(): ModuleCatalogue
    {
        $catalogue = $this->service('registry.catalogue');
        \assert($catalogue instanceof ModuleCatalogue);

        return $catalogue;
    }

    /**
     * ZERO IS A REAL NUMBER OF MODULES, and the first one every installation
     * has. Skeleton + registry and nothing else must give a working, empty
     * catalogue — a runtime that only functions once a module is installed has
     * a hidden dependency on its own modules. The seed command runs, succeeds,
     * and writes nothing.
     */
    public function testAnInstallationWithNoModulesHasAnEmptyCatalogue(): void
    {
        $this->install([]);

        self::assertSame([], $this->catalogue()->all());
        self::assertNull($this->catalogue()->find('sightings'));
    }

    public function testATaggedProviderAppearsInTheCatalogue(): void
    {
        $this->install(['sightings', 'ferries']);

        self::assertSame(
            ['sightings', 'ferries'],
            array_map(static fn (object $row): ?string => $row->getSlug(), $this->catalogue()->all()),
        );
    }

    /**
     * THE CATALOGUE IS THE INSTALLED SET, not a table that remembers. Removing a
     * bundle removes its module — the catalogue must not keep offering a tile
     * that links to code nobody has any more.
     *
     * What the catalogue stops OFFERING is not the same as what the database
     * still holds: the row and the area's data survive an uninstall, so
     * reinstalling the bundle finds its history where it left it. The offer is
     * what goes.
     */
    public function testUninstallingABundleTakesItsModuleOutOfTheCatalogue(): void
    {
        $this->install(['sightings', 'ferries']);
        $this->install(['sightings'], freshDatabase: false);

        self::assertNull($this->catalogue()->find('ferries'), 'an uninstalled module is not on offer');
        self::assertNotNull($this->catalogue()->find('sightings'));
    }

    /**
     * The trait defaults are the contract's answer for everything a module did
     * not say, and they have to hold all the way into the catalogue — not just
     * as far as the tagged service. A module that declares three methods is a
     * live, unpinned, generically-rendered catalogue row.
     */
    public function testTheTraitDefaultsHoldThroughToTheCatalogueRow(): void
    {
        $this->install(['sightings']);

        $row = $this->catalogue()->find('sightings');
        self::assertNotNull($row);

        self::assertSame('live', $row->getStatus()->value);
        self::assertFalse($row->isPinned());
        self::assertSame('', $row->getDataSource(), 'no provenance line is an empty one, never a null in the tile');
        self::assertNull($row->getIcon(), 'no icon means the host default, decided at render time');
        self::assertNull($row->getDescription(), 'a module that says nothing beyond its name has no line, not an empty one');
    }

    /**
     * A module renames itself between releases and the catalogue follows: the
     * seed upserts by SLUG, which is the module's identity, and the display
     * name is data the provider owns. (The per-area on/off state is a different
     * question with a different answer — see SeedCatalogueCommandTest.).
     */
    public function testAModulesOwnMetadataIsRefreshedOnEverySeed(): void
    {
        $this->install(['sightings' => ['name' => 'Sightings']]);
        $this->install(['sightings' => ['name' => 'Wildlife sightings']], freshDatabase: false);

        self::assertSame('Wildlife sightings', $this->catalogue()->find('sightings')?->getName());
    }

    /**
     * WHAT A MODULE IS, IN ONE LINE, is the provider's to say and the sync's to
     * keep: a reworded line replaces the old one on the next sync, and a line
     * the module stops saying is cleared rather than left behind.
     */
    public function testTheCatalogueRowKeepsTheLineTheModuleSaysItIs(): void
    {
        $this->install(['sightings' => ['description' => 'Every animal somebody saw, and where.']]);
        self::assertSame('Every animal somebody saw, and where.', $this->catalogue()->find('sightings')?->getDescription());

        $this->install(['sightings' => ['description' => 'Every animal seen in the field, and where.']], freshDatabase: false);
        self::assertSame('Every animal seen in the field, and where.', $this->catalogue()->find('sightings')?->getDescription());

        $this->install(['sightings'], freshDatabase: false);
        self::assertNull($this->catalogue()->find('sightings')?->getDescription());
    }
}
