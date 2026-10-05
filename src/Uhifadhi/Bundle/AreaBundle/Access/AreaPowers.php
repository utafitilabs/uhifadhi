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

namespace Uhifadhi\Bundle\AreaBundle\Access;

use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Security\CheckInWriteVoter;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * THE AREA'S ONE POWER OVER SOMEBODY ELSE'S RECORD: a check-in, which is
 * evidence of who was on duty where. It is asked of the colleague's latest
 * check-in on the installation.
 */
final readonly class AreaPowers implements PowerSourceInterface
{
    public function __construct(
        private CheckInRepository $checkIns,
    ) {
    }

    public function powers(): iterable
    {
        yield new Power('change-another-rangers-check-in', PowerGroup::OthersRecords, 'end or change another ranger’s check-in', [CheckInWriteVoter::WRITE], [PowerTarget::AColleague]);
    }

    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        $uuid = $target instanceof UserInterface ? $target->getUuidString() : null;

        return null === $uuid ? null : ($this->checkIns->findRecentForPerson($uuid, 1)[0] ?? null);
    }
}
