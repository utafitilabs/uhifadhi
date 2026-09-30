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
 * THE RECORD A SUPER ADMIN IS ABOUT TO DELETE, as its owner describes it
 * (ruled 28 Sep, #48). The page is titled by it, the confirmation asks for
 * its reference, and the audit line keeps its title after the record is gone.
 */
final readonly class DeletionSubject
{
    /**
     * @param string $kind      the record's kind in the owner's words ("patrol", "station")
     * @param string $reference what the Super Admin types to confirm ("P-0142", a person's name)
     * @param string $title     what the audit line names once the record is gone
     * @param string $summary   the page's subline: what this record is, in one line
     * @param string $recordUrl where "Back" goes
     * @param string $afterUrl  where the page goes once the record is deleted (its register)
     * @param string $register  that register's name in the crumb ("team", "patrols")
     */
    public function __construct(
        public string $kind,
        public string $reference,
        public string $title,
        public string $summary,
        public string $recordUrl,
        public string $afterUrl,
        public string $register,
    ) {
        if ('' === trim($reference)) {
            throw new \InvalidArgumentException(\sprintf('A %s to delete needs a reference: it is what the Super Admin types to confirm.', $kind));
        }
    }
}
