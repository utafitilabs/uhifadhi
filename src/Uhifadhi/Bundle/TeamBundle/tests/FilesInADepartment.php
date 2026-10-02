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

namespace Uhifadhi\Bundle\TeamBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;

/**
 * EVERYBODY BELONGS TO ONE DEPARTMENT (ruled 2 Oct 2026), so a test that does
 * not care which files its people in Operations — one organization-wide
 * department, found or created. A test about departments names its own.
 */
trait FilesInADepartment
{
    private ?Department $homeDepartment = null;

    protected function homeDepartment(EntityManagerInterface $em): Department
    {
        if (null !== $this->homeDepartment && $em->contains($this->homeDepartment)) {
            return $this->homeDepartment;
        }

        $found = $em->getRepository(Department::class)->findOneBy(['name' => 'Operations']);
        if (null === $found) {
            $found = new Department()->setName('Operations');
            $em->persist($found);
        }

        return $this->homeDepartment = $found;
    }
}
