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

namespace Uhifadhi\Contracts\Deletion;

/**
 * A RECORD WHOSE DELETE TAKES OTHER RECORDS THAT OTHERS LINK TO BY ID
 * (ruled 28 Sep, #48: "an incident filed from a deleted patrol stays, losing
 * only its link"). Modules link across bundles by a uuid and a label rather
 * than a foreign key, so nothing in the database knows the link exists; a
 * record that implements this lists the ids that go with it, and whoever
 * holds a link to one of them keeps its record and drops the link.
 */
interface LinkedRecordsInterface
{
    /**
     * The ids of this record and of every record under it that goes with it.
     *
     * @return list<string>
     */
    public function linkedRecordUuids(): array;
}
