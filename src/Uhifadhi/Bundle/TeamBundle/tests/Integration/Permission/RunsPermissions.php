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

use Uhifadhi\Bundle\TeamBundle\Entity\PermissionRun;
use Uhifadhi\Bundle\TeamBundle\Enum\PermissionRunTriggerEnum;
use Uhifadhi\Bundle\TeamBundle\Permissions\PermissionRunService;
use Uhifadhi\Bundle\TeamBundle\Repository\PermissionRunRepository;

/**
 * A RUN, MADE AS THE WORKER MAKES ONE, for the suites that read what it filed.
 */
trait RunsPermissions
{
    private function runNow(): PermissionRun
    {
        $service = $this->service(PermissionRunService::class);
        $run = $service->requestRun(PermissionRunTriggerEnum::Manual, null);
        $service->handle($run->getId());

        $runs = $this->service(PermissionRunRepository::class);

        return $runs->find($run->getId()) ?? throw new \LogicException('the run is gone');
    }

    private function positionId(string $name): int
    {
        return $this->positions[$name]->getId() ?? throw new \LogicException('not persisted');
    }

    private function areaKey(string $name): string
    {
        return 'area-'.($this->areas[$name]->getId() ?? throw new \LogicException('not persisted'));
    }
}
