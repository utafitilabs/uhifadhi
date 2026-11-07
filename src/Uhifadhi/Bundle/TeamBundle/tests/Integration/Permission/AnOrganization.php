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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Integration\Permission;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\Fixtures\Area\HostArea;

/**
 * THE SEMANTICS' ORGANIZATION AS AN OBJECT, for the browser suites: their base
 * case has helpers of its own under the trait's names, so the trait is held
 * here rather than mixed in.
 */
final class AnOrganization
{
    use BuildsAnOrganization;

    public function __construct(EntityManagerInterface $em)
    {
        $this->buildOrganization($em);
    }

    public function personNamed(string $name): User
    {
        return $this->people[$name] ?? throw new \LogicException('nobody called '.$name);
    }

    public function positionNamed(string $name): Position
    {
        return $this->positions[$name] ?? throw new \LogicException('no position called '.$name);
    }

    public function areaNamed(string $name): HostArea
    {
        return $this->areas[$name] ?? throw new \LogicException('no area called '.$name);
    }
}
