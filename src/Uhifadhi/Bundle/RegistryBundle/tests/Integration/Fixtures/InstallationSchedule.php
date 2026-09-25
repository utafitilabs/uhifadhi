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

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * THE INSTALLATION'S OWN `default` SCHEDULE, with one task of its own —
 * what the starter's `App\Schedule` becomes once an installation adds to it.
 */
final class InstallationSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->add(
            RecurringMessage::cron('30 4 * * 1', new PlainMessage()),
        );
    }
}
