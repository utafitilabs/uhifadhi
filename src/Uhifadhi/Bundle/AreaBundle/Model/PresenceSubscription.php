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

namespace Uhifadhi\Bundle\AreaBundle\Model;

use Symfony\Component\HttpFoundation\Cookie;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveStream;

/**
 * A PAGE'S LEAVE TO WATCH THE LIVE MARKS MOVE — the two halves that must go
 * out together: what the plate carries to the browser, and the cookie the
 * browser hands the hub. One without the other is a plate that connects
 * and is refused, or a cookie nothing uses.
 */
final readonly class PresenceSubscription
{
    public function __construct(
        /** The hub and the topics, for the plate. */
        public LiveStream $stream,
        /** The subscriber authorization for those topics, for the response. */
        public Cookie $cookie,
    ) {
    }
}
