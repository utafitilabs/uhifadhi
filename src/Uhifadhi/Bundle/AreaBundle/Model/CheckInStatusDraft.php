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

use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;

/**
 * ONE ROW OF AN AREA'S CHECK-IN STATUSES AS THE EDITOR SENT IT, in the order
 * it was sent. `new` is a status the area does not hold yet; every other row
 * names a stored status by its key. A kind the editor left unchosen, or sent
 * as a word the platform does not have, is null.
 */
final readonly class CheckInStatusDraft
{
    public function __construct(
        public string $key,
        public string $label,
        public ?CheckInStatusKind $kind,
        public bool $active,
        public bool $new,
    ) {
    }
}
