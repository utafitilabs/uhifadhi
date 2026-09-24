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

namespace Uhifadhi\Bundle\RegistryBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\RegistryBundle\Entity\AreaModule;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Repository\AreaModuleRepository;
use Uhifadhi\Bundle\RegistryBundle\Repository\ModuleRepository;
use Uhifadhi\Contracts\Entity\AreaInterface;
use Uhifadhi\Contracts\ModuleProviderInterface;

/**
 * RECONCILE THE REGISTRY WITH WHAT IS INSTALLED — what `registry:sync` does.
 *
 * PROVIDER-DRIVEN, WITHOUT EXCEPTION. Every row comes from a tagged
 * {@see ModuleProviderInterface}: a module declares itself and appears here,
 * and nothing else does. There is no row the runtime writes for itself and no
 * slug it recognises — `pinned` and `base` are flags a provider declares.
 *
 * CREATE-ONLY, AND THAT IS A PRODUCTION PROMISE. This runs on every deploy,
 * against a database with real areas configured by real admins, and there is no
 * undo. So the rule that matters most is what it does NOT touch:
 *
 *   the catalogue row is the MODULE'S — the provider owns its name, category,
 *   provenance and icon, and every run refreshes them;
 *   the per-area row is the ADMIN'S — the run creates it when it is missing and
 *   never revisits it again.
 *
 * An admin who parked a base module is not overruled by a deploy. A deploy that
 * reorders the catalogue does not reshuffle anybody's sub-nav. And an
 * uninstalled module's rows are LEFT ALONE rather than deleted: a bundle removed
 * by mistake and reinstalled next morning finds every area as it left it, and a
 * deploy step that deletes data on a missing dependency is a step nobody should
 * run on production.
 *
 * ZERO MODULES IS A SUCCESSFUL RUN. A fresh installation with no modules on it
 * reconciles nothing and says so.
 */
final readonly class RegistrySyncService
{
    /**
     * @param iterable<ModuleProviderInterface> $providers every tagged provider, in registration order
     */
    public function __construct(
        private EntityManagerInterface $em,
        private ModuleRepository $modules,
        private AreaModuleRepository $areaModules,
        private ProviderCatalogueMapper $mapper,
        private iterable $providers = [],
    ) {
    }

    public function sync(): RegistrySyncResult
    {
        if (!$this->tablesExist()) {
            return RegistrySyncResult::skipped();
        }

        // 1) Upsert the catalogue by SLUG, which is a module's identity. A module
        //    that renames itself between releases is followed; a module that
        //    changed nothing is written back identically.
        $bySlug = [];
        $added = [];
        $kept = [];
        $order = 0;
        foreach ($this->providers as $provider) {
            $row = $this->mapper->toRow($provider, $order++);

            $module = $this->modules->findBySlug($row['slug']);
            if (null === $module) {
                $module = new Module();
                $added[] = $row['slug'];
            } else {
                $kept[] = $row['slug'];
            }
            $module->setSlug($row['slug'])
                ->setName($row['name'])
                ->setCategory($row['category'])
                ->setStatus($row['status'])
                ->setDataSource($row['source'])
                ->setIcon($row['icon'])
                ->setPinned($row['pinned'])
                ->setPosition($row['position']);

            $this->em->persist($module);
            $bySlug[$row['slug']] = [$module, $row['active']];
        }
        $this->em->flush();

        // The rows whose provider is gone are named, not touched — see the
        // class docblock for why a deploy never deletes them.
        $retired = [];
        foreach ($this->modules->catalogue() as $module) {
            $slug = (string) $module->getSlug();
            if (!isset($bySlug[$slug])) {
                $retired[] = $slug;
            }
        }

        // 2) Backfill every area with the modules it has no row for at all —
        //    including the areas created between two deploys, which is the half
        //    of this that is not create-only-shaped and the reason it exists. An
        //    area that already has a row keeps it exactly as it is.
        $backfilled = 0;
        foreach ($this->areas() as $area) {
            $have = [];
            foreach ($this->areaModules->forArea($area) as $areaModule) {
                $have[(string) $areaModule->getModule()?->getSlug()] = true;
            }

            foreach ($bySlug as $slug => [$module, $active]) {
                if (isset($have[$slug])) {
                    continue;
                }
                $this->em->persist(new AreaModule()
                    ->setArea($area)
                    ->setModule($module)
                    ->setActive($active)
                    ->setPosition($module->getPosition()));
                ++$backfilled;
            }
        }
        $this->em->flush();

        return new RegistrySyncResult($added, $kept, $retired, $backfilled);
    }

    /**
     * THE FRESH-INSTALL GUARD. `registry:sync` can be typed BEFORE the first
     * `doctrine:migrations:migrate` — so the registry's own tables may be absent,
     * and asking the schema manager is cheaper and more honest than catching the
     * driver's error afterwards. The command turns this answer into its exit code.
     */
    private function tablesExist(): bool
    {
        $connection = $this->em->getConnection();

        $tables = [];
        foreach ([Module::class, AreaModule::class] as $entity) {
            $tables[] = $this->em->getClassMetadata($entity)->getTableName();
        }

        return $connection->createSchemaManager()->tablesExist($tables);
    }

    /**
     * EVERY AREA, WITHOUT KNOWING WHAT AN AREA IS. The host resolved
     * {@see AreaInterface} to its own entity when it mapped this bundle's
     * association, so the concrete class is already recorded there — asking
     * Doctrine for it is how the registry iterates a model it does not define.
     *
     * A host that installed the bundle without resolving the interface has an
     * unusable per-area table; there are no areas to backfill, and saying so is
     * more useful than a fatal.
     *
     * @return iterable<AreaInterface>
     */
    private function areas(): iterable
    {
        $areaClass = $this->em->getClassMetadata(AreaModule::class)
            ->getAssociationMapping('area')
            ->targetEntity;

        if (AreaInterface::class === $areaClass || !class_exists($areaClass)) {
            return [];
        }

        $identifier = $this->em->getClassMetadata($areaClass)->getSingleIdentifierFieldName();

        /** @var iterable<AreaInterface> $areas */
        $areas = $this->em->getRepository($areaClass)->findBy([], [$identifier => 'ASC']);

        return $areas;
    }
}
