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

namespace Uhifadhi\Bundle\AreaBundle\Api\State;

use Symfony\Component\HttpFoundation\Response;
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiContext;
use Uhifadhi\Bundle\AreaBundle\Api\DutyApiException;
use Uhifadhi\Bundle\AreaBundle\Api\DutyResponse;
use Uhifadhi\Bundle\AreaBundle\Repository\CheckInRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;

/**
 * `PATCH /areas/{areaUuid}/checkins/{clientRef}` — API-CONTRACT.md §13B.
 *
 * The check-out, the back-fill and the corrections: three things that
 * happen to a claim after it is made, and all three append.
 */
final class UpdateCheckInProcessor extends DutyProcessor
{
    public function __construct(
        private readonly DutyApiContext $api,
        private readonly CheckInRepository $checkIns,
        private readonly CheckInService $writes,
    ) {
    }

    protected function handle(array $uriVariables): Response
    {
        $area = $this->api->area(self::uri($uriVariables, 'areaUuid'));
        $this->api->requireRanger($area);
        $clientRef = self::uri($uriVariables, 'clientRef');

        $checkIn = $this->checkIns->findByRef($area, $clientRef)
            ?? throw DutyApiException::unknownCheckIn($clientRef);
        $this->api->requireWriter($checkIn);

        $this->writes->amend($checkIn, $this->api->body());

        return DutyResponse::checkIn($checkIn, duplicate: false);
    }
}
